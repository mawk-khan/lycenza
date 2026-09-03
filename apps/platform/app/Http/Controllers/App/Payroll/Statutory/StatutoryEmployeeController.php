<?php

namespace App\Http\Controllers\App\Payroll\Statutory;

use App\Domain\HR\Infrastructure\EmploymentRecord;
use App\Domain\Payroll\Statutory\Application\Admin\EmployeeEsiCoverageAdminService;
use App\Domain\Payroll\Statutory\Application\Admin\EmployeePfStatusAdminService;
use App\Domain\Payroll\Statutory\Application\Admin\EmployeeTaxProfileAdminService;
use App\Domain\Payroll\Statutory\Application\Admin\StatutoryIdentifierAdminService;
use App\Domain\Payroll\Statutory\Application\Exceptions\StatutoryPayrollException;
use App\Domain\Payroll\Statutory\Infrastructure\PayrollStatutoryCalculationResult;
use App\Http\Controllers\Controller;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Authorization\CapabilityResolver;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Checkpoint 9.6I (Section 4) -- one combined session-authenticated
 * Inertia page per EmploymentRecord covering PF status, ESI coverage,
 * tax profile, masked statutory identifiers, and a read-only
 * statutory-result breakdown (most recent calculated results). The
 * four write forms POST back to their own narrow action here, each
 * delegating to its own Admin service (already capability-checked
 * internally) -- this controller adds no authorization logic of its
 * own beyond the page-level `payroll.statutory.view` gate every
 * sub-service also independently re-enforces.
 *
 * `revealIdentifier()` is a SEPARATE, on-demand JSON endpoint --
 * mirrors `CompensationController::values()`'s established "Highly
 * Sensitive value fetched only when explicitly requested, never
 * embedded in the initial page load" pattern exactly (Section 5's own
 * requirement).
 */
class StatutoryEmployeeController extends Controller
{
    use AuthorizesCapability;

    public function search(Request $request, TenantContext $context): JsonResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('payroll.statutory.view', $school);

        $validated = $request->validate(['q' => ['required', 'string', 'min:2', 'max:100']]);

        $records = $context->withSchool($school, fn () => EmploymentRecord::query()
            ->where('school_id', $school->id)
            ->whereHas('employee', fn ($q) => $q
                ->where('school_id', $school->id)
                ->where(fn ($q2) => $q2
                    ->where('full_name', 'ilike', "%{$validated['q']}%")
                    ->orWhere('employee_number', 'ilike', "%{$validated['q']}%")))
            ->with('employee')
            ->limit(20)
            ->get());

