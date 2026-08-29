<?php

namespace App\Domain\Payroll\Http\Controllers;

use App\Domain\Payroll\Application\PayrollPostingAdministrationService;
use App\Domain\Payroll\Application\PayrollRunResultDetail;
use App\Domain\Payroll\Application\PayrollRunResultLineDetail;
use App\Domain\Payroll\Application\PayrollRunResultReadService;
use App\Domain\Payroll\Infrastructure\PayrollRun;
use App\Domain\Payroll\Infrastructure\PayrollRunPosting;
use App\Http\Controllers\Controller;
use App\Models\School;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Phase 9.8 -- thin HTTP transport over `PayrollRunResultReadService`
 * (`payroll.compensation.sensitive.view` -- the Highly Sensitive
 * result figures) and `PayrollPostingAdministrationService`
 * (`payroll.runs.post`/`.reverse`, each already checked by that
 * class's own per-method capability -- see its docblock).
 * `{payrollRun}` resolved via `PayrollRun::query()->findOrFail()` --
 * see `SalaryComponentController`'s docblock for why this is safe.
 * Neither `post()` nor `reverse()` carries the `idempotent` middleware,
 * mirroring `JournalEntryController`'s identical reasoning: both
 * already have their own structural at-most-once guarantee
 * (`PayrollPostingService::post()`'s row lock closes the race window
 * entirely -- proven by Checkpoint 9.5's real two-process concurrency
 * test -- and `reverse()` rejects a repeat with
 * `PAYROLL_RUN_ALREADY_REVERSED`, 409).
 */
class PayrollRunPostingController extends Controller
{
    public function results(School $school, string $payrollRun, PayrollRunResultReadService $service): JsonResponse
    {
        $results = $service->listResults($school, $payrollRun, request()->user());

        return response()->json([
            'data' => array_map(fn (PayrollRunResultDetail $r) => $this->presentResult($r), $results),
        ]);
    }

    public function post(Request $request, School $school, string $payrollRun, PayrollPostingAdministrationService $service): JsonResponse
    {
        $run = PayrollRun::query()->findOrFail($payrollRun);
        $posting = $service->post($run, $request->user());

        return response()->json(['data' => $this->presentPosting($posting)], 201);
    }

    public function reverse(Request $request, School $school, string $payrollRun, PayrollPostingAdministrationService $service): JsonResponse
    {
        $run = PayrollRun::query()->findOrFail($payrollRun);

        $validated = $request->validate([
            'reason' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        $posting = $service->reverse($run, $request->user(), $validated['reason'] ?? null);

        return response()->json(['data' => $this->presentPosting($posting)], 201);
    }

    /**
     * @return array<string, mixed>
     */
    private function presentResult(PayrollRunResultDetail $result): array
    {
        return [
            'employmentRecordId' => $result->employmentRecordId,
            'employeeId' => $result->employeeId,
            'grossAmount' => $result->grossAmount,
            'totalDeductions' => $result->totalDeductions,
            'netAmount' => $result->netAmount,
            'lines' => array_map(fn (PayrollRunResultLineDetail $l) => [
                'salaryComponentId' => $l->salaryComponentId,
                'amount' => $l->amount,
                'effect' => $l->effect,
                'resolvedLedgerAccountId' => $l->resolvedLedgerAccountId,
            ], $result->lines),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentPosting(PayrollRunPosting $posting): array
    {
        return [
            'id' => $posting->id,
            'payrollRunId' => $posting->payroll_run_id,
            'journalEntryId' => $posting->journal_entry_id,
            'postingKind' => $posting->posting_kind,
            'reversalOfPayrollRunPostingId' => $posting->reversal_of_payroll_run_posting_id,
            'reason' => $posting->reason,
        ];
    }
}
