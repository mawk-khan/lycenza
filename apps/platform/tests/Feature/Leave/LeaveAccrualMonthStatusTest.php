<?php

namespace Tests\Feature\Leave;

use App\Domain\HR\Application\EmploymentRoster;
use App\Domain\HR\Application\EmploymentService;
use App\Domain\HR\Infrastructure\EmploymentRecord;
use App\Domain\Leave\Application\Accrual\LeaveAccrualMonthStatusReader as Status;
use App\Domain\Leave\Application\Accrual\LeaveAccrualService;
use App\Domain\Leave\Application\LeavePolicyAssignmentService;
use App\Domain\Leave\Application\LeavePolicyService;
use App\Domain\Leave\Infrastructure\LeavePolicy;
use App\Domain\Leave\Infrastructure\LeaveType;
use App\Domain\Leave\Infrastructure\LeaveYear;
use App\Models\School;
use App\Models\User;
use App\Support\Idempotency\CommittedEffectDetector;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Feature\Leave\Concerns\CreatesLeaveFixtures;
use Tests\TestCase;

/**
 * HR.R1-C32 (WO-9, D-3 option 3, HR owner 2026-11-12; LVA-1, TRR-2): a monthly accrual's status, read from the runs
 * and the canonical evaluation, never written. The leave year is 2026-04-01..2027-03-31 and today is Monday
 * 2026-10-05 in Asia/Kolkata. Every number is a synthetic test input.
 */
class LeaveAccrualMonthStatusTest extends TestCase
{
    use CreatesLeaveFixtures;

    private School $school;

    private User $admin;

    private LeaveType $type;

