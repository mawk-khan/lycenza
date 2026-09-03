<?php

namespace App\Domain\Payroll\Statutory\Application;

use App\Domain\HR\Infrastructure\EmploymentRecord;
use App\Domain\Payroll\Infrastructure\PayrollRun;
use App\Domain\Payroll\Infrastructure\PayrollRunResult;
use App\Domain\Payroll\Infrastructure\PayrollRunResultLine;
use App\Domain\Payroll\Statutory\Application\Exceptions\StatutoryCalculationAlreadyPerformedException;
use App\Domain\Payroll\Statutory\Application\Exceptions\StatutoryEmployeeFactsMissingException;
use App\Domain\Payroll\Statutory\Application\Exceptions\StatutoryRuleVersionNotFoundException;
use App\Domain\Payroll\Statutory\Application\Exceptions\StatutoryRunNotEligibleForCalculationException;
use App\Domain\Payroll\Statutory\Application\Exceptions\StatutorySalaryComponentNotClassifiedException;
use App\Domain\Payroll\Statutory\Calculation\EsiContributionCalculationService;
use App\Domain\Payroll\Statutory\Calculation\EsiContributionInput;
use App\Domain\Payroll\Statutory\Calculation\EsiCoverageDeterminationService;
use App\Domain\Payroll\Statutory\Calculation\EsiRuleVersion;
use App\Domain\Payroll\Statutory\Calculation\IncomeTaxRuleVersion;
use App\Domain\Payroll\Statutory\Calculation\IncomeTaxSlabCalculator;
use App\Domain\Payroll\Statutory\Calculation\LabourWelfareFundRuleVersion;
use App\Domain\Payroll\Statutory\Calculation\LwfCalculationService;
use App\Domain\Payroll\Statutory\Calculation\PfCalculationInput;
use App\Domain\Payroll\Statutory\Calculation\PfCalculationService;
use App\Domain\Payroll\Statutory\Calculation\PfComponentClassification;
use App\Domain\Payroll\Statutory\Calculation\PfEmployeeStatutoryFacts;
use App\Domain\Payroll\Statutory\Calculation\PfRuleVersion;
use App\Domain\Payroll\Statutory\Calculation\ProfessionalTaxCalculationService;
use App\Domain\Payroll\Statutory\Calculation\ProfessionalTaxRuleVersion;
use App\Domain\Payroll\Statutory\Calculation\TdsMonthlyDeductionInput;
use App\Domain\Payroll\Statutory\Calculation\TdsMonthlyDeductionService;
use App\Domain\Payroll\Statutory\Infrastructure\EmployeeEsiCoverage;
use App\Domain\Payroll\Statutory\Infrastructure\EmployeePfStatus;
use App\Domain\Payroll\Statutory\Infrastructure\EmployeeTaxProfile;
use App\Domain\Payroll\Statutory\Infrastructure\PayrollEsiRuleVersion;
use App\Domain\Payroll\Statutory\Infrastructure\PayrollIncomeTaxRuleVersion;
use App\Domain\Payroll\Statutory\Infrastructure\PayrollLwfAnnualCharge;
use App\Domain\Payroll\Statutory\Infrastructure\PayrollLwfRuleVersion;
use App\Domain\Payroll\Statutory\Infrastructure\PayrollPfRuleVersion;
use App\Domain\Payroll\Statutory\Infrastructure\PayrollProfessionalTaxRuleVersion;
use App\Domain\Payroll\Statutory\Infrastructure\PayrollSalaryComponentStatutoryClassification;
use App\Domain\Payroll\Statutory\Infrastructure\PayrollStatutoryCalculationResult;
use App\Models\School;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Money\Money;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Checkpoint 9.6F (ADR 0035 correction addendum) -- the Application-
 * layer orchestration that resolves real EmploymentRecord/rule-version
 * data and feeds it into the pure Checkpoint 9.6D/9.6E calculation
 * engines, persisting one immutable `PayrollStatutoryCalculationResult`
 * row per `PayrollRunResult`. Never itself performs a statutory
 * calculation -- every actual number comes from `PfCalculationService`/
 * `EsiContributionCalculationService`/`ProfessionalTaxCalculationService`/
 * `LwfCalculationService`/`TdsMonthlyDeductionService`
 * (Checkpoint 9.6D/9.6E), this class only builds their typed inputs.
 *
 * Must run while the parent `payroll_runs` row is `draft` or
 * `calculated` -- `trg_payroll_statutory_results_freeze` rejects any
 * write once the run reaches `approved`/`posted`, mirroring
 * `payroll_run_results`' own freeze boundary exactly.
 *
 * Two deliberate, DISCLOSED scope limitations (never invented legal
 * behavior -- documented gaps, the same posture as ADR 0035's ESI
 * disability deferral):
 *   - `income_tax_treatment = 'partially_exempt'` components are
 *     treated as FULLY taxable (the conservative, never-under-
 *     withholding choice) pending a documented partial-exemption
 *     computation (e.g. HRA exemption formula) -- computing that
 *     formula is out of Checkpoint 9.6F's scope and is not invented
 *     here.
 *   - LWF category eligibility currently resolves to `true` for every
 *     EmploymentRecord -- a School-configurable employee-category
 *     exclusion model (ADR 0035 correction addendum §1.7's "respecting
 *     legal employee-definition exclusions") is not yet built; no
 *     exclusion is fabricated in its absence.
 */
class StatutoryPayrollCalculationService
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly AuditRecorder $audit,
        private readonly PfCalculationService $pf,
        private readonly EsiCoverageDeterminationService $esiCoverage,
        private readonly EsiContributionCalculationService $esiContribution,
        private readonly ProfessionalTaxCalculationService $professionalTax,
        private readonly LwfCalculationService $lwf,
        private readonly IncomeTaxSlabCalculator $incomeTax,
        private readonly TdsMonthlyDeductionService $tds,
    ) {}

    /**
     * The `PayrollRun` row lock below serializes two concurrent calls
     * for the SAME run (the second blocks until the first commits or
     * rolls back) -- but `calculateForRun()` never mutates
     * `payroll_runs.status`, so the second caller, once unblocked,
     * still observes `draft`/`calculated` and would otherwise attempt
     * a SECOND set of `payroll_statutory_calculation_results` inserts.
     * `payroll_run_result_id`'s UNIQUE constraint (Checkpoint 9.6C) is
     * the real guarantee against that -- caught here and translated to
     * `StatutoryCalculationAlreadyPerformedException`, never a raw
     * database exception (Checkpoint 9.6H concurrency proof).
     */
    public function calculateForRun(PayrollRun $run, User $actor): int
    {
        $school = $run->school;

        try {
            return $this->context->withSchool($school, function () use ($school, $run, $actor) {
                return DB::transaction(function () use ($school, $run, $actor) {
                    $locked = PayrollRun::query()->where('id', $run->id)->lockForUpdate()->firstOrFail();
                    if (! in_array($locked->status, ['draft', 'calculated'], true)) {
                        throw new StatutoryRunNotEligibleForCalculationException($run->id, $locked->status);
                    }

                    $period = $locked->period;
                    $asOf = Carbon::parse($period->period_month)->startOfMonth();

                    $classifications = PayrollSalaryComponentStatutoryClassification::query()
                        ->where('school_id', $school->id)
                        ->where('effective_from', '<=', $asOf)
                        ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>', $asOf))
                        ->get()
                        ->keyBy('salary_component_id');

                    $results = PayrollRunResult::query()->where('payroll_run_id', $run->id)->get();
                    $count = 0;

                    foreach ($results as $result) {
                        $this->calculateForResult($school, $run, $result, $asOf, $classifications, $actor);
                        $count++;
                    }

                    $this->audit->school($school, 'payroll.statutory_calculation.completed', actor: $actor, subject: $run, metadata: ['resultCount' => $count]);

                    return $count;
                });
            });
        } catch (UniqueConstraintViolationException) {
            throw new StatutoryCalculationAlreadyPerformedException($run->id);
        }
    }

    /**
     * @param  Collection<string, PayrollSalaryComponentStatutoryClassification>  $classifications
     */
    private function calculateForResult(School $school, PayrollRun $run, PayrollRunResult $result, Carbon $asOf, Collection $classifications, User $actor): void
    {
        $employmentRecordId = $result->employment_record_id;

        $lines = PayrollRunResultLine::query()
            ->where('payroll_run_result_id', $result->id)
            ->with('component')
            ->get()
            ->filter(fn (PayrollRunResultLine $l) => $l->component->isEarning());

        foreach ($lines as $line) {
            if (! $classifications->has($line->salary_component_id)) {
                throw new StatutorySalaryComponentNotClassifiedException($line->salary_component_id, $asOf->toDateString());
            }
        }

        $pfResult = $this->calculatePf($school, $employmentRecordId, $lines, $classifications, $asOf);
        $esiResult = $this->calculateEsi($school, $employmentRecordId, $lines, $classifications, $asOf);
        $ptResult = $this->calculatePt($result, $asOf);
        $lwfResult = $this->calculateLwf($school, $employmentRecordId, $result, $asOf);

        $employeeStatutoryWithholdingThisRun = $pfResult['result']->employeeMandatoryContribution
            ->add($pfResult['result']->employeeVoluntaryContribution)
            ->add($esiResult['result']->employeeContribution)
            ->add($ptResult['amount'])
            ->add($lwfResult['result']->employeeAmount);

        $tdsResult = $this->calculateTds($school, $employmentRecordId, $result, $asOf, $classifications, $employeeStatutoryWithholdingThisRun);

        PayrollStatutoryCalculationResult::query()->create([
            'school_id' => $school->id,
            'payroll_run_result_id' => $result->id,
            'pf_rule_version_id' => $pfResult['ruleVersionId'],
            'esi_rule_version_id' => $esiResult['ruleVersionId'],
            'professional_tax_rule_version_id' => $ptResult['ruleVersionId'],
            'lwf_rule_version_id' => $lwfResult['ruleVersionId'],
            'income_tax_rule_version_id' => $tdsResult['ruleVersionId'],

            'is_pf_excluded_employee' => $pfResult['result']->isExcludedEmployee,
            'pf_uncapped_statutory_wage' => $pfResult['result']->uncappedStatutoryWage->amount(),
            'pf_contribution_base' => $pfResult['result']->contributionBase->amount(),
            'employee_pf_mandatory' => $pfResult['result']->employeeMandatoryContribution->amount(),
            'employee_pf_voluntary' => $pfResult['result']->employeeVoluntaryContribution->amount(),
            'employer_pf_total' => $pfResult['result']->employerTotalContribution->amount(),
            'employer_eps' => $pfResult['result']->employerEpsContribution->amount(),
            'employer_epf' => $pfResult['result']->employerEpfContribution->amount(),
            'pf_edli' => $pfResult['result']->edliContribution->amount(),
            'pf_admin_charge' => $pfResult['result']->adminCharge->amount(),

            'esi_is_covered' => $esiResult['result']->isCovered,
            'esi_statutory_wage' => $esiResult['wage']->amount(),
            'employee_esi' => $esiResult['result']->employeeContribution->amount(),
            'employer_esi' => $esiResult['result']->employerContribution->amount(),

            'professional_tax' => $ptResult['amount']->amount(),

            'lwf_charged' => $lwfResult['result']->shouldCharge,
            'employee_lwf' => $lwfResult['result']->employeeAmount->amount(),
            'employer_lwf' => $lwfResult['result']->employerAmount->amount(),

            'tds_monthly_deduction' => $tdsResult['result']->monthlyDeduction->amount(),
            'tds_residual_compliance_exception' => $tdsResult['result']->residualComplianceException?->amount(),
        ]);

        if ($lwfResult['result']->shouldCharge) {
            $this->chargeLwfCycle($school, $employmentRecordId, $lwfResult['cycleYear'], $result->id);
        }
    }

    /**
     * @param  Collection<int, PayrollRunResultLine>  $lines
     * @param  Collection<string, PayrollSalaryComponentStatutoryClassification>  $classifications
     */
    private function calculatePf(School $school, string $employmentRecordId, Collection $lines, Collection $classifications, Carbon $asOf): array
    {
        $status = EmployeePfStatus::query()
            ->where('school_id', $school->id)
            ->where('employment_record_id', $employmentRecordId)
            ->first();

        if ($status === null) {
            throw new StatutoryEmployeeFactsMissingException($employmentRecordId, 'employee_pf_status');
        }

        $amounts = [];
        foreach ($lines as $line) {
            $classification = $classifications->get($line->salary_component_id);
            if ($classification->pf_classification === PfComponentClassification::ExcludedNonRemuneration->value
                || $classification->pf_classification === 'not_applicable') {
                continue;
            }
            $key = $classification->pf_classification;
            $amounts[$key] = ($amounts[$key] ?? Money::of('0.00', 'INR'))->add(Money::of($line->amount, 'INR'));
        }

        $input = new PfCalculationInput(
            componentAmounts: $amounts,
            facts: new PfEmployeeStatutoryFacts(
                hasExistingPfMembership: $status->has_existing_pf_membership,
                hasUan: $status->has_uan,
                hasApprovedHigherWageContribution: $status->has_approved_higher_wage_contribution,
                isEpsEligible: $status->is_eps_eligible,
            ),
        );

        $ruleModel = PayrollPfRuleVersion::query()
            ->where('status', 'active')
            ->where('effective_from', '<=', $asOf)
            ->orderByDesc('effective_from')
            ->first();

        if ($ruleModel === null) {
            throw new StatutoryRuleVersionNotFoundException('PF', $asOf->toDateString());
        }

        $rule = new PfRuleVersion(
            effectiveFrom: $ruleModel->effective_from->toDateString(),
            employeeContributionRate: $ruleModel->employee_contribution_rate,
            employerContributionRate: $ruleModel->employer_contribution_rate,
            epsRate: $ruleModel->eps_rate,
            edliRate: $ruleModel->edli_rate,
            adminChargeRate: $ruleModel->admin_charge_rate,
            membershipWageCeiling: $ruleModel->membership_wage_ceiling,
            epsWageCeiling: $ruleModel->eps_wage_ceiling,
            edliWageCeiling: $ruleModel->edli_wage_ceiling,
            adminChargeMinimum: $ruleModel->admin_charge_minimum,
        );

        return ['result' => $this->pf->calculate($input, $rule), 'ruleVersionId' => $ruleModel->id];
    }

    /**
     * @param  Collection<int, PayrollRunResultLine>  $lines
     * @param  Collection<string, PayrollSalaryComponentStatutoryClassification>  $classifications
     */
    private function calculateEsi(School $school, string $employmentRecordId, Collection $lines, Collection $classifications, Carbon $asOf): array
    {
        $wage = Money::of('0.00', 'INR');
        foreach ($lines as $line) {
            $classification = $classifications->get($line->salary_component_id);
            if ($classification->esi_wage_included) {
                $wage = $wage->add(Money::of($line->amount, 'INR'));
            }
        }

        $ruleModel = PayrollEsiRuleVersion::query()
            ->where('status', 'active')
            ->where('effective_from', '<=', $asOf)
            ->orderByDesc('effective_from')
            ->first();

        if ($ruleModel === null) {
            throw new StatutoryRuleVersionNotFoundException('ESI', $asOf->toDateString());
        }

        $rule = new EsiRuleVersion(
            effectiveFrom: $ruleModel->effective_from->toDateString(),
            employeeContributionRate: $ruleModel->employee_contribution_rate,
            employerContributionRate: $ruleModel->employer_contribution_rate,
            wageThreshold: $ruleModel->wage_threshold,
            averageDailyWageExemptionThreshold: $ruleModel->average_daily_wage_exemption_threshold,
        );

        [$periodStart, $periodEnd] = $this->esiContributionPeriodFor($asOf);

        $coverage = EmployeeEsiCoverage::query()
            ->where('school_id', $school->id)
            ->where('employment_record_id', $employmentRecordId)
            ->where('period_start', $periodStart->toDateString())
            ->first();

        if ($coverage === null) {
            $isCovered = $this->esiCoverage->determineCoverageAtPeriodStart($wage, $rule);

            try {
                $coverage = EmployeeEsiCoverage::query()->create([
                    'school_id' => $school->id,
                    'employment_record_id' => $employmentRecordId,
                    'period_start' => $periodStart,
                    'period_end' => $periodEnd,
                    'entry_wage' => $wage->amount(),
                    'is_covered' => $isCovered,
                ]);
            } catch (UniqueConstraintViolationException) {
                // A concurrent process already initialized this
                // period's coverage decision -- re-read it rather than
                // re-decide (continuity rule: decided once).
                $coverage = EmployeeEsiCoverage::query()
                    ->where('school_id', $school->id)
                    ->where('employment_record_id', $employmentRecordId)
                    ->where('period_start', $periodStart->toDateString())
                    ->firstOrFail();
            }
        }

        $input = new EsiContributionInput(
            coveredForThisPeriod: $coverage->is_covered,
            statutoryWage: $wage,
        );

        return [
            'result' => $this->esiContribution->calculate($input, $rule),
            'ruleVersionId' => $ruleModel->id,
            'wage' => $wage,
        ];
    }

    private function calculatePt(PayrollRunResult $result, Carbon $asOf): array
    {
        $ruleModel = PayrollProfessionalTaxRuleVersion::query()
            ->where('jurisdiction', 'Telangana')
            ->where('status', 'active')
            ->where('effective_from', '<=', $asOf)
            ->orderByDesc('effective_from')
            ->first();

        if ($ruleModel === null) {
            throw new StatutoryRuleVersionNotFoundException('Professional Tax (Telangana)', $asOf->toDateString());
        }

        $rule = new ProfessionalTaxRuleVersion(
            effectiveFrom: $ruleModel->effective_from->toDateString(),
            jurisdiction: $ruleModel->jurisdiction,
            slabs: $ruleModel->slabs->map(fn ($s) => ['upTo' => $s->upper_bound, 'amount' => $s->amount])->all(),
        );

        $monthlyWage = Money::of($result->gross_amount, 'INR');

        return [
            'amount' => $this->professionalTax->calculate($monthlyWage, $rule),
            'ruleVersionId' => $ruleModel->id,
        ];
    }

    private function calculateLwf(School $school, string $employmentRecordId, PayrollRunResult $result, Carbon $asOf): array
    {
        $ruleModel = PayrollLwfRuleVersion::query()
            ->where('jurisdiction', 'Telangana')
            ->where('status', 'active')
            ->where('effective_from', '<=', $asOf)
            ->orderByDesc('effective_from')
            ->first();

        if ($ruleModel === null) {
            throw new StatutoryRuleVersionNotFoundException('LWF (Telangana)', $asOf->toDateString());
        }

        $rule = new LabourWelfareFundRuleVersion(
            effectiveFrom: $ruleModel->effective_from->toDateString(),
            jurisdiction: $ruleModel->jurisdiction,
            employeeAmount: $ruleModel->employee_amount,
            employerAmount: $ruleModel->employer_amount,
        );

        $cycleYear = $this->fiscalYearStart($asOf)->year;

        $alreadyCharged = PayrollLwfAnnualCharge::query()
            ->where('school_id', $school->id)
            ->where('employment_record_id', $employmentRecordId)
            ->where('annual_cycle_year', $cycleYear)
            ->exists();

        // See this class's own docblock -- LWF category eligibility is
        // disclosed as always-true pending a School-configurable
        // exclusion model.
        $isEligibleCategory = true;

        return [
            'result' => $this->lwf->calculate($isEligibleCategory, $alreadyCharged, $rule),
            'ruleVersionId' => $ruleModel->id,
            'cycleYear' => $cycleYear,
        ];
    }

    private function chargeLwfCycle(School $school, string $employmentRecordId, int $cycleYear, string $payrollRunResultId): void
    {
        try {
            PayrollLwfAnnualCharge::query()->create([
                'school_id' => $school->id,
                'employment_record_id' => $employmentRecordId,
                'annual_cycle_year' => $cycleYear,
                'payroll_run_result_id' => $payrollRunResultId,
            ]);
        } catch (UniqueConstraintViolationException) {
            // A concurrent process already charged this employee's
            // LWF for this cycle -- the database is authoritative; the
            // amount already computed into this result row is a
            // documented race outcome for a human to reconcile, never
            // silently corrected here.
        }
    }

    /**
     * @param  Collection<string, PayrollSalaryComponentStatutoryClassification>  $classifications
     */
    private function calculateTds(School $school, string $employmentRecordId, PayrollRunResult $result, Carbon $asOf, Collection $classifications, Money $employeeStatutoryWithholdingThisRun): array
    {
        $profile = EmployeeTaxProfile::query()
            ->where('school_id', $school->id)
            ->where('employment_record_id', $employmentRecordId)
            ->where('fiscal_year_start', $this->fiscalYearStart($asOf)->toDateString())
            ->first();

        if ($profile === null) {
            throw new StatutoryEmployeeFactsMissingException($employmentRecordId, 'employee_tax_profile for fiscal year '.$this->fiscalYearStart($asOf)->toDateString());
        }

        $ruleModel = PayrollIncomeTaxRuleVersion::query()
            ->where('regime', $profile->regime)
            ->where('status', 'active')
            ->where('effective_from', '<=', $asOf)
            ->orderByDesc('effective_from')
            ->first();

        if ($ruleModel === null) {
            throw new StatutoryRuleVersionNotFoundException("Income Tax ({$profile->regime} regime)", $asOf->toDateString());
        }

        $rule = new IncomeTaxRuleVersion(
            effectiveFrom: $ruleModel->effective_from->toDateString(),
            regime: $ruleModel->regime,
            slabs: $ruleModel->incomeSlabs->map(fn ($s) => ['upTo' => $s->upper_bound, 'rate' => $s->rate])->all(),
            standardDeduction: $ruleModel->standard_deduction,
            rebateQualifyingIncomeThreshold: $ruleModel->rebate_qualifying_income_threshold,
            rebateMaximum: $ruleModel->rebate_maximum,
            cessRate: $ruleModel->cess_rate,
            surchargeSlabs: $ruleModel->surchargeSlabs->map(fn ($s) => ['threshold' => $s->upper_bound, 'rate' => $s->rate])->all(),
        );

        $fiscalYearStart = $this->fiscalYearStart($asOf);
        $fiscalMonthIndex = ((int) $asOf->month - 4 + 12) % 12 + 1; // April = 1 .. March = 12
        $remainingCycles = 13 - $fiscalMonthIndex;

        $monthlyTaxableGross = $this->taxableGrossForResult($result, $classifications);

        $priorResults = PayrollRunResult::query()
            ->where('employment_record_id', $employmentRecordId)
            ->where('id', '!=', $result->id)
            ->whereHas('run.period', fn ($q) => $q
                ->where('period_month', '>=', $fiscalYearStart->toDateString())
                ->where('period_month', '<', $asOf->toDateString()))
            ->with('lines.component')
            ->get();

        $priorCumulative = Money::of('0.00', 'INR');
        $cumulativeAlreadyDeducted = Money::of('0.00', 'INR');
        foreach ($priorResults as $priorResult) {
            $priorLines = $priorResult->lines->filter(fn (PayrollRunResultLine $l) => $l->component->isEarning());
            $priorCumulative = $priorCumulative->add($this->taxableGrossForResult($priorResult, $classifications, $priorLines));

            $priorStatutory = PayrollStatutoryCalculationResult::query()->where('payroll_run_result_id', $priorResult->id)->first();
            if ($priorStatutory !== null && $priorStatutory->tds_monthly_deduction !== null) {
                $cumulativeAlreadyDeducted = $cumulativeAlreadyDeducted->add(Money::of($priorStatutory->tds_monthly_deduction, 'INR'));
            }
        }

        $cumulativeIncludingCurrent = $priorCumulative->add($monthlyTaxableGross);
        $remainingMonthsAfterCurrent = $remainingCycles - 1;
        $projectedFromRemaining = $monthlyTaxableGross->multiplyByRate((string) $remainingMonthsAfterCurrent, 2);

        $standardDeduction = Money::of($rule->standardDeduction, 'INR');
        $declaredDeductions = $profile->regime === 'old' ? Money::of((string) $profile->declared_deductions, 'INR') : Money::of('0.00', 'INR');

        $projectedAnnualTaxableIncome = $cumulativeIncludingCurrent
            ->add($projectedFromRemaining)
            ->add(Money::of((string) $profile->previous_employer_income, 'INR'))
            ->add(Money::of((string) $profile->declared_other_income, 'INR'))
            ->add($standardDeduction->negated())
            ->add($declaredDeductions->negated());

        if (! $projectedAnnualTaxableIncome->isPositive()) {
            $projectedAnnualTaxableIncome = Money::of('0.00', 'INR');
        }

        $annualProjectedLiability = $this->incomeTax->calculateAnnualTax($projectedAnnualTaxableIncome, $rule);

        // The previous employer's already-withheld TDS is a one-time
        // credit against THIS employer's obligation -- applied only in
        // the first cycle this employer calculates for the employee
        // this fiscal year (cumulativeAlreadyDeducted from THIS
        // employer is still zero), never re-applied every month.
        $priorEmployerTdsCredit = $cumulativeAlreadyDeducted->isZero()
            ? Money::of((string) $profile->previous_employer_tds, 'INR')
            : null;

        $availableSalary = Money::of($result->net_amount, 'INR')->add($employeeStatutoryWithholdingThisRun->negated());
        if (! $availableSalary->isPositive()) {
            $availableSalary = Money::of('0.00', 'INR');
        }

        $input = new TdsMonthlyDeductionInput(
            annualProjectedLiability: $annualProjectedLiability,
            cumulativeAlreadyDeducted: $cumulativeAlreadyDeducted,
            remainingCycles: $remainingCycles,
            priorEmployerTdsCredit: $priorEmployerTdsCredit,
            availableSalaryForWithholding: $availableSalary,
        );

        return ['result' => $this->tds->calculate($input), 'ruleVersionId' => $ruleModel->id];
    }

    /**
     * @param  Collection<string, PayrollSalaryComponentStatutoryClassification>  $classifications
     * @param  Collection<int, PayrollRunResultLine>|null  $lines
     */
    private function taxableGrossForResult(PayrollRunResult $result, Collection $classifications, ?Collection $lines = null): Money
    {
        $lines ??= PayrollRunResultLine::query()
            ->where('payroll_run_result_id', $result->id)
            ->with('component')
            ->get()
            ->filter(fn (PayrollRunResultLine $l) => $l->component->isEarning());

        $taxable = Money::of('0.00', 'INR');
        foreach ($lines as $line) {
            $classification = $classifications->get($line->salary_component_id);
            if ($classification === null) {
                throw new StatutorySalaryComponentNotClassifiedException($line->salary_component_id, 'a prior fiscal-year cycle');
            }
            if (in_array($classification->income_tax_treatment, ['taxable', 'partially_exempt'], true)) {
                $taxable = $taxable->add(Money::of($line->amount, 'INR'));
            }
        }

        return $taxable;
    }

    private function fiscalYearStart(Carbon $asOf): Carbon
    {
        return $asOf->month >= 4
            ? Carbon::create($asOf->year, 4, 1)
            : Carbon::create($asOf->year - 1, 4, 1);
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function esiContributionPeriodFor(Carbon $asOf): array
    {
        // Standard ESI contribution periods: 1 April-30 September and
        // 1 October-31 March.
        if ($asOf->month >= 4 && $asOf->month <= 9) {
            return [Carbon::create($asOf->year, 4, 1), Carbon::create($asOf->year, 9, 30)];
        }

        $startYear = $asOf->month >= 10 ? $asOf->year : $asOf->year - 1;

        return [Carbon::create($startYear, 10, 1), Carbon::create($startYear + 1, 3, 31)];
    }
}
