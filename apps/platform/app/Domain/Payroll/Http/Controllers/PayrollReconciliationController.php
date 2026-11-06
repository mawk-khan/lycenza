<?php

namespace App\Domain\Payroll\Http\Controllers;

use App\Domain\Payroll\Application\PayrollReversalReconciliationReadService;
use App\Domain\Payroll\Application\PayrollReversalReconciliationService;
use App\Http\Controllers\Controller;
use App\Models\School;
use App\Support\Auth\Mfa\FreshMfaRequirement;
use App\Support\Auth\Mfa\SensitiveReadAssurance;
use App\Support\Authorization\CapabilityResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * HR.R1-C6 (PAY-18, OD-HR-23): the API transport of the reconciliation workspace.
 * A duplicate review changes nothing and answers 409: the unique review row is the
 * guarantee, it moves no money, and a retried request needs a new code anyway, so
 * no `idempotent` middleware (rule 29 considered).
 */
class PayrollReconciliationController extends Controller
{
    public function index(Request $request, School $school, PayrollReversalReconciliationReadService $reads): JsonResponse
    {
        $validated = $request->validate(['state' => ['sometimes', Rule::in(PayrollReversalReconciliationReadService::STATES)], 'page' => ['sometimes', 'integer', 'min:1']]);
        $page = $reads->page($school, $request->user(), $validated['state'] ?? 'open', (int) ($validated['page'] ?? 1));

        return response()->json([
            'data' => $page->items(),
            'meta' => ['total' => $page->total(), 'page' => $page->currentPage(), 'lastPage' => $page->lastPage()],
        ]);
    }

    public function show(Request $request, School $school, string $payrollReconciliation, PayrollReversalReconciliationReadService $reads, CapabilityResolver $capabilities, SensitiveReadAssurance $assurance): JsonResponse
    {
        $maySeeDetails = $capabilities->canInSchool($request->user(), PayrollReversalReconciliationService::SENSITIVE_VIEW, $school) && $assurance->holds($request);

        return response()->json(['data' => $reads->show($school, $request->user(), $payrollReconciliation, $maySeeDetails, max(1, (int) $request->query('independence_page', '1')))]);
    }

    public function review(Request $request, School $school, string $payrollReconciliation, PayrollReversalReconciliationService $reconciliations, FreshMfaRequirement $mfa): JsonResponse
    {
        $validated = $request->validate(['review_version' => ['required', 'string', 'size:64'], 'accepted' => ['required', 'boolean']]);
        $proof = $mfa->requireForAction($request, $request->user());
        $review = $reconciliations->review($school, $payrollReconciliation, $request->user(), $proof, $validated['review_version'], (bool) $validated['accepted']);

        return response()->json(['data' => ['id' => $review->id, 'reconciliationId' => $review->reconciliation_id, 'statementCode' => $review->statement_code, 'reviewedAt' => $review->created_at->toIso8601String()]], 201);
    }
}