    private LeaveYear $year;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-05 06:00:00');
        $this->school = $this->createSchool();
        $this->admin = $this->leaveAdmin($this->school);
        $this->type = $this->leaveType($this->school, $this->admin);
        $this->year = $this->leaveYear($this->school, $this->admin);
    }

    private function monthly(string $timing = 'period_start'): LeavePolicy
    {
        return app(LeavePolicyService::class)->create($this->school, $this->type->id, [
            'name' => 'Monthly '.$timing, 'annual_allocation_units' => 0, 'carry_forward_allowed' => false, 'carry_forward_cap_units' => null,
            'carry_forward_expiry_days' => null, 'entitlement_method' => 'monthly', 'monthly_accrual_units' => 3, 'accrual_timing' => $timing,
        ], null, $this->admin, []);
    }

    private function employment(string $startsOn = '2024-01-01'): EmploymentRecord
    {
        return $this->createEmploymentRecord($this->createEmployee($this->school), ['status' => 'active', 'starts_on' => $startsOn, 'staff_type' => 'teaching']);
    }

    private function assign(EmploymentRecord $e, LeavePolicy $p, string $from = '2026-04-01'): void
    {
        app(LeavePolicyAssignmentService::class)->assign($this->school, $e->id, $p->id, $from, null, $this->admin);
    }

    private function accrue(string $month, int $maxBatches = 1000): void
    {
        app(LeaveAccrualService::class)->execute($this->school, $this->type->id, $this->year->id, $month, $this->admin, $maxBatches);
    }

    /** @return array<string, mixed> */
    private function monthStatus(string $month, ?User $actor = null): array
    {
        return app(Status::class)->month($this->school, $this->type->id, $this->year->id, $month, $actor ?? $this->admin);
    }

    private function credits(EmploymentRecord $e): int
    {
        return (int) $this->inSchool($this->school, fn () => DB::table('leave_ledger_entries')->where('employment_record_id', $e->id)->where('kind', 'accrual')->count());
    }

    private function processingRun(string $month): string
    {
        $id = (string) Str::uuid();
        $this->inSchool($this->school, fn () => DB::table('leave_accrual_runs')->insert([
            'id' => $id, 'school_id' => $this->school->id, 'leave_type_id' => $this->type->id, 'leave_year_id' => $this->year->id,
            'period_start' => $month, 'status' => 'processing', 'started_by_user_id' => $this->admin->id, 'started_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]));

        return $id;
    }

    #[Test]
    public function a_due_month_never_run_is_missed_and_completing_it_makes_it_complete(): void
    {
        $e = $this->employment();
        $this->assign($e, $this->monthly());

        $missed = $this->monthStatus('2026-09-01');
        $this->assertSame([Status::MISSED, 'never_run', 1], [$missed['status'], $missed['reason'], $missed['counts']['credited']]);

        $this->accrue('2026-09-01');
        $complete = $this->monthStatus('2026-09-01');
        $this->assertSame([Status::COMPLETE, 'run_completed'], [$complete['status'], $complete['reason']]);
        $this->assertNotNull($complete['runId']);
        $this->assertSame(1, $this->credits($e));
    }

    /** C31's mandatory case: a completed month that someone was added to afterwards is incomplete, never complete. */
    #[Test]
    public function a_completed_month_with_an_employment_added_after_the_run_is_incomplete_until_run_again(): void
    {
        $policy = $this->monthly();
        $first = $this->employment();
        $this->assign($first, $policy);
        $this->accrue('2026-08-01');
        $this->assertSame(Status::COMPLETE, $this->monthStatus('2026-08-01')['status']);

        // Recorded later, effective from before the month (a backdated hire): the ledger of the first is untouched.
        $late = $this->employment('2025-06-01');
        $this->assign($late, $policy);
        $incomplete = $this->monthStatus('2026-08-01');
        $this->assertSame([Status::INCOMPLETE, 'would_still_credit', 1], [$incomplete['status'], $incomplete['reason'], $incomplete['counts']['credited']]);
        $this->assertSame([1, 0], [$this->credits($first), $this->credits($late)], 'nothing is posted by reading');

        // The existing manual run is the remedy, and never credits twice.
        $this->accrue('2026-08-01');
        $this->assertSame(Status::COMPLETE, $this->monthStatus('2026-08-01')['status']);
        $this->assertSame([1, 1], [$this->credits($first), $this->credits($late)]);
    }

    #[Test]
    public function a_month_not_started_or_not_yet_postable_is_not_due(): void
    {
        $this->assign($this->employment(), $this->monthly('period_end'));

        $this->assertSame([Status::NOT_DUE, 'month_not_started'], [$this->monthStatus('2026-11-01')['status'], $this->monthStatus('2026-11-01')['reason']]);
        // October in arrears is postable from 2026-11-01: today it is not due.
        $october = $this->monthStatus('2026-10-01');
        $this->assertSame([Status::NOT_DUE, 'version_not_yet_postable'], [$october['status'], $october['reason']]);
        $this->assertSame(Status::MISSED, $this->monthStatus('2026-09-01')['status'], 'September in arrears is due');
    }

    /** The School-local midnight, not UTC's, decides whether a month has started. */
    #[Test]
    public function the_school_local_day_decides_the_boundary(): void
    {
        $this->assign($this->employment(), $this->monthly());

        $this->travelTo('2026-10-31 18:29:59'); // 23:59:59 IST, still October
        $this->assertSame(Status::NOT_DUE, $this->monthStatus('2026-11-01')['status']);
        $this->travelTo('2026-10-31 18:30:00'); // 00:00 IST, 1 November: an advance month is due
        $this->assertSame(Status::MISSED, $this->monthStatus('2026-11-01')['status']);
    }

    /** Arrears: October becomes postable on 1 November, School-local. */
    #[Test]
    public function the_school_local_day_decides_an_arrears_month_too(): void
    {
        $this->assign($this->employment(), $this->monthly('period_end'));

        $this->travelTo('2026-10-31 18:29:59');
        $this->assertSame([Status::NOT_DUE, 'version_not_yet_postable'], [$this->monthStatus('2026-10-01')['status'], $this->monthStatus('2026-10-01')['reason']]);
        $this->travelTo('2026-10-31 18:30:00');
        $this->assertSame(Status::MISSED, $this->monthStatus('2026-10-01')['status']);
    }

    /** The calculator's every non-credit answer is nothing to credit, never missed: a part month, a separation, an annual policy. */
    #[Test]
    public function the_calculators_non_credit_outcomes_are_nothing_to_credit(): void
    {
        $joiner = $this->employment('2026-09-10');
        $this->assign($joiner, $this->monthly(), '2026-09-10');
        // A leaver: assigned while still employed, separated inside September.
        $this->travelTo('2026-09-05 06:00:00');
        $leaver = $this->createEmploymentRecord($this->createEmployee($this->school), ['status' => 'active', 'starts_on' => '2024-01-01', 'staff_type' => 'teaching']);
        $this->assign($leaver, $this->monthly());
        app(EmploymentService::class)->end($this->inSchool($this->school, fn () => EmploymentRecord::query()->findOrFail($leaver->id)), '2026-09-20', $this->fullHrActor($this->school));
        $this->travelTo('2026-10-05 06:00:00');
        $annual = app(LeavePolicyService::class)->create($this->school, $this->type->id, [
            'name' => 'Annual', 'annual_allocation_units' => 24, 'carry_forward_allowed' => false, 'carry_forward_cap_units' => null,
            'carry_forward_expiry_days' => null, 'entitlement_method' => 'annual', 'monthly_accrual_units' => null, 'accrual_timing' => null,
        ], null, $this->admin, []);
        $this->assign($this->employment(), $annual);

        $status = $this->monthStatus('2026-09-01');
        $this->assertSame(Status::NOTHING_TO_CREDIT, $status['status']);
        $this->assertSame([2, 1, 0], [$status['counts']['partial_period'], $status['counts']['not_monthly_policy'], $status['counts']['credited']]);
    }

    /** The status stays the evidence's; the workspace also says why a run would now be refused. */
    #[Test]
    public function a_month_that_can_no_longer_be_run_says_why(): void
    {
        $this->assign($this->employment(), $this->monthly());
        $this->assertNull($this->monthStatus('2026-09-01')['blockedBy']);
        $this->inSchool($this->school, fn () => DB::table('leave_types')->where('id', $this->type->id)->update(['status' => 'inactive']));
        $this->assertSame([Status::MISSED, 'type_inactive'], [$this->monthStatus('2026-09-01')['status'], $this->monthStatus('2026-09-01')['blockedBy']]);
    }

    #[Test]
    public function nothing_to_credit_is_told_apart_from_missed(): void
    {
        $this->employment(); // no policy assigned
        $nothing = $this->monthStatus('2026-09-01');
        $this->assertSame([Status::NOTHING_TO_CREDIT, 'no_credit_due', 1], [$nothing['status'], $nothing['reason'], $nothing['counts']['no_policy']]);
    }

    #[Test]
    public function an_open_run_shows_as_running_or_failed_before_anything_else(): void
    {
        $this->assign($this->employment(), $this->monthly());
        $id = $this->processingRun('2026-07-01');
        $running = $this->monthStatus('2026-07-01');
        $this->assertSame([Status::RUNNING, $id], [$running['status'], $running['runId']]);

        $this->inSchool($this->school, fn () => DB::table('leave_accrual_runs')->where('id', $id)->update(['status' => 'failed', 'failure_code' => 'LEAVE_ACCRUAL_BATCH_FAILED', 'updated_at' => now()]));
        $failed = $this->monthStatus('2026-07-01');
        $this->assertSame([Status::FAILED, 'LEAVE_ACCRUAL_BATCH_FAILED'], [$failed['status'], $failed['failureCode']]);

        // Resuming it through the existing workflow completes the month.
        $this->accrue('2026-07-01');
        $this->assertSame(Status::COMPLETE, $this->monthStatus('2026-07-01')['status']);
    }

    #[Test]
    public function an_evaluation_that_fails_is_unknown_never_complete_or_nothing_to_credit(): void
    {
        $this->assign($this->employment(), $this->monthly());
        $this->accrue('2026-09-01');
        $this->app->bind(EmploymentRoster::class, fn () => new class extends EmploymentRoster
        {
            public function __construct() {}

            public function pageOverlapping(School $school, string $from, string $to, ?string $after, int $perPage): array
            {
                throw new RuntimeException('an input could not be read');
            }
        });
        $this->app->forgetInstance(Status::class);

        $unknown = $this->monthStatus('2026-09-01');
        $this->assertSame([Status::UNKNOWN, 'evaluation_failed', null], [$unknown['status'], $unknown['reason'], $unknown['counts']]);
    }

    /** @return array<string, array{0: ?string, 1: bool, 2: ?array<string, int>, 3: string}> */
    public static function precedence(): array
    {
        $c = fn (array $n) => $n + ['credited' => 0, 'already_credited' => 0, 'not_due' => 0];

        return [
            'running beats everything' => ['processing', true, $c(['credited' => 2]), Status::RUNNING],
            'failed beats a completed run' => ['failed', true, $c([]), Status::FAILED],
            'failed beats an unreadable evaluation' => ['failed', false, null, Status::FAILED],
            'unreadable is unknown, even after a completed run' => [null, true, null, Status::UNKNOWN],
            'would credit after a completed run' => [null, true, $c(['credited' => 1, 'already_credited' => 5]), Status::INCOMPLETE],
            'would credit with no completed run' => [null, false, $c(['credited' => 1]), Status::MISSED],
            'would credit beats not yet postable' => [null, false, $c(['credited' => 1, 'not_due' => 3]), Status::MISSED],
            'some not yet postable, nothing to credit now' => [null, true, $c(['already_credited' => 2, 'not_due' => 1]), Status::NOT_DUE],
            'completed and nothing left' => [null, true, $c(['already_credited' => 4]), Status::COMPLETE],
            'credits with no completed run (contradiction)' => [null, false, $c(['already_credited' => 1]), Status::UNKNOWN],
            'nothing at all' => [null, false, $c(['no_policy' => 3]), Status::NOTHING_TO_CREDIT],
        ];
    }

    /** @param  array<string, int>|null  $counts */
    #[DataProvider('precedence')]
    #[Test]
    public function the_precedence_is_fixed(?string $open, bool $completed, ?array $counts, string $expected): void
    {
        $this->assertSame($expected, Status::classify($open, $completed, $counts)['status']);
    }

    #[Test]
    public function a_viewer_reads_it_and_others_are_refused(): void
    {
        $this->assign($this->employment(), $this->monthly());
        $viewer = $this->createUserWithCapabilities($this->school, ['hr.leave.view']);
        $this->assertSame(Status::MISSED, $this->monthStatus('2026-09-01', $viewer)['status']);

        try {
            $this->monthStatus('2026-09-01', $this->createUserWithCapabilities($this->school, ['hr.employees.view']));
            $this->fail('a user without hr.leave.view read the status');
        } catch (AuthorizationException) {
        }

        $other = $this->createSchool();
        $otherAdmin = $this->leaveAdmin($other);
        try {
            app(Status::class)->month($other, $this->type->id, $this->year->id, '2026-09-01', $otherAdmin);
            $this->fail('another School read this School\'s leave type');
        } catch (ModelNotFoundException) {
        }

        $this->expectException(\InvalidArgumentException::class);
        $this->monthStatus('2027-05-01');
    }

    /** Reading writes nothing: no statement it runs is a write, and every Leave and HR table is unchanged. */
    #[Test]
    public function reading_the_status_writes_nothing(): void
    {
        $policy = $this->monthly();
        $this->assign($this->employment(), $policy);
        $this->accrue('2026-08-01');
        $this->assign($this->employment('2025-01-01'), $policy);
        $tables = ['leave_accrual_runs', 'leave_accrual_run_items', 'leave_ledger_entries', 'leave_policy_assignments', 'employment_records', 'school_audit_events'];
        $before = $this->inSchool($this->school, fn () => array_map(fn (string $t) => DB::table($t)->where('school_id', $this->school->id)->count(), $tables));

        $writes = [];
        $detector = app(CommittedEffectDetector::class);
        DB::listen(function ($query) use (&$writes, $detector): void {
            if ($detector->isWrite($query->sql)) {
                $writes[] = $query->sql;
            }
        });
        $statuses = app(Status::class)->year($this->school, $this->type->id, $this->year->id, $this->admin);

        $this->assertSame([], $writes);
        // isWrite() strips row locks on purpose; this proof also refuses any lock the reader might take on a run.
        $locks = [];
        DB::listen(function ($query) use (&$locks): void {
            if (preg_match('/\bfor\s+(no\s+key\s+)?update\b/i', $query->sql) === 1) {
                $locks[] = $query->sql;
            }
        });
        app(Status::class)->year($this->school, $this->type->id, $this->year->id, $this->admin);
        $this->assertSame([], $locks, 'the reader takes no exclusive row lock');
        $this->assertSame($before, $this->inSchool($this->school, fn () => array_map(fn (string $t) => DB::table($t)->where('school_id', $this->school->id)->count(), $tables)));
        $this->assertCount(12, $statuses);
        $this->assertSame(Status::INCOMPLETE, collect($statuses)->firstWhere('periodStart', '2026-08-01')['status']);
        $this->assertSame(array_fill(0, 12, true), array_map(fn (array $s) => in_array($s['status'], Status::STATUSES, true), $statuses));
        // Repeatable: the same evidence gives the same answer.
        $this->assertSame(array_column($statuses, 'status'), array_column(app(Status::class)->year($this->school, $this->type->id, $this->year->id, $this->admin), 'status'));
    }

    #[Test]
    public function the_workspace_shows_the_month_and_every_month_on_request(): void
    {
        $this->assign($this->employment(), $this->monthly());
        $viewer = $this->createUserWithCapabilities($this->school, ['hr.leave.view']);
        $this->actingAs($viewer)->withSession(['active_school_id' => $this->school->id]);

        $query = http_build_query(['leave_type_id' => $this->type->id, 'leave_year_id' => $this->year->id, 'period_start' => '2026-09-01', 'check_months' => 1]);
        $this->get("/app/leave/accruals?{$query}")->assertOk()->assertInertia(fn ($page) => $page
            ->where('monthStatus.status', Status::MISSED)
            ->where('monthStatus.counts.credited', 1)
            ->has('monthStatuses', 12)
            ->where('monthStatuses.5.status', Status::MISSED)
            ->where('monthStatuses.7.status', Status::NOT_DUE)
            ->where('preview', null));

        $this->get('/app/leave/accruals?'.http_build_query(['leave_type_id' => $this->type->id, 'leave_year_id' => $this->year->id, 'period_start' => '2027-05-01']))
            ->assertOk()->assertInertia(fn ($page) => $page->where('error', 'Choose a month of the selected Leave Year.')->where('monthStatus', null));

        // Another School's leave type through this School's session: nothing of it is read.
        $other = $this->createSchool();
        $otherType = $this->leaveType($other, $this->leaveAdmin($other), 'OT');
        $this->get('/app/leave/accruals?'.http_build_query(['leave_type_id' => $otherType->id, 'leave_year_id' => $this->year->id, 'period_start' => '2026-09-01', 'check_months' => 1]))
            ->assertOk()->assertInertia(fn ($page) => $page->where('monthStatus', null)->where('monthStatuses', null)->whereNot('error', null));

        // Without hr.leave.view the workspace is refused.
        $outsider = $this->createUserWithCapabilities($this->school, ['hr.employees.view']);
        app('auth')->forgetGuards();
        $this->actingAs($outsider)->withSession(['active_school_id' => $this->school->id])
            ->get("/app/leave/accruals?{$query}")->assertForbidden();
    }
}
