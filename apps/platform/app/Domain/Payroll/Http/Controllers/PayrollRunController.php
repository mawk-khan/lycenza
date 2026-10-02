<?php

namespace App\Domain\Payroll\Http\Controllers;

use App\Domain\HR\Infrastructure\EmploymentRecord;
use App\Domain\Payroll\Application\CorrectionDeltaInput;
use App\Domain\Payroll\Application\PayrollRunAdministrationService;
use App\Domain\Payroll\Application\PayrollRunReadService;
use App\Domain\Payroll\Application\PayrollRunSummary;
use App\Domain\Payroll\Infrastructure\PayrollPeriod;
use App\Domain\Payroll\Infrastructure\PayrollRun;
use App\Http\Controllers\Controller;
use App\Models\School;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Phase 9.8 -- thin HTTP transport over `PayrollRunReadService`
 * (`payroll.runs.view`) and `PayrollRunAdministrationService`
 * (`payroll.runs.prepare` for create/calculate/adjustments,
 * `payroll.runs.approve` for `approve()` only -- that class already
 * authorizes each method with its own exact capability, this
 * controller adds no authorization of its own beyond the route
 * `capability:` middleware, mirroring `SalaryComponentController`'s
 * identical split). `{payrollPeriod}`/`{payrollRun}`/`{employmentRecord}`
 * resolved via plain Eloquent queries -- see that controller's
 * docblock for why this is safe.
 */
class PayrollRunController extends Controller
{
    public function index(School $school, string $payrollPeriod, PayrollRunReadService $service): JsonResponse
    {
        $period = PayrollPeriod::query()->findOrFail($payrollPeriod);
        $runs = $service->listRuns($school, $period, request()->user());

        return response()->json([
            'data' => array_map(fn (PayrollRunSummary $r) => $this->present($r), $runs),
        ]);
    }

    public function show(School $school, string $payrollRun, PayrollRunReadService $service): JsonResponse
    {
        $summary = $service->getRun($school, $payrollRun, request()->user());

        return response()->json(['data' => $this->present($summary)]);
    }

    public function store(Request $request, School $school, string $payrollPeriod, PayrollRunAdministrationService $service): JsonResponse
    {
        $period = PayrollPeriod::query()->findOrFail($payrollPeriod);
        $run = $service->createRun($period, $request->user());

        return response()->json(['data' => $this->present(PayrollRunSummary::fromModel($run))], 201);
    }

    public function correction(Request $request, School $school, string $payrollRun, PayrollRunAdministrationService $service): JsonResponse
    {
        $correctsRun = PayrollRun::query()->findOrFail($payrollRun);

        $validated = $request->validate([
            'payroll_period_id' => ['required', 'uuid'],
        ]);
        $period = PayrollPeriod::query()->findOrFail($validated['payroll_period_id']);

        $correction = $service->createCorrectionRun($correctsRun, $period, $request->user());

        return response()->json(['data' => $this->present(PayrollRunSummary::fromModel($correction))], 201);
    }

    public function manualOverride(Request $request, School $school, string $payrollRun, PayrollRunAdministrationService $service): JsonResponse
    {
        $run = PayrollRun::query()->findOrFail($payrollRun);

        $validated = $request->validate([
            'employment_record_id' => ['required', 'uuid'],
            'component_amounts' => ['required', 'array', 'min:1'],
            'component_amounts.*' => ['required', 'string', 'regex:/^\d{1,12}(\.\d{1,2})?$/'],
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        $employmentRecord = EmploymentRecord::query()->findOrFail($validated['employment_record_id']);

        $service->recordManualOverride($run, $employmentRecord, $validated['component_amounts'], $validated['reason'], $request->user());

        return response()->json(['data' => ['recorded' => true]], 201);
    }

    public function correctionDelta(Request $request, School $school, string $payrollRun, PayrollRunAdministrationService $service): JsonResponse
    {
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

        $service->recordCorrectionDelta($run, $employmentRecord, $lines, $validated['reason'], $request->user());

        return response()->json(['data' => ['recorded' => true]], 201);
    }

    public function calculate(Request $request, School $school, string $payrollRun, PayrollRunAdministrationService $service): JsonResponse
    {
        $run = PayrollRun::query()->findOrFail($payrollRun);
        $outcome = $service->calculate($run, $request->user());

        return response()->json(['data' => [
            'resolvedCount' => count($outcome->resolvedEmploymentRecordIds),
            'unresolvedEmploymentRecordIds' => $outcome->unresolvedEmploymentRecordIds,
            'transitionedToCalculated' => $outcome->transitionedToCalculated,
        ]]);
    }

    public function approve(Request $request, School $school, string $payrollRun, PayrollRunAdministrationService $service): JsonResponse
    {
        $run = PayrollRun::query()->findOrFail($payrollRun);
        $run = $service->approve($run, $request->user());

        return response()->json(['data' => $this->present(PayrollRunSummary::fromModel($run))]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(PayrollRunSummary $summary): array
    {
        return [
            'id' => $summary->id,
            'payrollPeriodId' => $summary->payrollPeriodId,
            'runKind' => $summary->runKind,
            'correctsPayrollRunId' => $summary->correctsPayrollRunId,
            'status' => $summary->status,
            'preparedByUserId' => $summary->preparedByUserId,
            'approvedByUserId' => $summary->approvedByUserId,
            'postedByUserId' => $summary->postedByUserId,
            'approvedAt' => $summary->approvedAt?->toIso8601String(),
            'postedAt' => $summary->postedAt?->toIso8601String(),
            'resultsExpiredAt' => $summary->resultsExpiredAt?->toIso8601String(),
        ];
    }
}
