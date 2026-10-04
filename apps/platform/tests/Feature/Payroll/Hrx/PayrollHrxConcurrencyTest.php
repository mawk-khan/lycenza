<?php

namespace Tests\Feature\Payroll\Hrx;

use App\Domain\Leave\Application\LeaveRequestService;
use App\Domain\Payroll\Application\PayrollHrxInputReadService;
use App\Domain\StaffAttendance\Application\Payroll\PayrollAbsenceEvidenceReader;
use App\Models\School;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;
use Tests\Concerns\ForcesConcurrentOverlap;
use Tests\Feature\Payroll\Hrx\Concerns\CreatesPayrollHrxFixtures;
use Tests\TestCase;

/**
 * HRX.5 (ADR 0065 §26.9): genuine two-process races between a payroll
 * calculation (which captures HRX evidence under the exclusive
 * `hrx.staff_employment` locks) and HRX writers (which take them shared).
 * The contender is observed BLOCKED before the holder commits, and the
 * captured fingerprint always equals the evidence wholly before or wholly
 * after the HRX write -- never a mix. Posting takes no HRX lock: a source
 * correction during posting completes independently, and the posted
 * snapshot stays as calculated.
 *
 * Child processes use the real clock; the period is September 2026.
 */
class PayrollHrxConcurrencyTest extends TestCase
{
    use CreatesPayrollHrxFixtures, ForcesConcurrentOverlap;

    /** @var array<int, string> */
    protected $connectionsToTransact = [];

    /** @var list<School> */
    private array $schools = [];

    protected function tearDown(): void
    {
        // Committed evidence is append-only and RESTRICT: it goes with its School through the migration role in replica mode (test database only).
        // The ad hoc capability roles go too -- a payroll capability left on a committed role would leak into global role checks.
        $admin = DB::connection('pgsql_admin');
        foreach ($this->schools as $school) {
            $users = $admin->table('school_memberships')->where('school_id', $school->id)->pluck('user_id')->all();
            $roles = $admin->table('membership_role_assignments as a')->join('roles as r', 'r.id', '=', 'a.role_id')
                ->where('a.school_id', $school->id)->where('r.key', 'like', 'test.capability_grant.%')->distinct()->pluck('r.id')->all();
            $admin->transaction(function () use ($admin, $school, $users, $roles) {
                $admin->statement("SET LOCAL session_replication_role = 'replica'");
                foreach ($admin->select("SELECT c.table_name FROM information_schema.columns c JOIN pg_class t ON t.relname = c.table_name AND t.relkind = 'r' AND t.relnamespace = 'public'::regnamespace WHERE c.table_schema = 'public' AND c.column_name = 'school_id'") as $row) {
                    $admin->table($row->table_name)->where('school_id', $school->id)->delete();
                }
                $admin->table('schools')->where('id', $school->id)->delete();
                $admin->table('role_capabilities')->whereIn('role_id', $roles)->delete();
                $admin->table('roles')->whereIn('id', $roles)->delete();
                $admin->table('users')->whereIn('id', $users)->delete();
            });
        }

        parent::tearDown();
    }

    private function payroll(string ...$args): array
    {
        return ['php', __DIR__.'/../../../Support/payroll-hrx-op.php', ...$args];
    }

    private function leave(string ...$args): array
    {
        return ['php', __DIR__.'/../../../Support/leave-op.php', ...$args];
    }

    private function attendance(string ...$args): array
    {
        return ['php', __DIR__.'/../../../Support/staff-attendance-op.php', ...$args];
    }

    private function world(): array
    {
        $w = $this->hrxWorld();
        $this->schools[] = $w['school'];
        $w['preparer'] = $this->createUserWithCapabilities($w['school'], ['payroll.runs.prepare']);
        $w['run'] = $this->payrollRun($w['school'], [$w['employment']], '2026-09-01', 'draft');

        return $w;
    }

    private function fingerprintNow(array $w): string
    {
        return app(PayrollAbsenceEvidenceReader::class)->read($w['school'], $w['employment']->id, '2026-09-01', '2026-09-30')->fingerprint;
    }

    private function calculate(array $w): array
    {
        return $this->payroll('calculate', $w['school']->id, $w['run'], $w['preparer']->id, $w['employment']->id);
    }

    #[Test]
    public function a_capture_racing_a_leave_approval_sees_it_wholly_before_or_wholly_after(): void
    {
        $w = $this->world();
        $before = $this->fingerprintNow($w);
        $first = $this->submitLeave($w, '2026-09-14', '2026-09-14', type: $w['unpaid'])->id;

        [$holder, $contender] = $this->raceWithHeldHolder($this->calculate($w), $this->leave('approve', $w['school']->id, $first, $w['admin']->id));
        $this->assertSame(["ok:{$before}", 'ok:approved'], [$holder, $contender], 'calculation first: the evidence predates the approval');

        $afterFirst = $this->fingerprintNow($w);
        $second = $this->submitLeave($w, '2026-09-15', '2026-09-15', type: $w['unpaid'])->id;
        [$holder, $contender] = $this->raceWithHeldHolder($this->leave('approve', $w['school']->id, $second, $w['admin']->id), $this->calculate($w));
        $this->assertSame('ok:approved', $holder);
        $this->assertSame('ok:'.$this->fingerprintNow($w), $contender, 'approval first: the capture waited and saw it');
        $this->assertNotSame($afterFirst, $this->fingerprintNow($w));
    }

