<?php

namespace App\Http\Controllers\App\Payroll;

use App\Domain\HR\Infrastructure\EmploymentRecord;
use App\Domain\Payroll\Application\CorrectionDeltaInput;
use App\Domain\Payroll\Application\PayrollRunAdministrationService;
use App\Domain\Payroll\Application\PayrollRunReadService;
use App\Domain\Payroll\Application\PayrollRunResultDetail;
use App\Domain\Payroll\Application\PayrollRunResultReadService;
use App\Domain\Payroll\Application\PayrollStructureReadService;
use App\Domain\Payroll\Application\SalaryComponentSummary;
use App\Domain\Payroll\Infrastructure\PayrollAccountingConfiguration;
use App\Domain\Payroll\Infrastructure\PayrollPeriod;
use App\Domain\Payroll\Infrastructure\PayrollRun;
use App\Domain\Payroll\Infrastructure\PayrollRunPosting;
use App\Domain\Payroll\Infrastructure\SalaryComponent;
use App\Http\Controllers\Controller;
use App\Models\School;
use App\Models\User;
use App\Support\Authorization\CapabilityResolver;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Phase 9.9 -- session-authenticated Inertia pages for Payroll Runs:
 * creation, calculation/recalculation, the partial-period manual
 * override and correction-delta entry flows, calculation review,
 * approval, and (via `PayrollRunPostingController`) posting/reversal/
 * correction-run creation. Delegates every read/write to the SAME
 * `PayrollRunReadService`/`PayrollRunResultReadService`/
 * `PayrollRunAdministrationService` the JSON API controller calls --
 * never a raw Eloquent query for anything those services already
 * authorize, and never a re-derived calculation/eligibility rule.
 *
 * Unlike their API counterparts, these routes do NOT carry the
 * `idempotent` middleware -- see the detailed rationale in
 * `routes/web.php`'s Payroll section (`EnsureIdempotent`/`IdempotencyGuard`
 * were built exclusively for `JsonResponse`-shaped API responses and
 * would replay a broken redirect here as-is). Double-submit protection
 * for these actions is the standard disabled-button/`processing` guard
 * on the frontend plus the structural at-most-once guarantees each
 * action already has regardless of transport.
 */
class PayrollRunController extends Controller
{
    public function store(TenantContext $context, string $payrollPeriod, PayrollRunAdministrationService $service): RedirectResponse
    {
        $context->requireSchool();
        $period = PayrollPeriod::query()->findOrFail($payrollPeriod);
        $run = $service->createRun($period, $context->actor());

        return redirect("/app/payroll/runs/{$run->id}");
    }

    public function storeCorrection(Request $request, TenantContext $context, string $payrollRun, PayrollRunAdministrationService $service): RedirectResponse
    {
        $context->requireSchool();
        $correctsRun = PayrollRun::query()->findOrFail($payrollRun);

        $validated = $request->validate([
            'payroll_period_id' => ['required', 'uuid'],
        ]);
        $period = PayrollPeriod::query()->findOrFail($validated['payroll_period_id']);

        $correction = $service->createCorrectionRun($correctsRun, $period, $context->actor());

        return redirect("/app/payroll/runs/{$correction->id}");
    }

    public function show(TenantContext $context, PayrollRunReadService $runs, PayrollRunResultReadService $results, PayrollStructureReadService $structures, CapabilityResolver $capabilities, string $payrollRun): Response
    {
        $school = $context->requireSchool();
        $run = PayrollRun::query()->findOrFail($payrollRun);

        return $this->renderShow($context, $runs, $results, $structures, $capabilities, $school, $run);
    }

    public function calculate(TenantContext $context, PayrollRunReadService $runs, PayrollRunResultReadService $results, PayrollStructureReadService $structures, CapabilityResolver $capabilities, PayrollRunAdministrationService $service, string $payrollRun): Response
    {
        $school = $context->requireSchool();
        $run = PayrollRun::query()->findOrFail($payrollRun);
        $outcome = $service->calculate($run, $context->actor());

        return $this->renderShow($context, $runs, $results, $structures, $capabilities, $school, $run->fresh(), [
            'resolvedCount' => count($outcome->resolvedEmploymentRecordIds),
            'unresolvedEmploymentRecordIds' => $outcome->unresolvedEmploymentRecordIds,
            'transitionedToCalculated' => $outcome->transitionedToCalculated,
        ]);
    }