        return response()->json(['data' => $records->map(fn (EmploymentRecord $er) => [
            'employmentRecordId' => $er->id,
            'employeeFullName' => $er->employee?->full_name,
            'employeeNumber' => $er->employee?->employee_number,
        ])->all()]);
    }

    public function show(
        TenantContext $context,
        CapabilityResolver $capabilities,
        EmployeePfStatusAdminService $pfService,
        EmployeeEsiCoverageAdminService $esiService,
        EmployeeTaxProfileAdminService $taxService,
        StatutoryIdentifierAdminService $identifierService,
        string $employmentRecord,
    ): Response {
        $school = $context->requireSchool();
        $actor = $context->actor();
        $record = EmploymentRecord::query()->where('school_id', $school->id)->with('employee')->findOrFail($employmentRecord);

        $this->authorizeCapability('payroll.statutory.view', $school);

        $pfStatus = $pfService->view($school, $record->id, $actor);
        $esiHistory = $esiService->history($school, $record->id, $actor);
        $taxProfiles = $taxService->history($school, $record->id, $actor);
        $identifiers = $identifierService->list($school, $record->id, $actor);

        $results = $context->withSchool($school, fn () => PayrollStatutoryCalculationResult::query()
            ->whereHas('payrollRunResult', fn ($q) => $q->where('employment_record_id', $record->id))
            ->with('payrollRunResult.run.period')
            ->orderByDesc('created_at')
            ->limit(12)
            ->get());

        return Inertia::render('App/Payroll/Statutory/Employee/Show', [
            'employmentRecord' => [
                'id' => $record->id,
                'employeeFullName' => $record->employee?->full_name,
                'employeeNumber' => $record->employee?->employee_number,
            ],
            'can' => [
                'manage' => $capabilities->canInSchool($actor, 'payroll.statutory.manage', $school),
                'viewIdentifiers' => $capabilities->canInSchool($actor, 'payroll.statutory.identifiers.view', $school),
                'manageIdentifiers' => $capabilities->canInSchool($actor, 'payroll.statutory.identifiers.manage', $school),
            ],
            'pfStatus' => $pfStatus === null ? null : [
                'hasExistingPfMembership' => $pfStatus->has_existing_pf_membership,
                'hasUan' => $pfStatus->has_uan,
                'hasApprovedHigherWageContribution' => $pfStatus->has_approved_higher_wage_contribution,
                'higherWageApprovalReference' => $pfStatus->higher_wage_approval_reference,
                'higherWageApprovalEffectiveFrom' => $pfStatus->higher_wage_approval_effective_from?->toDateString(),
                'isEpsEligible' => $pfStatus->is_eps_eligible,
                'hasHigherPensionStatus' => $pfStatus->has_higher_pension_status,
            ],
            'esiCoverage' => $esiHistory->map(fn (array $row) => [
                'periodStart' => $row['coverage']->period_start->toDateString(),
                'periodEnd' => $row['coverage']->period_end->toDateString(),
                'entryWage' => $row['coverage']->entry_wage,
                'isCovered' => $row['coverage']->is_covered,
                'continuous' => $row['continuous'],
            ])->all(),
            'taxProfiles' => $taxProfiles->map(fn ($p) => [
                'fiscalYearStart' => $p->fiscal_year_start->toDateString(),
                'regime' => $p->regime,
                'regimeSwitchPolicyReference' => $p->regime_switch_policy_reference,
                'previousEmployerIncome' => $p->previous_employer_income,
                'previousEmployerTds' => $p->previous_employer_tds,
                'declaredOtherIncome' => $p->declared_other_income,
                'declaredDeductions' => $p->declared_deductions,
            ])->all(),
            'identifiers' => $identifiers->all(),
            'statutoryResults' => $results->map(fn (PayrollStatutoryCalculationResult $r) => [
                'periodMonth' => $r->payrollRunResult->run->period->period_month->toDateString(),
                'isPfExcludedEmployee' => $r->is_pf_excluded_employee,
                'employeePfMandatory' => $r->employee_pf_mandatory,
                'employerPfTotal' => $r->employer_pf_total,
                'esiIsCovered' => $r->esi_is_covered,
                'employeeEsi' => $r->employee_esi,
                'employerEsi' => $r->employer_esi,
                'professionalTax' => $r->professional_tax,
                'lwfCharged' => $r->lwf_charged,
                'employeeLwf' => $r->employee_lwf,
                'employerLwf' => $r->employer_lwf,
                'tdsMonthlyDeduction' => $r->tds_monthly_deduction,
                'tdsResidualComplianceException' => $r->tds_residual_compliance_exception,
            ])->all(),
        ]);
    }

    public function revealIdentifier(TenantContext $context, StatutoryIdentifierAdminService $service, string $employmentRecord, string $identifierType): JsonResponse
    {
        $school = $context->requireSchool();

        try {
            $value = $service->reveal($school, $employmentRecord, $identifierType, $context->actor());
        } catch (StatutoryPayrollException $e) {
            abort($e->getStatusCode(), $e->getMessage());
        }

        return response()->json(['data' => ['identifierType' => $identifierType, 'value' => $value]]);
    }

    public function storePfStatus(Request $request, TenantContext $context, EmployeePfStatusAdminService $service, string $employmentRecord): RedirectResponse
    {
        $school = $context->requireSchool();

        $validated = $request->validate([
            'has_existing_pf_membership' => ['required', 'boolean'],
            'has_uan' => ['required', 'boolean'],
            'has_approved_higher_wage_contribution' => ['required', 'boolean'],
            'higher_wage_approval_reference' => ['nullable', 'string', 'max:255'],
            'higher_wage_approval_effective_from' => ['nullable', 'date'],
            'is_eps_eligible' => ['required', 'boolean'],
            'has_higher_pension_status' => ['required', 'boolean'],
        ]);

        try {
            $service->configure($school, $employmentRecord, [
                'hasExistingPfMembership' => $validated['has_existing_pf_membership'],
                'hasUan' => $validated['has_uan'],
                'hasApprovedHigherWageContribution' => $validated['has_approved_higher_wage_contribution'],
                'higherWageApprovalReference' => $validated['higher_wage_approval_reference'] ?? null,
                'higherWageApprovalEffectiveFrom' => $validated['higher_wage_approval_effective_from'] ?? null,
                'isEpsEligible' => $validated['is_eps_eligible'],
                'hasHigherPensionStatus' => $validated['has_higher_pension_status'],
            ], $context->actor());
        } catch (StatutoryPayrollException $e) {
            abort($e->getStatusCode(), $e->getMessage());
        }

        return redirect("/app/payroll/statutory/employees/{$employmentRecord}");
    }

    public function storeEsiCoverage(Request $request, TenantContext $context, EmployeeEsiCoverageAdminService $service, string $employmentRecord): RedirectResponse
    {
        $school = $context->requireSchool();

        $validated = $request->validate([
            'period_start' => ['required', 'date'],
            'period_end' => ['required', 'date', 'after:period_start'],
            'entry_wage' => ['required', 'string', 'regex:/^\d{1,12}(\.\d{1,2})?$/'],
            'is_covered' => ['required', 'boolean'],
        ]);

        try {
            $service->correct(
                $school, $employmentRecord,
                $validated['period_start'], $validated['period_end'], $validated['entry_wage'], $validated['is_covered'],
                $context->actor(),
            );
        } catch (StatutoryPayrollException $e) {
            abort($e->getStatusCode(), $e->getMessage());
        }

        return redirect("/app/payroll/statutory/employees/{$employmentRecord}");
    }

    public function storeTaxProfile(Request $request, TenantContext $context, EmployeeTaxProfileAdminService $service, string $employmentRecord): RedirectResponse
    {
        $school = $context->requireSchool();

        $validated = $request->validate([
            'fiscal_year_start' => ['required', 'date'],
            'regime' => ['required', 'in:old,new'],
            'regime_switch_policy_reference' => ['nullable', 'string', 'max:255'],
            'previous_employer_income' => ['required', 'string', 'regex:/^\d{1,12}(\.\d{1,2})?$/'],
            'previous_employer_tds' => ['required', 'string', 'regex:/^\d{1,12}(\.\d{1,2})?$/'],
            'declared_other_income' => ['required', 'string', 'regex:/^\d{1,12}(\.\d{1,2})?$/'],
            'declared_deductions' => ['required', 'string', 'regex:/^\d{1,12}(\.\d{1,2})?$/'],
        ]);

        try {
            $service->configure($school, $employmentRecord, $validated['fiscal_year_start'], [
                'regime' => $validated['regime'],
                'regimeSwitchPolicyReference' => $validated['regime_switch_policy_reference'] ?? null,
                'previousEmployerIncome' => $validated['previous_employer_income'],
                'previousEmployerTds' => $validated['previous_employer_tds'],
                'declaredOtherIncome' => $validated['declared_other_income'],
                'declaredDeductions' => $validated['declared_deductions'],
            ], $context->actor());
        } catch (StatutoryPayrollException $e) {
            abort($e->getStatusCode(), $e->getMessage());
        }

        return redirect("/app/payroll/statutory/employees/{$employmentRecord}");
    }

    public function storeIdentifier(Request $request, TenantContext $context, StatutoryIdentifierAdminService $service, string $employmentRecord): RedirectResponse
    {
        $school = $context->requireSchool();

        $validated = $request->validate([
            'identifier_type' => ['required', 'in:pan,uan,pf_member_id,esic_ip_number'],
            'value' => ['required', 'string', 'max:64'],
        ]);

        try {
            $service->set($school, $employmentRecord, $validated['identifier_type'], $validated['value'], $context->actor());
        } catch (StatutoryPayrollException $e) {
            abort($e->getStatusCode(), $e->getMessage());
        }

        return redirect("/app/payroll/statutory/employees/{$employmentRecord}");
    }
}
