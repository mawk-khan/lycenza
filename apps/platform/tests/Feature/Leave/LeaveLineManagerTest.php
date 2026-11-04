<?php

namespace Tests\Feature\Leave;

use App\Domain\HR\Infrastructure\ReportingRelationship;
use App\Domain\Leave\Application\Exceptions\LeaveException;
use App\Domain\Leave\Application\LeavePolicyAssignmentService;
use App\Domain\Leave\Application\LeaveRequestReadService;
use App\Domain\Leave\Application\LeaveRequestService;
use App\Models\School;
use App\Models\User;
use App\Support\Authorization\CapabilityResolver;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Leave\Concerns\CreatesLeaveFixtures;
use Tests\TestCase;

/**
 * HR.11 (OD-HR-5 = Option 2, ADR 0065 §30): the `line_manager` add-on role
 * -- exactly `hr.leave.approve`, decided ONLY for the holder's current
 * HR.3A direct reports on the decision date, never their own request, never
 * through a Campus grant (OD-8.8), never a second stage, delegation or
 * escalation (ADR 0065 §6 unchanged). The inbox is server-resolved, paged
 * and filtered; the decision history is recorded, not recomputed.
 *
 * Today is Monday 2026-10-05.
 */
class LeaveLineManagerTest extends TestCase
{
    use CreatesLeaveFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-05 06:00:00');
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
        }
    }

    /** A User holding the REAL catalogue role(s), linked to an Employee with a current employment and primary assignment. */
    private function person(School $school, array $roles, ?string $campusId = null): array
    {
        $user = $this->createUser(['name' => 'Person '.count($roles).random_int(100, 999)]);
        $membership = $this->createMembership($user, $school);
        foreach ($roles as $role) {
            $this->assignSchoolRole($membership, $role, $campusId);
        }
        $employee = $this->createEmployee($school, ['user_id' => $user->id]);
        $employment = $this->createEmploymentRecord($employee, ['status' => 'active', 'starts_on' => '2024-01-01', 'ends_on' => null]);
        $assignment = $this->createEmployeeAssignment($employment, $this->createPosition($school), ['starts_on' => '2024-01-01', 'ends_on' => null, 'is_primary' => true]);
        app(CapabilityResolver::class)->forgetCache($user, $school);

        return compact('user', 'membership', 'employee', 'employment', 'assignment');
    }

    /** @return array<string, mixed> a team: requester reports to a line manager; the request is submitted on the requester's behalf */
    private function team(): array
    {
        $w = $this->leaveWorld();
        $this->workingWeek($w['school'], $w['admin']);
        $requester = $this->person($w['school'], ['staff_self_service']);
        $manager = $this->person($w['school'], ['teacher', 'line_manager']);
        $this->reportTo($requester['assignment'], $manager['assignment']);
        app(LeavePolicyAssignmentService::class)->assign($w['school'], $requester['employment']->id, $w['policy']->id, '2026-04-01', null, $w['admin']);
        $this->allocate($w, 24, null, $requester['employment']);
        $request = $this->submitLeave($w, '2026-10-12', '2026-10-12', 'full', null, $requester['employment']);

        return $w + compact('requester', 'manager', 'request');
    }

    #[Test]
    public function the_role_carries_exactly_the_decision_key_and_nothing_else(): void
    {
        $school = $this->createSchool();
        $only = $this->person($school, ['line_manager']);

        $this->assertSame(['hr.leave.approve'], app(CapabilityResolver::class)->schoolCapabilities($only['user'], $school));
        foreach (['hr.leave.view', 'hr.leave.manage', 'hr.employees.view', 'hr.employees.personal.view', 'payroll.runs.view'] as $absent) {
            $this->assertFalse(app(CapabilityResolver::class)->canInSchool($only['user'], $absent, $school), $absent);
        }
    }

    #[Test]
    public function a_line_manager_decides_their_current_reports_request_and_the_history_records_them(): void
    {
        $t = $this->team();
        $page = fn (User $u, string $query = '') => $this->actingAs($u)->withHeader('X-School-Id', $t['school']->id)->get("/app/leave/approvals{$query}");

        $page($t['manager']['user'])->assertOk()->assertInertia(fn (AssertableInertia $p) => $p->component('App/Leave/Approvals')
            ->where('pendingCount', 1)->has('requests', 1)->where('requests.0.id', $t['request']->id)->where('pagination.total', 1)
            ->has('employees.'.$t['requester']['employment']->id)
            ->where('employees.'.$t['requester']['employment']->id, fn ($label) => array_keys($label->toArray()) === ['employmentRecordId', 'employeeNumber', 'fullName', 'status']));
        $page($t['manager']['user'], '?status=decided')->assertInertia(fn (AssertableInertia $p) => $p->has('requests', 0)->where('pendingCount', 1));
        $page($t['manager']['user'], '?from=2026-10-13')->assertInertia(fn (AssertableInertia $p) => $p->has('requests', 0), 'filters only narrow');

        $this->actingAs($t['manager']['user'])->withHeader('X-School-Id', $t['school']->id)
            ->post("/app/leave/approvals/{$t['request']->id}/approve")->assertSessionHasNoErrors();
        $this->assertSame('approved', $this->inSchool($t['school'], fn () => $t['request']->refresh()->status));
        $this->assertSame(2, (int) $this->inSchool($t['school'], fn () => DB::table('leave_ledger_entries')->where('leave_request_id', $t['request']->id)->where('kind', 'consumption')->sum('units')), 'consumed once');

        $detail = fn () => $this->actingAs($t['manager']['user'])->withHeader('X-School-Id', $t['school']->id)->get("/app/leave/approvals/{$t['request']->id}");
        $detail()->assertOk()->assertInertia(fn (AssertableInertia $p) => $p->component('App/Leave/ApprovalShow')
            ->where('request.decisions.0.decision', 'approved')->where('request.decisions.0.path', 'manager')
            ->where('request.decisions.0.decidedBy', $t['manager']['user']->name)->missing('request.ledgerEntries'));

        // A later reporting change never rewrites the recorded decision (the admin view still names the manager).
        $other = $this->person($t['school'], ['teacher', 'line_manager']);
        $this->reportTo($t['requester']['assignment'], $other['assignment']);
        $show = app(LeaveRequestReadService::class)->show($t['school'], $t['request']->id, $t['admin']);
        $this->assertSame([$t['manager']['user']->name, 'manager'], [$show['decisions'][0]['decidedBy'], $show['decisions'][0]['path']]);
        $detail()->assertNotFound();

        // The API detail never carries the ledger rows either; the employee's own view never names the decider (§25.4).
        $this->assertArrayNotHasKey('ledgerEntries', app(LeaveRequestReadService::class)->managedShow($t['school'], $t['request']->id, $other['user']));
        $this->assertSame($t['manager']['user']->name, app(LeaveRequestReadService::class)->managedShow($t['school'], $t['request']->id, $other['user'])['decisions'][0]['decidedBy'], 'the new manager sees the recorded name');
    }

    #[Test]
    public function the_role_without_a_current_line_decides_nothing(): void
    {
        $t = $this->team();
        $requests = app(LeaveRequestService::class);
        $outsider = $this->person($t['school'], ['line_manager']);
        $this->assertSame('not_found', $this->code(fn () => $requests->approveAsManager($t['school'], $t['request']->id, $outsider['user'])), 'capability without the line: the private 404');
        $this->actingAs($outsider['user'])->withHeader('X-School-Id', $t['school']->id)->get('/app/leave/approvals')
            ->assertOk()->assertInertia(fn (AssertableInertia $p) => $p->has('requests', 0)->where('pendingCount', 0));
        $this->actingAs($outsider['user'])->withHeader('X-School-Id', $t['school']->id)->get("/app/leave/approvals/{$t['request']->id}")->assertNotFound();

        // The line without the role (no capability): refused before anything is read.
        $plain = $this->person($t['school'], ['teacher']);
        $this->reportTo($t['requester']['assignment'], $plain['assignment']);
        $this->assertSame('forbidden', $this->code(fn () => $requests->approveAsManager($t['school'], $t['request']->id, $plain['user'])));
        $this->actingAs($plain['user'])->withHeader('X-School-Id', $t['school']->id)->get('/app/leave/approvals')->assertForbidden();
    }

    #[Test]
    public function a_former_or_future_manager_and_a_separated_manager_cannot_decide(): void
    {
        $t = $this->team();
        $requests = app(LeaveRequestService::class);
        $line = fn () => $this->inSchool($t['school'], fn () => ReportingRelationship::query()->where('subordinate_employment_id', $t['requester']['employment']->id)->live()->sole());

        // Former: the line ended yesterday.
        $this->inSchool($t['school'], fn () => $line()->forceFill(['ends_on' => '2026-10-04'])->save());
        $this->assertSame('not_found', $this->code(fn () => $requests->approveAsManager($t['school'], $t['request']->id, $t['manager']['user'])));

        // Future: a line starting tomorrow is not yet authority.
        $this->setReportingLineFixture($t['requester']['assignment'], $t['manager']['assignment'], '2026-10-06');
        $this->assertSame('not_found', $this->code(fn () => $requests->approveAsManager($t['school'], $t['request']->id, $t['manager']['user'])));

        // Separated: the manager's own employment ended, so no acting Employee.
        $this->reportTo($t['requester']['assignment'], $t['manager']['assignment']);
        $this->closeReportingLinesFixture($t['school'], $t['manager']['employment']->id, '2026-10-04');
        $this->inSchool($t['school'], fn () => DB::table('employment_records')->where('id', $t['manager']['employment']->id)->update(['status' => 'separated', 'ends_on' => '2026-10-04']));
        $this->assertSame('not_found', $this->code(fn () => $requests->approveAsManager($t['school'], $t['request']->id, $t['manager']['user'])));
        $this->assertSame('submitted', $this->inSchool($t['school'], fn () => $t['request']->refresh()->status), 'the request stays pending; nobody is invented');
        $this->assertSame('approved', $requests->approve($t['school'], $t['request']->id, $t['admin'])->status, 'the administrative path remains');
    }

    #[Test]
    public function a_campus_grant_of_the_role_never_decides_leave_and_platform_authority_reaches_nothing(): void
    {
        $t = $this->team();
        $campus = $this->createCampus($t['school']);
        $campusManager = $this->person($t['school'], ['teacher', 'line_manager'], $campus->id);
        $this->reportTo($t['requester']['assignment'], $campusManager['assignment']);
        $this->assertSame('forbidden', $this->code(fn () => app(LeaveRequestService::class)->approveAsManager($t['school'], $t['request']->id, $campusManager['user'])),
            'OD-8.8: only a whole-School grant decides Leave');

        $root = $this->createPlatformRoot();
        $response = $this->actingAs($root)->withHeader('X-School-Id', $t['school']->id)->get('/app/leave/approvals');
        $this->assertContains($response->status(), [302, 403], 'platform authority is never School authority: refused, never the page');
        $this->assertNotSame(200, $response->status());
    }

    #[Test]
    public function the_manager_never_decides_their_own_request(): void
    {
        $t = $this->team();
        // The manager reports to themselves through nothing; their own request is simply not theirs to decide on any path.
        app(LeavePolicyAssignmentService::class)->assign($t['school'], $t['manager']['employment']->id, $t['policy']->id, '2026-04-01', null, $t['admin']);
        $this->allocate($t, 24, null, $t['manager']['employment']);
        $own = $this->submitLeave($t, '2026-10-13', '2026-10-13', 'full', null, $t['manager']['employment']);

        $this->assertSame('not_found', $this->code(fn () => app(LeaveRequestService::class)->approveAsManager($t['school'], $own->id, $t['manager']['user'])));
        $this->assertNotContains($own->id, array_column(app(LeaveRequestReadService::class)->managed($t['school'], $t['manager']['user']), 'id'));
    }

    #[Test]
    public function the_inbox_is_paged_on_the_server_and_counts_only_decidable_requests(): void
    {
        $t = $this->team();
        foreach (range(13, 30) as $day) {
            $date = sprintf('2026-10-%02d', $day);
            if (! in_array((int) date('N', strtotime($date)), [6, 7], true)) {
                $this->submitLeave($t, $date, $date, 'full', null, $t['requester']['employment']);
            }
        }
        $reads = app(LeaveRequestReadService::class);
        $first = $reads->managedPage($t['school'], $t['manager']['user'], [], 1, 5);
        $total = $first['total'];
        $this->assertGreaterThan(5, $total);
        $this->assertCount(5, $first['rows']);
        $ids = [];
        for ($page = 1; $page <= (int) ceil($total / 5); $page++) {
            array_push($ids, ...array_column($reads->managedPage($t['school'], $t['manager']['user'], [], $page, 5)['rows'], 'id'));
        }
        $this->assertSame($total, count(array_unique($ids)), 'no row skipped or repeated across pages');
        $this->assertSame($total, $first['pendingCount']);
    }
}
