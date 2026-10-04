<?php

namespace Tests\Feature\StaffSelfService\Concerns;

use App\Domain\Finance\Infrastructure\LedgerAccount;
use App\Domain\HR\Infrastructure\EmployeeAssignment;
use App\Domain\HR\Infrastructure\EmploymentRecord;
use App\Domain\Leave\Application\LeavePolicyAssignmentService;
use App\Domain\Payroll\Application\AddStructureComponentData;
use App\Domain\Payroll\Application\FixedComponentValueInput;
use App\Domain\Payroll\Application\PayrollAccountingAdministrationService;
use App\Domain\Payroll\Application\PayrollCompensationAdministrationService;
use App\Domain\Payroll\Application\PayrollPeriodAdministrationService;
use App\Domain\Payroll\Application\PayrollPostingAdministrationService;
use App\Domain\Payroll\Application\PayrollRunAdministrationService;
use App\Domain\Payroll\Application\PayrollStructureAdministrationService;
use App\Models\School;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\Feature\StaffAttendance\Concerns\CreatesStaffAttendanceFixtures;

/**
 * HRX.4: a Leave + Staff Attendance world (HRX.1-HRX.3 fixtures) plus staff
 * members who are Users linked to active Employees (ActingEmployee), and a
 * Payroll builder using the real Payroll services (structure, compensation,
 * period, run, calculate, approve, post) -- the Phase 9.10 payslip test's
 * path, never fabricated Payroll rows.
 */
trait CreatesSelfServiceFixtures
{
    use CreatesStaffAttendanceFixtures;

    public const SELF_CAPABILITIES = ['hr.leave.self', 'hr.staff_attendance.self', 'payroll.payslips.self'];

    /**
     * A staff member with the self capabilities, the world's leave policy assigned and 24 units allocated.
     *
     * @param  list<string>|null  $capabilities  null = all three self capabilities
     * @return array{user: User, employment: EmploymentRecord, assignment: EmployeeAssignment}
     */
    protected function selfMember(array $w, ?array $capabilities = null): array
    {
        $member = $this->staffMember($w['school'], $capabilities ?? self::SELF_CAPABILITIES);
        app(LeavePolicyAssignmentService::class)->assign($w['school'], $member['employment']->id, $w['policy']->id, '2026-04-01', null, $w['admin']);
        $this->allocate($w, 24, null, $member['employment']);

        return $member;
    }

    /**
     * Pays these employments for $month through the real Payroll services and leaves the run in $finalStatus
     * (`$compensate` false: a later month for employments already compensated).
     *
     * @param  list<EmploymentRecord>  $employments
     * @return string the run id
     */
    protected function payrollRun(School $school, array $employments, string $month = '2026-08-01', string $finalStatus = 'posted', bool $compensate = true): string
    {
        $structureManager = $this->createUserWithCapabilities($school, ['payroll.structures.manage', 'payroll.accounting.manage', 'payroll.compensation.sensitive.manage']);
        $runManager = $this->createUserWithCapabilities($school, ['payroll.runs.prepare', 'payroll.periods.manage']);
        $approver = $this->createUserWithCapabilities($school, ['payroll.runs.approve']);
        $poster = $this->createUserWithCapabilities($school, ['payroll.runs.post', 'payroll.runs.reverse']);

        return $this->inSchool($school, function () use ($school, $employments, $month, $finalStatus, $compensate, $structureManager, $runManager, $approver, $poster) {
            if (! LedgerAccount::query()->where('school_id', $school->id)->exists()) {
                $expense = LedgerAccount::factory()->for($school, 'school')->type('expense')->create();
                $payable = LedgerAccount::factory()->for($school, 'school')->type('liability')->create();
                app(PayrollAccountingAdministrationService::class)->configure($school, $expense->id, $payable->id, $structureManager);
            }
            if ($compensate) {
                $structures = app(PayrollStructureAdministrationService::class);
                $code = Str::upper(Str::random(6));
                $component = $structures->createComponent($school, "B{$code}", 'Basic', 'earning', null, $structureManager);
                $structure = $structures->createDraftStructure($school, "G{$code}", 'Grade', $structureManager);
                $line = $structures->addStructureComponent($structure, new AddStructureComponentData($component->id, 'fixed_amount', null, null, 1), $structureManager);
                $structure = $structures->activateStructure($structure, $structureManager);
                foreach ($employments as $i => $employment) {
                    app(PayrollCompensationAdministrationService::class)->assign($school, $employment, $structure, Carbon::parse('2024-01-01'),
                        [new FixedComponentValueInput($line->id, (string) (40000 + $i * 1000).'.00')], $structureManager);
                }
            }

            $periods = app(PayrollPeriodAdministrationService::class);
            $period = $periods->open($periods->createPeriod($school, Carbon::parse($month), null, $runManager), $runManager);
            $runs = app(PayrollRunAdministrationService::class);
            $run = $runs->createRun($period, $runManager);
            $runs->calculate($run, $runManager);
            if (in_array($finalStatus, ['approved', 'posted'], true)) {
                $runs->approve($run->refresh(), $approver);
            }
            if ($finalStatus === 'posted') {
                app(PayrollPostingAdministrationService::class)->post($run->refresh(), $poster);
            }

            return $run->id;
        });
    }
}
