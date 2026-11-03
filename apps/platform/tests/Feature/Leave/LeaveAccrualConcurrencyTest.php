<?php

namespace Tests\Feature\Leave;

use App\Domain\Leave\Application\LeavePolicyAssignmentService;
use App\Domain\Leave\Application\LeavePolicyService;
use App\Domain\Leave\Infrastructure\LeavePolicy;
use App\Domain\Leave\Infrastructure\LeaveType;
use App\Domain\Leave\Infrastructure\LeaveYear;
use App\Models\School;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ForcesConcurrentOverlap;
use Tests\Concerns\PurgesCommittedHrxFixtures;
use Tests\Feature\Leave\Concerns\CreatesLeaveFixtures;
use Tests\TestCase;

/**
 * HR.10 (ADR 0065 §29.6): accrual racing the writers it could collide with,
 * in two real OS processes with the contender OBSERVED blocked on the
 * holder's lock (COMMITTED fixtures; the children run on the real clock, so
 * the months are in the past: leave year 2025-04-01..2026-03-31):
 * - two runs of one month: the second waits on the month lock, then credits
 *   nothing -- every account once;
 * - an employment ending inside the month: the run's FOR SHARE read waits,
 *   then the month is a part month -- never credited;
 * - a policy assignment ending inside the month: the run's FOR SHARE read of
 *   the assignment waits, then nothing is credited;
 * - a year close: the batch waits on the year lock, then refuses
 *   (LEAVE_YEAR_CLOSED) and the run is marked failed -- never posted into a
 *   closed year.
 * (Two debits racing an adjustment: LeaveConcurrencyTest; HR.10 adds no
 * expiry, so there is no expiry-versus-approval race to prove.)
 */
class LeaveAccrualConcurrencyTest extends TestCase
{
    use CreatesLeaveFixtures, ForcesConcurrentOverlap, PurgesCommittedHrxFixtures;

    /** @var array<int, string> */
    protected $connectionsToTransact = [];

    /** @var list<School> */
    private array $schools = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->snapshotDurableFixtures();
    }

    protected function tearDown(): void
    {
        $this->purgeCommittedHrxSchools($this->schools);
        try {
            $this->assertDurableFixturesRestored();
        } finally {
            parent::tearDown();
        }
    }

    private function script(string ...$args): array
    {
        return ['php', __DIR__.'/../../Support/leave-op.php', ...$args];
    }

    /** @return array{school: School, admin: User, type: LeaveType, year: LeaveYear, policy: LeavePolicy} */
    private function world(): array
    {
        $school = $this->createSchool();
        $this->schools[] = $school;
        $admin = $this->leaveAdmin($school);
        $type = $this->leaveType($school, $admin);
        $year = $this->leaveYear($school, $admin, '2025-06-01');
        $policy = app(LeavePolicyService::class)->create($school, $type->id, [
            'name' => 'Monthly', 'annual_allocation_units' => 0, 'carry_forward_allowed' => false, 'carry_forward_cap_units' => null,
            'carry_forward_expiry_days' => null, 'entitlement_method' => 'monthly', 'monthly_accrual_units' => 3, 'accrual_timing' => 'period_start',
        ], null, $admin);

        return compact('school', 'admin', 'type', 'year', 'policy');
    }

    private function assigned(array $w): string
    {
        $employment = $this->createEmploymentRecord($this->createEmployee($w['school']), ['status' => 'active', 'starts_on' => '2024-01-01', 'ends_on' => null]);
        app(LeavePolicyAssignmentService::class)->assign($w['school'], $employment->id, $w['policy']->id, '2025-04-01', null, $w['admin']);

        return $employment->id;
    }

    private function accruals(School $school): int
    {
        return DB::connection('pgsql_admin')->table('leave_ledger_entries')->where('school_id', $school->id)->where('kind', 'accrual')->count();
    }

    #[Test]
    public function two_concurrent_runs_of_a_month_credit_each_account_once(): void
    {
        $w = $this->world();
        $this->assigned($w);
        $this->assigned($w);
        $accrue = $this->script('accrue', $w['school']->id, $w['type']->id, $w['year']->id, '2025-09-01', $w['admin']->id);

        [$holder, $contender] = $this->raceWithHeldHolder($accrue, $accrue);

        $this->assertSame('ok:completed:2', $holder);
        $this->assertSame('ok:completed:0', $contender, 'the waiting run finds both months already credited');
        $this->assertSame(2, $this->accruals($w['school']));
    }

    #[Test]
    public function an_employment_ending_inside_the_month_is_never_credited_for_it(): void
    {
        $w = $this->world();
        $employment = $this->assigned($w);

        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->script('end-employment', $w['school']->id, $employment, '2025-09-15', $this->fullHrActor($w['school'])->id),
            $this->script('accrue', $w['school']->id, $w['type']->id, $w['year']->id, '2025-09-01', $w['admin']->id),
        );

        $this->assertSame('ok:separated', $holder);
        $this->assertSame('ok:completed:0', $contender);
        $this->assertSame(0, $this->accruals($w['school']));
    }

    #[Test]
    public function an_assignment_ending_inside_the_month_is_never_credited_for_it(): void
    {
        $w = $this->world();
        $employment = $this->assigned($w);
        $assignment = DB::connection('pgsql_admin')->table('leave_policy_assignments')->where('employment_record_id', $employment)->value('id');

        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->script('end-assignment', $w['school']->id, $assignment, '2025-09-10', $w['admin']->id),
            $this->script('accrue', $w['school']->id, $w['type']->id, $w['year']->id, '2025-09-01', $w['admin']->id),
        );

        $this->assertSame('ok:2025-09-10', $holder);
        $this->assertSame('ok:completed:0', $contender);
        $this->assertSame(0, $this->accruals($w['school']));
    }

    #[Test]
    public function a_year_closed_while_a_run_waits_is_never_posted_into(): void
    {
        $w = $this->world();
        $this->assigned($w);
        $this->leaveYear($w['school'], $w['admin'], '2026-06-01');

        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->script('close', $w['school']->id, $w['year']->id, $w['admin']->id),
            $this->script('accrue', $w['school']->id, $w['type']->id, $w['year']->id, '2026-03-01', $w['admin']->id),
        );

        $this->assertStringStartsWith('ok:', $holder);
        $this->assertSame('rejected:LEAVE_YEAR_CLOSED', $contender);
        $this->assertSame(0, $this->accruals($w['school']));
        $this->assertSame(['failed', 'LEAVE_YEAR_CLOSED'], array_values((array) DB::connection('pgsql_admin')->table('leave_accrual_runs')
            ->where('school_id', $w['school']->id)->select('status', 'failure_code')->first()));
    }
}
