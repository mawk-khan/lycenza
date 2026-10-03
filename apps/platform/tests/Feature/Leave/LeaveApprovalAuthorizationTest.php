<?php

namespace Tests\Feature\Leave;

use App\Domain\HR\Application\ReportingHierarchyService;
use App\Domain\Leave\Application\Exceptions\LeaveException;
use App\Domain\Leave\Application\LeavePolicyAssignmentService;
use App\Domain\Leave\Application\LeaveRequestReadService;
use App\Domain\Leave\Application\LeaveRequestService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Leave\Concerns\CreatesLeaveFixtures;
use Tests\TestCase;

/**
 * HRX.2 (ADR 0065 §6, §23.5-§23.7): manager approval is `hr.leave.approve`
 * AND the acting Employee being the requester's CURRENT manager, resolved
 * fresh. The administrative path is `hr.leave.manage`. Nobody approves or
 * rejects their own request -- refused by the service and by a database
 * CHECK whose Employees the database derives itself.
 */
class LeaveApprovalAuthorizationTest extends TestCase
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
            return 'db:'.substr($e->getMessage(), 0, 200);
        }
    }

    /** A School with a requester reporting to a manager, and a third staff member. */
    private function team(): array
    {
        $w = $this->leaveWorld();
        $this->workingWeek($w['school'], $w['admin']);
        $requester = $this->staffMember($w['school']);
        $manager = $this->staffMember($w['school'], ['hr.leave.approve']);
        $other = $this->staffMember($w['school'], ['hr.leave.approve']);
        $this->reportTo($requester['assignment'], $manager['assignment']);
        app(LeavePolicyAssignmentService::class)->assign($w['school'], $requester['employment']->id, $w['policy']->id, '2026-04-01', null, $w['admin']);
        $this->allocate($w, 24, null, $requester['employment']);

        return $w + compact('requester', 'manager', 'other');
    }

    #[Test]
    public function the_current_direct_manager_with_the_capability_decides_and_nobody_else_on_the_manager_path(): void
    {
        $t = $this->team();
        $requests = app(LeaveRequestService::class);
        $reads = app(LeaveRequestReadService::class);
        $request = $this->submitLeave($t, '2026-10-12', '2026-10-12', 'full', null, $t['requester']['employment']);

        // Capability without ownership: the private 404, in reads and in decisions.
        $this->assertSame([], $reads->managed($t['school'], $t['other']['user']));
        $this->assertSame('not_found', $this->code(fn () => $reads->managedShow($t['school'], $request->id, $t['other']['user'])));
        $this->assertSame('not_found', $this->code(fn () => $requests->approveAsManager($t['school'], $request->id, $t['other']['user'])));

        // Ownership without capability.
        $unprivileged = $this->staffMember($t['school']);
        $this->reportTo($t['requester']['assignment'], $unprivileged['assignment']);
        $this->assertSame('forbidden', $this->code(fn () => $requests->approveAsManager($t['school'], $request->id, $unprivileged['user'])));
        $this->reportTo($t['requester']['assignment'], $t['manager']['assignment']);

        // The current manager sees exactly the report's request and decides it.
        $this->assertSame([$request->id], array_column($reads->managed($t['school'], $t['manager']['user']), 'id'));
        $this->assertSame('approved', $requests->approveAsManager($t['school'], $request->id, $t['manager']['user'])->status);
        $this->assertSame('manager', $this->inSchool($t['school'], fn () => DB::table('leave_decisions')->where('leave_request_id', $request->id)->value('path')));
    }

    #[Test]
    public function a_reporting_change_after_submission_moves_the_authority_to_the_new_manager(): void
    {
        $t = $this->team();
        $requests = app(LeaveRequestService::class);
        $request = $this->submitLeave($t, '2026-10-12', '2026-10-12', 'full', null, $t['requester']['employment']);

        // HR's own reporting change: the old manager loses the request immediately.
        $hrAdmin = $this->createUserWithCapabilities($t['school'], ['hr.employees.assignments.manage']);
        app(ReportingHierarchyService::class)->setManager($this->inSchool($t['school'], fn () => $t['requester']['assignment']->fresh()), $t['other']['assignment'], $hrAdmin);

        $this->assertSame('not_found', $this->code(fn () => $requests->rejectAsManager($t['school'], $request->id, 'staffing_need', $t['manager']['user'])), 'the old manager');
        $this->assertSame('rejected', $requests->rejectAsManager($t['school'], $request->id, 'staffing_need', $t['other']['user'])->status, 'the new manager');

        // No manager at all: only the administrative path can decide.
        $orphan = $this->submitLeave($t, '2026-10-13', '2026-10-13', 'full', null, $t['requester']['employment']);
        app(ReportingHierarchyService::class)->setManager($this->inSchool($t['school'], fn () => $t['requester']['assignment']->fresh()), null, $hrAdmin);
        $this->assertSame('not_found', $this->code(fn () => $requests->approveAsManager($t['school'], $orphan->id, $t['other']['user'])));
        $this->assertSame('approved', $requests->approve($t['school'], $orphan->id, $t['admin'])->status);
        $this->assertSame('administrative', $this->inSchool($t['school'], fn () => DB::table('leave_decisions')->where('leave_request_id', $orphan->id)->value('path')));
    }

    #[Test]
    public function nobody_approves_or_rejects_their_own_request_on_any_path_and_the_database_enforces_it(): void
    {
        $t = $this->team();
        $requests = app(LeaveRequestService::class);

        // An administrator who is also an employee requests leave for themself.
        $self = $this->staffMember($t['school'], ['hr.leave.manage', 'hr.leave.view', 'hr.leave.approve']);
        app(LeavePolicyAssignmentService::class)->assign($t['school'], $self['employment']->id, $t['policy']->id, '2026-04-01', null, $t['admin']);
        $this->allocate($t, 24, null, $self['employment']);
        $own = $this->submitLeave($t, '2026-10-12', '2026-10-12', 'full', null, $self['employment']);

        $this->assertSame('LEAVE_SELF_DECISION', $this->code(fn () => $requests->approve($t['school'], $own->id, $self['user'])), 'the administrative path is no override');
        $this->assertSame('LEAVE_SELF_DECISION', $this->code(fn () => $requests->reject($t['school'], $own->id, 'other', $self['user'])));
        $this->assertSame('withdrawn', $requests->withdraw($t['school'], $own->id, 'plans_changed', $self['user'])->status, 'withdrawing one\'s own request is allowed');

        // Raw SQL with the runtime role: the database derives both Employees and refuses.
        $raw = $this->submitLeave($t, '2026-10-13', '2026-10-13', 'full', null, $self['employment']);
        $message = $this->code(fn () => $this->inSchool($t['school'], fn () => DB::insert(
            "insert into leave_decisions (id, school_id, leave_request_id, requester_employee_id, decision, path, decided_by_user_id, decider_employee_id) values (?, ?, ?, ?, 'approved', 'administrative', ?, null)",
            [(string) Str::uuid7(), $t['school']->id, $raw->id, $t['requester']['employment']->employee_id, $self['user']->id],
        )));
        $this->assertStringContainsString('leave_decisions_no_self_decision', $message, 'a forged NULL decider or wrong requester is overwritten by the trigger');
        $this->assertStringContainsString('leave_request_evidence_missing', $this->code(fn () => $this->inSchool($t['school'], fn () => DB::table('leave_requests')->where('id', $raw->id)->update(['status' => 'approved']))), 'no status change without its evidence');
    }

    #[Test]
    public function another_schools_requests_are_invisible_and_undecidable(): void
    {
        $a = $this->team();
        $b = $this->team();
        $request = $this->submitLeave($b, '2026-10-12', '2026-10-12', 'full', null, $b['requester']['employment']);

        $this->assertSame('not_found', $this->code(fn () => app(LeaveRequestService::class)->approve($a['school'], $request->id, $a['admin'])));
        $this->assertSame('not_found', $this->code(fn () => app(LeaveRequestReadService::class)->show($a['school'], $request->id, $a['admin'])));
        $this->assertSame('forbidden', $this->code(fn () => app(LeaveRequestService::class)->approve($b['school'], $request->id, $a['admin'])), "School A's authority is not School B's");
        $this->assertSame('not_found', $this->code(fn () => app(LeaveRequestService::class)->submitOnBehalf($a['school'], $b['requester']['employment']->id, $a['type']->id, '2026-10-14', 'full', '2026-10-14', 'full', null, $a['admin'])));
        $this->assertSame('not_found', $this->code(fn () => app(LeaveRequestService::class)->submitOnBehalf($a['school'], $a['employment']->id, $b['type']->id, '2026-10-14', 'full', '2026-10-14', 'full', null, $a['admin'])));
    }
}
