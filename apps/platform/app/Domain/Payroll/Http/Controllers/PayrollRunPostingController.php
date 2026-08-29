<?php

namespace App\Domain\Payroll\Http\Controllers;

use App\Domain\Payroll\Application\PayrollPostingAdministrationService;
use App\Domain\Payroll\Application\PayrollRunPostingSummary;
use App\Domain\Payroll\Application\PayrollRunResultDetail;
use App\Domain\Payroll\Application\PayrollRunResultLineDetail;
use App\Domain\Payroll\Application\PayrollRunResultReadService;
use App\Domain\Payroll\Infrastructure\PayrollRun;
use App\Http\Controllers\Controller;
use App\Models\School;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Phase 9.8 (idempotency corrected) -- thin HTTP transport over
 * `PayrollRunResultReadService` (`payroll.compensation.sensitive.view`
 * -- the Highly Sensitive result figures) and
 * `PayrollPostingAdministrationService` (`payroll.runs.post`/`.reverse`,
 * each already checked by that class's own per-method capability --
 * see its docblock). `{payrollRun}` resolved via
 * `PayrollRun::query()->findOrFail()` -- see
 * `SalaryComponentController`'s docblock for why this is safe.
 *
 * `post()`/`reverse()` both carry the `idempotent` route middleware
 * (routes/api.php, placed AFTER `capability:...` so authorization is
 * re-evaluated before any replay is ever considered). Row locks/
 * conditional transitions/unique constraints remain the authoritative
 * structural guarantee against a genuine double-post or double-reversal;
 * `Idempotency-Key` restores the HTTP retry/replay CONTRACT on top of
 * that -- without it, a client retrying a request whose successful
 * response was lost would receive an invalid-transition/already-
 * reversed error instead of the original success. `$request->attributes->get('idempotency_record')`
 * is non-null only for a NEW claim (a replay short-circuits inside
 * `EnsureIdempotent` and never reaches this controller at all) and is
 * passed straight through so `PayrollPostingService` can complete it
 * INSIDE its own existing transaction (`completeWithin()`) -- this
 * controller never opens a transaction of its own.
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
        $record = $request->attributes->get('idempotency_record');
        $posting = $service->post($run, $request->user(), $record);

        return response()->json(['data' => PayrollRunPostingSummary::fromModel($posting)->toArray()], 201);
    }

    public function reverse(Request $request, School $school, string $payrollRun, PayrollPostingAdministrationService $service): JsonResponse
    {
        $run = PayrollRun::query()->findOrFail($payrollRun);

        $validated = $request->validate([
            'reason' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        $record = $request->attributes->get('idempotency_record');
        $posting = $service->reverse($run, $request->user(), $validated['reason'] ?? null, $record);

        return response()->json(['data' => PayrollRunPostingSummary::fromModel($posting)->toArray()], 201);
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
}