    public function manualOverride(Request $request, TenantContext $context, string $payrollRun, PayrollRunAdministrationService $service): RedirectResponse
    {
        $context->requireSchool();
        $run = PayrollRun::query()->findOrFail($payrollRun);

        $validated = $request->validate([
            'employment_record_id' => ['required', 'uuid'],
            'component_amounts' => ['required', 'array', 'min:1'],
            'component_amounts.*' => ['required', 'string', 'regex:/^\d{1,12}(\.\d{1,2})?$/'],
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        $employmentRecord = EmploymentRecord::query()->findOrFail($validated['employment_record_id']);

        $service->recordManualOverride($run, $employmentRecord, $validated['component_amounts'], $validated['reason'], $context->actor());

        return redirect("/app/payroll/runs/{$run->id}");
    }

    public function correctionDelta(Request $request, TenantContext $context, string $payrollRun, PayrollRunAdministrationService $service): RedirectResponse
    {
        $context->requireSchool();
        $run = PayrollRun::query()->findOrFail($payrollRun);

        $validated = $request->validate([
            'employment_record_id' => ['required', 'uuid'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.salary_component_id' => ['required', 'uuid'],
            'lines.*.amount' => ['required', 'string', 'regex:/^\d{1,12}(\.\d{1,2})?$/'],
            'lines.*.effect' => ['required', Rule::in(['increase', 'decrease'])],
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        $employmentRecord = EmploymentRecord::query()->findOrFail($validated['employment_record_id']);

        $lines = array_map(
            fn (array $line) => new CorrectionDeltaInput($line['salary_component_id'], $line['amount'], $line['effect']),
            $validated['lines'],
        );

        $service->recordCorrectionDelta($run, $employmentRecord, $lines, $validated['reason'], $context->actor());

        return redirect("/app/payroll/runs/{$run->id}");
    }

    public function approve(Request $request, TenantContext $context, string $payrollRun, PayrollRunAdministrationService $service): RedirectResponse
    {
        $context->requireSchool();
        $run = PayrollRun::query()->findOrFail($payrollRun);
        $service->approve($run, $context->actor());

        return redirect("/app/payroll/runs/{$run->id}");
    }

    /**
     * Shared presentation for `show()` and `calculate()` (the latter
     * needs to display a just-computed `PayrollCalculationOutcome`
     * inline -- there is no persisted record of WHICH EmploymentRecords
     * were unresolved once the request ends, so it is rendered directly
     * rather than redirected away and lost).
     */
    private function renderShow(TenantContext $context, PayrollRunReadService $runs, PayrollRunResultReadService $results, PayrollStructureReadService $structures, CapabilityResolver $capabilities, School $school, PayrollRun $run, ?array $calculationOutcome = null): Response
    {
        $actor = $context->actor();
        // Only fetched for an actor who also holds payroll.structures.view
        // -- a plain payroll.runs.view holder must not be denied this
        // page merely because the component picker (used by the manual-
        // override/correction-delta forms, themselves only shown to a
        // payroll.runs.prepare holder) needs a capability that run
        // viewing itself never required.
        $components = $capabilities->canInSchool($actor, 'payroll.structures.view', $school)
            ? $structures->listComponents($school, $actor)
            : [];
        $summary = $runs->getRun($school, $run->id, $actor);

        $canViewSensitive = $capabilities->canInSchool($actor, 'payroll.compensation.sensitive.view', $school);
        $resultDetails = $canViewSensitive ? $results->listResults($school, $run->id, $actor) : null;

        $userIds = array_values(array_filter([$summary->preparedByUserId, $summary->approvedByUserId, $summary->postedByUserId]));
        $userNames = User::query()->whereIn('id', $userIds)->pluck('name', 'id');

        $postings = $context->withSchool($school, fn () => PayrollRunPosting::query()
            ->where('school_id', $school->id)
            ->where('payroll_run_id', $run->id)
            ->orderBy('created_at')
            ->get());
        $isReversed = $postings->contains(fn (PayrollRunPosting $p) => $p->isReversal());

        $canPost = $capabilities->canInSchool($actor, 'payroll.runs.post', $school);
        $accountingReadiness = null;
        if ($canPost && $summary->status === 'approved' && ! $isReversed) {
            // Non-sensitive prerequisite check (account codes/mapping
            // completeness, never a monetary figure) shown to whoever
            // can see the Post action -- independent of
            // `payroll.accounting.manage`, matching this checkpoint's
            // "show only what's necessary to understand the accounting
            // action" requirement.
            $config = $context->withSchool($school, fn () => PayrollAccountingConfiguration::query()
                ->where('school_id', $school->id)
                ->with(['salaryExpenseLedgerAccount', 'salaryPayableLedgerAccount'])
                ->first());
            $missingMappings = $context->withSchool($school, fn () => SalaryComponent::query()
                ->where('school_id', $school->id)
                ->where('type', 'deduction')
                ->where('status', 'active')
                ->whereNull('liability_ledger_account_id')
                ->get(['id', 'code', 'name']));

            $accountingReadiness = [
                'configured' => $config !== null,
                'salaryExpenseLedgerAccountLabel' => $config?->salaryExpenseLedgerAccount
                    ? "{$config->salaryExpenseLedgerAccount->code} — {$config->salaryExpenseLedgerAccount->name}"
                    : null,
                'salaryPayableLedgerAccountLabel' => $config?->salaryPayableLedgerAccount
                    ? "{$config->salaryPayableLedgerAccount->code} — {$config->salaryPayableLedgerAccount->name}"
                    : null,
                'missingDeductionMappings' => $missingMappings->map(fn (SalaryComponent $c) => "{$c->code} — {$c->name}")->all(),
                'readyToPost' => $config !== null && $missingMappings->isEmpty(),
            ];
        }

        $unresolvedEmployees = [];
        $unresolvedIds = $calculationOutcome['unresolvedEmploymentRecordIds'] ?? [];
        if ($unresolvedIds !== []) {
            $unresolvedEmployees = $context->withSchool($school, fn () => EmploymentRecord::query()
                ->whereIn('id', $unresolvedIds)
                ->with('employee')
                ->get()
                ->map(fn (EmploymentRecord $er) => [
                    'employmentRecordId' => $er->id,
                    'employeeId' => $er->employee_id,
                    'employeeFullName' => $er->employee?->full_name,
                    'employeeNumber' => $er->employee?->employee_number,
                ])->all());
        }

        // Non-sensitive identity for every result row -- so a viewer
        // with `payroll.compensation.sensitive.view` never has to work
        // from a raw employmentRecordId in the results table below.
        $resultEmployeeNames = [];
        if ($resultDetails !== null && $resultDetails !== []) {
            $resultEmployeeNames = $context->withSchool($school, fn () => EmploymentRecord::query()
                ->whereIn('id', array_map(fn (PayrollRunResultDetail $r) => $r->employmentRecordId, $resultDetails))
                ->with('employee')
                ->get()
                ->mapWithKeys(fn (EmploymentRecord $er) => [$er->id => [
                    'employeeFullName' => $er->employee?->full_name,
                    'employeeNumber' => $er->employee?->employee_number,
                ]])
                ->all());
        }

        // Same identity, drawn from the ORIGINAL run's results, for the
        // correction-delta entry picker on a correction run -- only
        // fetched for a viewer who already holds sensitive-view access
        // (a correction-delta amount is itself Highly Sensitive).
        $correctionCandidates = [];
        if ($canViewSensitive && $summary->runKind === 'correction' && $summary->correctsPayrollRunId !== null) {
            $originalResults = $results->listResults($school, $summary->correctsPayrollRunId, $actor);
            $originalNames = $context->withSchool($school, fn () => EmploymentRecord::query()
                ->whereIn('id', array_map(fn (PayrollRunResultDetail $r) => $r->employmentRecordId, $originalResults))
                ->with('employee')
                ->get()
                ->mapWithKeys(fn (EmploymentRecord $er) => [$er->id => $er->employee?->full_name])
                ->all());
            $correctionCandidates = array_map(fn (PayrollRunResultDetail $r) => [
                'employmentRecordId' => $r->employmentRecordId,
                'employeeFullName' => $originalNames[$r->employmentRecordId] ?? null,
            ], $originalResults);
        }

        $openPeriods = [];
        if ($summary->status === 'posted' && ! $isReversed) {
            $openPeriods = $context->withSchool($school, fn () => PayrollPeriod::query()
                ->where('school_id', $school->id)
                ->where('status', 'open')
                ->orderByDesc('period_month')
                ->get(['id', 'period_month'])
                ->map(fn (PayrollPeriod $p) => ['id' => $p->id, 'periodMonth' => $p->period_month->toDateString()])
                ->all());
        }

        return Inertia::render('App/Payroll/Runs/Show', [
            'run' => [
                'id' => $summary->id,
                'payrollPeriodId' => $summary->payrollPeriodId,
                'runKind' => $summary->runKind,
                'correctsPayrollRunId' => $summary->correctsPayrollRunId,
                'status' => $summary->status,
                'preparedByUserId' => $summary->preparedByUserId,
                'preparedByName' => $userNames->get($summary->preparedByUserId),
                'approvedByUserId' => $summary->approvedByUserId,
                'approvedByName' => $summary->approvedByUserId ? $userNames->get($summary->approvedByUserId) : null,
                'postedByUserId' => $summary->postedByUserId,
                'postedByName' => $summary->postedByUserId ? $userNames->get($summary->postedByUserId) : null,
                'approvedAt' => $summary->approvedAt?->toIso8601String(),
                'postedAt' => $summary->postedAt?->toIso8601String(),
                'isReversed' => $isReversed,
                'resultsExpiredAt' => $summary->resultsExpiredAt?->toIso8601String(),
            ],
            'postings' => $postings->map(fn (PayrollRunPosting $p) => [
                'id' => $p->id,
                'postingKind' => $p->posting_kind,
                'journalEntryId' => $p->journal_entry_id,
                'reversalOfPayrollRunPostingId' => $p->reversal_of_payroll_run_posting_id,
                'reason' => $p->reason,
            ])->all(),
            'canViewSensitive' => $canViewSensitive,
            'results' => $resultDetails === null ? null : array_map(fn (PayrollRunResultDetail $r) => [
                'employmentRecordId' => $r->employmentRecordId,
                'employeeId' => $r->employeeId,
                'employeeFullName' => $resultEmployeeNames[$r->employmentRecordId]['employeeFullName'] ?? null,
                'employeeNumber' => $resultEmployeeNames[$r->employmentRecordId]['employeeNumber'] ?? null,
                'grossAmount' => $r->grossAmount,
                'totalDeductions' => $r->totalDeductions,
                'netAmount' => $r->netAmount,
            ], $resultDetails),
            'calculationOutcome' => $calculationOutcome,
            'unresolvedEmployees' => $unresolvedEmployees,
            'correctionCandidates' => $correctionCandidates,
            'openPeriods' => $openPeriods,
            'components' => array_map(fn (SalaryComponentSummary $c) => [
                'id' => $c->id,
                'code' => $c->code,
                'name' => $c->name,
                'type' => $c->type,
            ], array_values(array_filter($components, fn (SalaryComponentSummary $c) => $c->status === 'active'))),
            'canPrepare' => $capabilities->canInSchool($actor, 'payroll.runs.prepare', $school),
            'canApprove' => $capabilities->canInSchool($actor, 'payroll.runs.approve', $school)
                && $summary->preparedByUserId !== $actor->id,
            'isSelfPrepared' => $summary->preparedByUserId === $actor->id,
            'canPost' => $canPost,
            'accountingReadiness' => $accountingReadiness,
            'canReverse' => $capabilities->canInSchool($actor, 'payroll.runs.reverse', $school),
        ]);
    }
}
