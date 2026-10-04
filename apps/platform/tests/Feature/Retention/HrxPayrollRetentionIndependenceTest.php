<?php

namespace Tests\Feature\Retention;

use App\Domain\Leave\Application\StaffCalendarService;
use App\Domain\Payroll\Application\PayrollHrxInputReadService;
use App\Domain\StaffAttendance\Application\StaffAttendanceService;
use App\Models\School;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Retention\Concerns\CreatesPayrollRetentionFixtures;
use Tests\TestCase;

/**
 * HRX.6 (ADR 0065 §27, §26.8): `payroll_run_hrx_inputs` is Payroll
 * evidence. It references no HRX row, so the raw HRX purge leaves it exactly
 * as frozen -- the run still shows what Payroll saw -- and it leaves only
 * with its payroll result, through Payroll's own D9 expiry (E21.3F). The
 * Employee root follows last.
 */
class HrxPayrollRetentionIndependenceTest extends TestCase
{
    use CreatesPayrollRetentionFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->enablePayrollRetention();
    }

    /** @return list<array<string, mixed>> the run's frozen HRX snapshots, every column */
    private function snapshots(School $school, string $runId): array
    {
        return $this->inSchool($school, fn () => DB::table('payroll_run_hrx_inputs')->where('payroll_run_id', $runId)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all());
    }

    #[Test]
    public function the_hrx_purge_leaves_payrolls_frozen_snapshot_and_payroll_retention_removes_it_with_its_result(): void
    {
        $school = $this->createSchool();
        $setup = $this->payrollSetup($school);
        $employee = $this->paidEmployee($school, $setup);
        $employmentId = (string) $this->inSchool($school, fn () => DB::table('employment_records')->where('employee_id', $employee->id)->value('id'));

        // August 2015: a working week and two recorded absent halves, captured by the run.
        $this->travelTo(Carbon::parse('2015-08-05 10:00:00', 'UTC'));
        $calendarAdmin = $this->createUserWithCapabilities($school, ['hr.leave.configure']);
        app(StaffCalendarService::class)->setWeeklyPattern($school, [1 => 'full', 2 => 'full', 3 => 'full', 4 => 'full', 5 => 'full', 6 => 'off', 7 => 'off'], $calendarAdmin);
        app(StaffAttendanceService::class)->record($school, $employmentId, '2015-08-03', 'absent', 'absent', $this->createUserWithCapabilities($school, ['hr.staff_attendance.manage']));
        $this->travelBack();
        $run = $this->postedRun($school, '2015-08-01', '2015-09-05');
        $this->separate($employee, '2015-12-31');

        $frozen = $this->snapshots($school, $run->id);
        $this->assertCount(1, $frozen);
        $this->assertSame(2, (int) $frozen[0]['recorded_absence_half_units']);
        $results = $this->rowsWhere($school, 'payroll_run_results', 'employee_id', $employee->id);

        $this->artisan('platform:employee-retention-prune', ['--only' => 'evidence'])
            ->expectsOutputToContain('staff attendance evidence of 1 Employee(s)')
            ->assertSuccessful();

        $this->assertSame(0, $this->rowsWhere($school, 'staff_attendance_records', 'employee_id', $employee->id), 'the raw HRX evidence expired');
        $this->assertSame($frozen, $this->snapshots($school, $run->id), 'the Payroll snapshot survives the raw HRX purge, unchanged');
        $this->assertSame($results, $this->rowsWhere($school, 'payroll_run_results', 'employee_id', $employee->id));
        $this->assertSame(1, $this->rowsWhere($school, 'employees', 'id', $employee->id), 'payroll evidence still keeps the Employee');
        $viewer = $this->createUserWithCapabilities($school, [PayrollHrxInputReadService::CAPABILITY]);
        $view = app(PayrollHrxInputReadService::class)->forRun($school, $run->id, $viewer);
        $this->assertSame(2, $view['inputs'][0]['units']['recordedAbsenceHalfUnits'], 'the run still shows what Payroll saw');
        // Truthfully: the raw source no longer matches the frozen snapshot (it expired); nothing is rewritten.
        $this->assertSame('source_changed_after_approval', $view['differenceState']);

        // Payroll's own D9 expiry removes the snapshot with its result; then the Employee goes.
        $this->artisan('platform:payroll-retention-prune')->assertSuccessful();
        $this->assertSame([], $this->snapshots($school, $run->id));
        $this->artisan('platform:employee-retention-prune', ['--only' => 'evidence'])->expectsOutputToContain('the employment evidence of 1 Employee(s)')->assertSuccessful();
        $this->assertSame(0, $this->rowsWhere($school, 'employees', 'id', $employee->id));
    }
}