    #[Test]
    public function a_capture_racing_a_leave_cancellation_sees_exactly_one_state(): void
    {
        $w = $this->world();
        $leave = $this->submitLeave($w, '2026-09-14', '2026-09-14', type: $w['unpaid']);
        app(LeaveRequestService::class)->approve($w['school'], $leave->id, $w['admin']);
        $withLeave = $this->fingerprintNow($w);

        [$holder, $contender] = $this->raceWithHeldHolder($this->leave('cancel', $w['school']->id, $leave->id, $w['admin']->id), $this->calculate($w));
        $this->assertSame('ok:cancelled', $holder);
        $this->assertSame('ok:'.$this->fingerprintNow($w), $contender, 'the capture waited for the cancellation');
        $this->assertNotSame($withLeave, $this->fingerprintNow($w));
    }

    #[Test]
    public function a_capture_racing_an_attendance_correction_sees_exactly_one_version(): void
    {
        $w = $this->world();
        $record = $this->recordAttendance($w, '2026-09-14', 'absent', 'absent');
        $absent = $this->fingerprintNow($w);

        [$holder, $contender] = $this->raceWithHeldHolder($this->calculate($w), $this->attendance('correct', $w['school']->id, $record->id, '1', 'present', 'present', $w['clerk']->id));
        $this->assertSame(["ok:{$absent}", 'ok:2'], [$holder, $contender], 'the correction waited for the capture, which saw version 1');
        $this->assertNotSame($absent, $this->fingerprintNow($w));
    }

    #[Test]
    public function two_simultaneous_captures_produce_one_snapshot_with_one_fingerprint(): void
    {
        $w = $this->world();
        $this->recordAttendance($w, '2026-09-14', 'absent', null);

        [$holder, $contender] = $this->raceWithHeldHolder($this->calculate($w), $this->calculate($w));
        $expected = 'ok:'.$this->fingerprintNow($w);
        $this->assertSame([$expected, $expected], [$holder, $contender]);
        $this->assertSame(1, DB::connection('pgsql_admin')->table('payroll_run_hrx_inputs')->where('payroll_run_id', $w['run'])->count());
    }

    #[Test]
    public function a_source_correction_during_posting_completes_independently_and_the_posted_snapshot_stays_as_calculated(): void
    {
        $w = $this->world();
        // An approved October run whose captured evidence includes an absence on 1 October.
        $octRecord = $this->recordAttendance($w, '2026-10-01', 'absent', 'absent');
        $runId = $this->payrollRun($w['school'], [], '2026-10-01', 'approved', compensate: false);
        $captured = DB::connection('pgsql_admin')->table('payroll_run_hrx_inputs')->where('payroll_run_id', $runId)->value('fingerprint');
        $poster = $this->createUserWithCapabilities($w['school'], ['payroll.runs.post']);

        $dir = sys_get_temp_dir().'/race_'.bin2hex(random_bytes(8));
        mkdir($dir);
        $holder = new Process($this->payroll('post', $w['school']->id, $runId, $poster->id), null, ['CONCURRENCY_HOLD_DIR' => $dir]);
        $holder->setTimeout(180);
        try {
            $holder->start();
            $deadline = microtime(true) + 90;
            while (! file_exists($dir.'/acted')) {
                $this->assertTrue($holder->isRunning() && microtime(true) < $deadline, 'the posting never reached its held write: '.$holder->getErrorOutput());
                usleep(2_000);
            }
            // While the posting is held uncommitted, the correction runs to completion: posting takes no HRX lock.
            $contender = new Process($this->attendance('correct', $w['school']->id, $octRecord->id, '1', 'present', 'present', $w['clerk']->id));
            $contender->setTimeout(120);
            $contender->run();
            $this->assertSame('ok:2', trim($contender->getOutput()), 'the correction did not wait for the posting');
        } finally {
            touch($dir.'/release');
            $holder->wait();
            @unlink($dir.'/acted');
            @unlink($dir.'/release');
            @rmdir($dir);
        }

        $this->assertSame('ok:posted', trim($holder->getOutput()));
        $this->assertSame($captured, DB::connection('pgsql_admin')->table('payroll_run_hrx_inputs')->where('payroll_run_id', $runId)->value('fingerprint'), 'the posted snapshot is the calculated one');
        $view = app(PayrollHrxInputReadService::class)->forRun($w['school'], $runId, $this->createUserWithCapabilities($w['school'], ['payroll.runs.prepare']));
        $this->assertSame(['source_changed_after_approval', 'posted'], [$view['differenceState'], $view['runStatus']]);
    }
}
