<?php

namespace Tests\Feature\Leave;

use App\Domain\HR\Application\ReportingHierarchyService;
use App\Domain\Leave\Application\Exceptions\LeaveException;
use App\Domain\Leave\Application\LeaveLedgerService;
use App\Domain\Leave\Application\LeavePolicyAssignmentService;
use App\Domain\Leave\Application\LeaveRequestService;
use App\Domain\Leave\Infrastructure\LeavePolicyAssignment;
use App\Models\SchoolAuditEvent;
use App\Models\SchoolMembership;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Leave\Concerns\CreatesLeaveFixtures;
use Tests\TestCase;

/**
 * ORG.8D (ADR 0076 §62): HR Leave decisions on the shared approval
 * foundation, and G-4.
 *
 * - Whoever SUBMITTED a request (an administrator on the Employee's behalf,
 *   or the Employee) never approves or rejects it, on either path (service +
 *   `leave_decisions_participant_guard`); the requester Employee never
 *   decides it either (unchanged).
 * - The decider's authority is read fresh at decision time (a revocation
 *   or a disabled Leave module the 60 s caches have not seen still refuses).
 * - G-4: the administrative path never writes the actor's OWN leave
 *   (allocation, adjustment, policy assignment, submit, withdraw, cancel);
 *   self-service stays theirs.
 * - OD-8.8 is NOT decided here: the repository's current rule is pinned --
 *   the manager path is the whole-School `hr.leave.approve` grant AND the
 *   current reporting line, with no Campus logic. A whole-School manager
 *   decides a report on another Campus; a Campus-only grant (e.g.
 *   principal@Campus) gives no manager authority, even to the report's
 *   reporting manager (fail closed).
 */
