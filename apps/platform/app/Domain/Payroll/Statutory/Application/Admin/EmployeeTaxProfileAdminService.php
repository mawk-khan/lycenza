<?php

namespace App\Domain\Payroll\Statutory\Application\Admin;

use App\Domain\HR\Infrastructure\EmploymentRecord;
use App\Domain\Payroll\Statutory\Application\Exceptions\StatutoryEmploymentRecordNotFoundException;
use App\Domain\Payroll\Statutory\Infrastructure\EmployeeTaxProfile;
use App\Models\School;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Checkpoint 9.6I (Section 1 "Employee tax profile") -- administrative
 * read/write for `employee_tax_profile`: regime election, the
 * School-policy switch reference (explicitly SCHOOL POLICY, never
 * itself a statutory rule -- ADR 0036's own distinction), and the
 * declared-income/deduction inputs `TdsMonthlyDeductionService`/
 * `IncomeTaxSlabCalculator` already consume. Never exposes arbitrary
 * tax-code scripting -- only the fixed, typed field set the
 * calculation engine already understands.
 */
class EmployeeTaxProfileAdminService
{
    use AuthorizesCapability;

    public function __construct(
        private readonly TenantContext $context,
        private readonly AuditRecorder $audit,
    ) {}

    /**
     * @return Collection<int, EmployeeTaxProfile>
     */
    public function history(School $school, string $employmentRecordId, User $actor): Collection
    {
        return $this->context->withSchool($school, function () use ($school, $employmentRecordId, $actor) {
            $this->authorizeCapabilityFor($actor, 'payroll.statutory.view', $school);
            $this->assertEmploymentRecordExists($school, $employmentRecordId);

            return EmployeeTaxProfile::query()
                ->where('school_id', $school->id)
                ->where('employment_record_id', $employmentRecordId)
                ->orderByDesc('fiscal_year_start')
                ->get();
        });
    }

    /**
     * @param  array{regime: string, regimeSwitchPolicyReference: ?string, previousEmployerIncome: string, previousEmployerTds: string, declaredOtherIncome: string, declaredDeductions: string}  $data
     */
    public function configure(School $school, string $employmentRecordId, string $fiscalYearStart, array $data, User $actor): EmployeeTaxProfile
    {
        return $this->context->withSchool($school, function () use ($school, $employmentRecordId, $fiscalYearStart, $data, $actor) {
            $this->authorizeCapabilityFor($actor, 'payroll.statutory.manage', $school);
            $this->assertEmploymentRecordExists($school, $employmentRecordId);

            return DB::transaction(function () use ($school, $employmentRecordId, $fiscalYearStart, $data, $actor) {
                EmployeeTaxProfile::query()
                    ->where('school_id', $school->id)
                    ->where('employment_record_id', $employmentRecordId)
                    ->where('fiscal_year_start', $fiscalYearStart)
                    ->lockForUpdate()
                    ->first();

                $profile = EmployeeTaxProfile::query()->updateOrCreate(
                    ['school_id' => $school->id, 'employment_record_id' => $employmentRecordId, 'fiscal_year_start' => $fiscalYearStart],
                    [
                        'regime' => $data['regime'],
                        'regime_switch_policy_reference' => $data['regimeSwitchPolicyReference'],
                        'previous_employer_income' => $data['previousEmployerIncome'],
                        'previous_employer_tds' => $data['previousEmployerTds'],
                        'declared_other_income' => $data['declaredOtherIncome'],
                        'declared_deductions' => $data['declaredDeductions'],
                    ],
                );

                $this->audit->school($school, 'payroll.statutory.tax_profile.configured', actor: $actor, subject: $profile, metadata: [
                    'employmentRecordId' => $employmentRecordId,
                    'fiscalYearStart' => $fiscalYearStart,
                ]);

                return $profile;
            });
        });
    }

    private function assertEmploymentRecordExists(School $school, string $employmentRecordId): void
    {
        $exists = EmploymentRecord::query()->where('school_id', $school->id)->where('id', $employmentRecordId)->exists();

        if (! $exists) {
            throw new StatutoryEmploymentRecordNotFoundException($employmentRecordId);
        }
    }
}
