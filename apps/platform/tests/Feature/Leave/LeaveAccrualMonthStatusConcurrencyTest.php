<?php

namespace Tests\Feature\Leave;

use App\Domain\Leave\Application\Accrual\LeaveAccrualMonthStatusReader as Status;
use App\Domain\Leave\Application\Accrual\LeaveAccrualService;
use App\Domain\Leave\Application\LeavePolicyAssignmentService;
use App\Domain\Leave\Application\LeavePolicyService;
use App\Models\School;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;
use Tests\Concerns\PurgesCommittedHrxFixtures;
use Tests\Feature\Leave\Concerns\CreatesLeaveFixtures;
use Tests\TestCase;

/**
 * HR.R1-C32 (WO-9): the month status against a REAL manual run held open in a second PostgreSQL session
 * (`leave-op.php accrue` inside Tests\Support\Concurrency\HeldTransaction), and the operator report. Committed
 * fixtures: the second session sees them, and this test's reads are what any other session reads.
 *
 * What it proves, truthfully: a run's uncommitted work is invisible to every other session, so a month being run
 * for the first time reads `missed` until that run commits; a run whose `processing` row is already committed
 * reads `running` while a batch is in flight; and the reader never waits on a running batch's locks.
 */
class LeaveAccrualMonthStatusConcurrencyTest extends TestCase
{
    use CreatesLeaveFixtures, PurgesCommittedHrxFixtures;

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

    /** @return array<string, mixed> */
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
        for ($i = 0; $i < 2; $i++) {
            $employment = $this->createEmploymentRecord($this->createEmployee($school), ['status' => 'active', 'starts_on' => '2024-01-01', 'ends_on' => null]);
            app(LeavePolicyAssignmentService::class)->assign($school, $employment->id, $policy->id, '2025-04-01', null, $admin);
        }

        return compact('school', 'admin', 'type', 'year');
    }

    /** @return array<string, mixed> */
    private function read(array $w, string $month): array
    {
        return app(Status::class)->month($w['school'], $w['type']->id, $w['year']->id, $month, $w['admin']);
    }

    /**
     * @param  callable(): void  $whileHeld
     */
    private function holdRun(array $w, string $month, callable $whileHeld): string
    {
        $dir = sys_get_temp_dir().'/wo9_'.bin2hex(random_bytes(6));
        mkdir($dir);
        $holder = new Process(['php', __DIR__.'/../../Support/leave-op.php', 'accrue', $w['school']->id, $w['type']->id, $w['year']->id, $month, $w['admin']->id], null, ['CONCURRENCY_HOLD_DIR' => $dir]);
        $holder->setTimeout(180);
        $holder->start();
        try {
            $deadline = microtime(true) + 90;
            while (! file_exists($dir.'/acted')) {
                if (! $holder->isRunning() || microtime(true) > $deadline) {
                    $this->fail("The run never reached its held commit.\n".$holder->getOutput().$holder->getErrorOutput());
                }
                usleep(2_000);
            }
            $whileHeld();
        } finally {
            touch($dir.'/release');
            $holder->wait();
        }

        return trim($holder->getOutput());
    }

    /** The reader must answer at once: never wait on the batch's row locks. */
    private function readWithoutWaiting(array $w, string $month): array
    {
        DB::statement("SET lock_timeout = '3s'");
        try {
            $started = microtime(true);
            $status = $this->read($w, $month);
            $this->assertLessThan(3.0, microtime(true) - $started, 'the reader waited on the held run');

            return $status;
        } finally {
            DB::statement('RESET lock_timeout');
        }
    }

    #[Test]
    public function a_first_run_held_open_is_invisible_until_it_commits(): void
    {
        $w = $this->world();

        $out = $this->holdRun($w, '2025-09-01', function () use ($w): void {
            // The run row, its items and both credits exist only in the other session's open transaction.
            $held = $this->readWithoutWaiting($w, '2025-09-01');
            $this->assertSame([Status::MISSED, null, 2], [$held['status'], $held['runId'], $held['counts']['credited']]);
        });

        $this->assertSame('ok:completed:2', $out);
        $this->assertSame(Status::COMPLETE, $this->read($w, '2025-09-01')['status']);
    }

    #[Test]
    public function a_committed_processing_run_reads_running_while_its_batch_is_held(): void
    {
        $w = $this->world();
        // A run opened and committed with no batch processed yet: what a timed-out web request leaves behind.
        $run = app(LeaveAccrualService::class)->execute($w['school'], $w['type']->id, $w['year']->id, '2025-10-01', $w['admin'], 0);
        $this->assertSame('processing', $run->status);

        $out = $this->holdRun($w, '2025-10-01', function () use ($w, $run): void {
            $held = $this->readWithoutWaiting($w, '2025-10-01');
            $this->assertSame([Status::RUNNING, $run->id, 'run_processing'], [$held['status'], $held['runId'], $held['reason']]);
        });

        $this->assertSame('ok:completed:2', $out);
        $this->assertSame(Status::COMPLETE, $this->read($w, '2025-10-01')['status']);
        $this->assertSame(2, DB::connection('pgsql_admin')->table('leave_ledger_entries')->where('school_id', $w['school']->id)->where('kind', 'accrual')->count(), 'credited once each');
    }

    /** The operator report: per School, no names, read-only, a suspended School included and marked. */
    #[Test]
    public function the_operator_report_reads_only_and_names_nobody(): void
    {
        $w = $this->world();
        $suspended = $this->world();
        DB::connection('pgsql_admin')->table('schools')->where('id', $suspended['school']->id)->update(['status' => 'suspended']);
        app(LeaveAccrualService::class)->execute($w['school'], $w['type']->id, $w['year']->id, '2025-09-01', $w['admin']);
        $count = fn (): array => array_map(fn (string $t) => DB::connection('pgsql_admin')->table($t)->whereIn('school_id', [$w['school']->id, $suspended['school']->id])->count(),
            ['leave_accrual_runs', 'leave_accrual_run_items', 'leave_ledger_entries', 'school_audit_events', 'employment_records']);
        $before = $count();

        // A day in the NEXT Leave Year: the fixture's year has ended but is not closed, so it is still reported (its last
        // month in arrears only became due the day the next year started; review MEDIUM-1).
        $this->travelTo('2026-05-10 06:00:00');
        Artisan::call('leave:accrual-status', ['--school' => [$w['school']->id, $suspended['school']->id]]);
        $output = Artisan::output();
        $this->travelBack();

        $this->assertSame($before, $count(), 'the report wrote nothing');
        $this->assertStringContainsString($w['school']->id, $output);
        $this->assertStringContainsString('suspended', $output);
        $this->assertMatchesRegularExpression('/2025-09-01\s*\|\s*complete\s*\|/', $output);
        $this->assertMatchesRegularExpression('/2025-08-01\s*\|\s*missed\s*\|/', $output);
        $names = DB::connection('pgsql_admin')->table('employees')->whereIn('school_id', [$w['school']->id, $suspended['school']->id])->pluck('full_name');
        foreach ($names as $name) {
            $this->assertStringNotContainsString((string) $name, $output, 'no employee is named');
        }
        $this->assertStringContainsString('Read-only: nothing was posted', $output);
    }

    #[Test]
    public function the_operator_report_refuses_a_malformed_school_id(): void
    {
        $this->assertSame(2, Artisan::call('leave:accrual-status', ['--school' => ['not-a-uuid']]));
        $this->assertStringContainsString('Not a School id: not-a-uuid', Artisan::output());
    }
}