class LeaveApprovalAssuranceTest extends TestCase
{
    use CreatesLeaveFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-05 10:00:00');
    }

    private function code(callable $call): string
    {
        try {
            DB::transaction($call);

            return 'ok';
        } catch (LeaveException $e) {
            return $e->errorCode();
        } catch (ModelNotFoundException) {
            return 'not_found';
        } catch (AuthorizationException) {
            return 'forbidden';
        } catch (QueryException $e) {
            return 'db:'.substr($e->getMessage(), 0, 300);
        }
    }

    /** A requester reporting to a manager (whole-School hr.leave.approve), with balance. */
    private function team(): array
    {
        $w = $this->leaveWorld();
        $this->workingWeek($w['school'], $w['admin']);
        $requester = $this->staffMember($w['school']);
        $manager = $this->staffMember($w['school'], ['hr.leave.approve']);
        $this->reportTo($requester['assignment'], $manager['assignment']);
        app(LeavePolicyAssignmentService::class)->assign($w['school'], $requester['employment']->id, $w['policy']->id, '2026-04-01', null, $w['admin']);
        $this->allocate($w, 24, null, $requester['employment']);

        return $w + compact('requester', 'manager');
    }

    private function requests(): LeaveRequestService
    {
        return app(LeaveRequestService::class);
    }

    #[Test]
    public function whoever_submitted_a_request_never_decides_it_on_either_path(): void
    {
        $t = $this->team();
        $clerk = $this->leaveClerk($t['school']);
        $request = $this->submitLeave($t, '2026-10-12', '2026-10-12', 'full', null, $t['requester']['employment']);

        $this->assertSame('LEAVE_PARTICIPANT_DECISION', $this->code(fn () => $this->requests()->approve($t['school'], $request->id, $clerk)));
        $this->assertSame('LEAVE_PARTICIPANT_DECISION', $this->code(fn () => $this->requests()->reject($t['school'], $request->id, 'other', $clerk)));

        // A manager who also holds hr.leave.manage and entered the request for their report cannot approve it as manager.
        $both = $this->staffMember($t['school'], ['hr.leave.approve', 'hr.leave.manage', 'hr.leave.view']);
        $this->reportTo($t['requester']['assignment'], $both['assignment']);
        $entered = $this->requests()->submitOnBehalf($t['school'], $t['requester']['employment']->id, $t['type']->id, '2026-10-13', 'full', '2026-10-13', 'full', null, $both['user']);
        $this->assertSame('LEAVE_PARTICIPANT_DECISION', $this->code(fn () => $this->requests()->approveAsManager($t['school'], $entered->id, $both['user'])));
        $this->assertSame('LEAVE_PARTICIPANT_DECISION', $this->code(fn () => $this->requests()->rejectAsManager($t['school'], $entered->id, 'other', $both['user'])));

        // Not decisions: the submitter may withdraw what they entered.
        $this->assertSame('withdrawn', $this->requests()->withdraw($t['school'], $entered->id, 'entered_in_error', $both['user'])->status);

        // Someone else decides.
        $this->assertSame('approved', $this->requests()->approve($t['school'], $request->id, $t['admin'])->status);
    }

    #[Test]
    public function the_database_refuses_a_decision_recorded_for_the_submitter(): void
    {
        $t = $this->team();
        $request = $this->submitLeave($t, '2026-10-12', '2026-10-12', 'full', null, $t['requester']['employment']);
        $insert = fn (string $decision, ?string $reason) => $this->code(fn () => $this->inSchool($t['school'], fn () => DB::insert(
            'insert into leave_decisions (id, school_id, leave_request_id, requester_employee_id, decision, path, decided_by_user_id, decider_employee_id, reason_code) values (?, ?, ?, ?, ?, ?, ?, null, ?)',
            [(string) Str::uuid7(), $t['school']->id, $request->id, $t['requester']['employment']->employee_id, $decision, 'administrative', $this->leaveClerk($t['school'])->id, $reason],
        )));

        $this->assertSame('school_os_app', DB::selectOne('select current_user as u')->u, 'proven on the real runtime role');
        $this->assertStringContainsString('leave_decisions_participant_guard', $insert('approved', null));
        $this->assertStringContainsString('leave_decisions_participant_guard', $insert('rejected', 'other'));
        $this->assertSame('ok', $insert('withdrawn', 'entered_in_error'), 'withdrawal is not a decision');

        $fn = DB::selectOne("select p.prosecdef, coalesce(array_to_string(p.proconfig, ','), '') as config, has_function_privilege('public', p.oid, 'EXECUTE') as public_exec from pg_proc p where p.proname = 'leave_decisions_refuse_submitter'");
        $this->assertFalse($fn->prosecdef);
        $this->assertStringContainsString('search_path=pg_catalog, public, pg_temp', $fn->config);
        $this->assertFalse($fn->public_exec);
    }

    #[Test]
    public function every_public_decision_goes_through_the_one_decision_core(): void
    {
        // The registry names decide(); this pins that approve and reject cannot skip it (security review LOW).
        $class = new \ReflectionClass(LeaveRequestService::class);
        $lines = file((string) $class->getFileName());
        foreach ($class->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->getDeclaringClass()->getName() !== LeaveRequestService::class || $method->isStatic() || $method->isConstructor()) {
                continue;
            }
            $body = implode('', array_slice($lines, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1));
            if (preg_match("/'approved'|'rejected'/", $body) === 1) {
                $this->assertStringContainsString('$this->decide(', $body, "{$method->getName()} decides through decide()");
            }
        }
        $this->assertSame(1, substr_count(implode('', $lines), 'LeaveDecision::query()->create('), 'only recordDecision() writes a decision');
        $this->assertSame('O', DB::selectOne("select tgenabled from pg_trigger where tgname = 'trg_leave_decisions_participant_guard'")->tgenabled, 'the submitter backstop is installed');
    }

    #[Test]
    public function authority_is_read_fresh_at_decision_time(): void
    {
        $t = $this->team();
        $request = $this->submitLeave($t, '2026-10-12', '2026-10-12', 'full', null, $t['requester']['employment']);
        $this->assertTrue(Gate::forUser($t['manager']['user'])->allows('capability', ['hr.leave.approve', $t['school']]), 'cache warmed');

        $this->inSchool($t['school'], fn () => DB::table('membership_role_assignments')
            ->whereIn('school_membership_id', DB::table('school_memberships')->where('user_id', $t['manager']['user']->id)->pluck('id'))
            ->update(['revoked_at' => now(), 'revocation_reason' => 'revoked']));
        $this->assertTrue(Gate::forUser($t['manager']['user'])->allows('capability', ['hr.leave.approve', $t['school']]), 'the cached floor still says yes');
        $this->assertSame('forbidden', $this->code(fn () => $this->requests()->approveAsManager($t['school'], $request->id, $t['manager']['user'])));

        // The Leave module disabled behind the cache refuses the administrative path too.
        $this->assertTrue(Gate::forUser($t['admin'])->allows('capability', ['hr.leave.manage', $t['school']]));
        $this->inSchool($t['school'], fn () => DB::table('school_module_entitlements')->where('school_id', $t['school']->id)->where('module_key', 'leave')->update(['enabled' => false]));
        $this->assertSame('forbidden', $this->code(fn () => $this->requests()->reject($t['school'], $request->id, 'other', $t['admin'])));
        $this->assertSame('submitted', $this->inSchool($t['school'], fn () => $request->fresh()->status));
    }

    #[Test]
    public function decisions_carry_approval_provenance_and_nothing_sensitive(): void
    {
        $t = $this->team();
        $approved = $this->submitLeave($t, '2026-10-12', '2026-10-12', 'full', null, $t['requester']['employment']);
        $this->requests()->approveAsManager($t['school'], $approved->id, $t['manager']['user']);
        $rejected = $this->submitLeave($t, '2026-10-13', '2026-10-13', 'full', null, $t['requester']['employment']);
        $this->requests()->reject($t['school'], $rejected->id, 'staffing_need', $t['admin']);

        $audit = fn (string $event) => $this->inSchool($t['school'], fn () => SchoolAuditEvent::query()->where('event_type', $event)->latest('id')->firstOrFail()->metadata);
        $approval = $audit('leave.request.approved');
        $this->assertSame(['leave_requests', 'approved', ['submitter' => [$this->leaveClerk($t['school'])->id]], $t['manager']['user']->id, 'reporting_line', false],
            [$approval['approvalWorkflow'], $approval['approvalDecision'], $approval['participants'], $approval['decidedByUserId'], $approval['decisionScope'], $approval['freshMfa']]);
        $rejection = $audit('leave.request.rejected');
        $this->assertSame(['rejected', 'school', $t['admin']->id], [$rejection['approvalDecision'], $rejection['decisionScope'], $rejection['decidedByUserId']]);
        $this->assertArrayNotHasKey('employeeName', $approval);
    }

    #[Test]
    public function the_administrative_path_never_writes_ones_own_leave_and_self_service_stays_theirs(): void
    {
        $t = $this->team();
        $self = $this->staffMember($t['school'], ['hr.leave.manage', 'hr.leave.view', 'hr.leave.self']);
        $mine = $self['employment']->id;
        $ledger = app(LeaveLedgerService::class);
        $policies = app(LeavePolicyAssignmentService::class);

        $this->assertSame('LEAVE_SELF_ADMINISTRATION', $this->code(fn () => $policies->assign($t['school'], $mine, $t['policy']->id, '2026-04-01', null, $self['user'])));
        $policies->assign($t['school'], $mine, $t['policy']->id, '2026-04-01', null, $t['admin']);
        $this->assertSame('LEAVE_SELF_ADMINISTRATION', $this->code(fn () => $ledger->allocate($t['school'], $mine, $t['type']->id, $t['year']->id, 24, $self['user'])));
        $ledger->allocate($t['school'], $mine, $t['type']->id, $t['year']->id, 12, $t['admin']);
        $this->assertSame('LEAVE_SELF_ADMINISTRATION', $this->code(fn () => $ledger->adjust($t['school'], $mine, $t['type']->id, $t['year']->id, 'credit', 10, 'allocation_correction', $self['user'])));
        $assignment = $this->inSchool($t['school'], fn () => LeavePolicyAssignment::query()->where('employment_record_id', $mine)->sole());
        $this->assertSame('LEAVE_SELF_ADMINISTRATION', $this->code(fn () => $policies->end($t['school'], $assignment->id, '2026-12-31', $self['user'])));
        $this->assertSame('LEAVE_SELF_ADMINISTRATION', $this->code(fn () => $this->requests()->submitOnBehalf($t['school'], $mine, $t['type']->id, '2026-10-12', 'full', '2026-10-12', 'full', null, $self['user'])));

        // Self-service is the path for one's own leave.
        $own = $this->requests()->submitOwn($t['school'], $t['type']->id, '2026-10-12', 'full', '2026-10-12', 'full', null, $self['user']);
        $this->assertSame('LEAVE_SELF_ADMINISTRATION', $this->code(fn () => $this->requests()->withdraw($t['school'], $own->id, 'plans_changed', $self['user'])));
        $this->assertSame('withdrawn', $this->requests()->withdrawOwn($t['school'], $own->id, 'plans_changed', $self['user'])->status);

        $approved = $this->requests()->submitOwn($t['school'], $t['type']->id, '2026-10-14', 'full', '2026-10-14', 'full', null, $self['user']);
        $this->requests()->approve($t['school'], $approved->id, $t['admin']);
        $this->assertSame('LEAVE_SELF_ADMINISTRATION', $this->code(fn () => $this->requests()->cancel($t['school'], $approved->id, 'plans_changed', $self['user'])), 'no administrative cancel of one\'s own (started or not)');
        $this->assertSame('cancelled', $this->requests()->cancelOwn($t['school'], $approved->id, 'plans_changed', $self['user'])->status, 'self-service, before it starts');

        // Another administrator writes the same balance freely.
        $this->assertSame('ok', $this->code(fn () => $ledger->adjust($t['school'], $mine, $t['type']->id, $t['year']->id, 'credit', 2, 'allocation_correction', $t['admin'])));
    }

    #[Test]
    public function od_8_8_is_not_decided_the_current_whole_school_and_relationship_rule_is_pinned(): void
    {
        $t = $this->team();
        $campusA = $this->createCampus($t['school'], ['code' => 'CA']);
        $campusB = $this->createCampus($t['school'], ['code' => 'CB']);
        $this->inSchool($t['school'], fn () => DB::table('employee_assignments')->where('id', $t['requester']['assignment']->id)->update(['campus_id' => $campusA->id]));
        $this->inSchool($t['school'], fn () => DB::table('employee_assignments')->where('id', $t['manager']['assignment']->id)->update(['campus_id' => $campusB->id]));

        // A whole-School hr.leave.approve holder who is the current reporting manager decides a report on another Campus.
        $request = $this->submitLeave($t, '2026-10-12', '2026-10-12', 'full', null, $t['requester']['employment']);
        $this->assertSame('approved', $this->requests()->approveAsManager($t['school'], $request->id, $t['manager']['user'])->status);

        // principal@Campus A (a Campus-scoped grant only) who IS the reporting manager gets no manager authority: fail closed.
        $principal = $this->createUserWithCapabilities($t['school'], []);
        $membership = $this->inSchool($t['school'], fn () => SchoolMembership::query()->where('user_id', $principal->id)->where('school_id', $t['school']->id)->sole());
        $this->assignSchoolRole($membership, 'principal', $campusA->id);
        $employee = $this->createEmployee($t['school'], ['user_id' => $principal->id]);
        $employment = $this->createEmploymentRecord($employee, ['status' => 'active', 'starts_on' => '2024-01-01', 'ends_on' => null]);
        $assignment = $this->createEmployeeAssignment($employment, $this->createPosition($t['school']), ['starts_on' => '2024-01-01', 'ends_on' => null, 'is_primary' => true, 'campus_id' => $campusA->id]);
        $this->reportTo($t['requester']['assignment'], $assignment);

        $pending = $this->submitLeave($t, '2026-10-13', '2026-10-13', 'full', null, $t['requester']['employment']);
        $this->assertFalse(Gate::forUser($principal)->allows('capability', ['hr.leave.approve', $t['school']]), 'a Campus grant never satisfies the whole-School check');
        $this->assertSame('forbidden', $this->code(fn () => $this->requests()->approveAsManager($t['school'], $pending->id, $principal)));
        $this->assertSame('forbidden', $this->code(fn () => $this->requests()->approve($t['school'], $pending->id, $principal)), 'nor the administrative path');
        $this->assertSame('submitted', $this->inSchool($t['school'], fn () => $pending->fresh()->status));
    }

    #[Test]
    public function a_new_assignment_elsewhere_does_not_move_authority_while_the_primary_line_stands(): void
    {
        $t = $this->team();
        $campusB = $this->createCampus($t['school'], ['code' => 'CB']);
        // A second, non-primary assignment on another Campus (no manager): the primary assignment's line still decides.
        $this->createEmployeeAssignment($t['requester']['employment'], $this->createPosition($t['school']), ['starts_on' => '2026-01-01', 'ends_on' => null, 'is_primary' => false, 'campus_id' => $campusB->id]);
        $request = $this->submitLeave($t, '2026-10-12', '2026-10-12', 'full', null, $t['requester']['employment']);

        $this->assertSame('approved', $this->requests()->approveAsManager($t['school'], $request->id, $t['manager']['user'])->status);

        // HR moving the line is the only thing that moves authority (ReportingHierarchyService, audited).
        $other = $this->staffMember($t['school'], ['hr.leave.approve']);
        $hrAdmin = $this->createUserWithCapabilities($t['school'], ['hr.employees.assignments.manage']);
        app(ReportingHierarchyService::class)->setManager($this->inSchool($t['school'], fn () => $t['requester']['assignment']->fresh()), $other['assignment'], $hrAdmin);
        $next = $this->submitLeave($t, '2026-10-13', '2026-10-13', 'full', null, $t['requester']['employment']);
        $this->assertSame('not_found', $this->code(fn () => $this->requests()->approveAsManager($t['school'], $next->id, $t['manager']['user'])), 'the former manager');
        $this->assertSame('approved', $this->requests()->approveAsManager($t['school'], $next->id, $other['user'])->status);
    }
}
