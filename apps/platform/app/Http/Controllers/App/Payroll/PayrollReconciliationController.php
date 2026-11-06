<?php

namespace App\Http\Controllers\App\Payroll;

use App\Domain\Payroll\Application\Exceptions\PayrollException;
use App\Domain\Payroll\Application\PayrollReversalReconciliationReadService;
use App\Domain\Payroll\Application\PayrollReversalReconciliationService;
use App\Http\Controllers\Controller;
use App\Support\Auth\Mfa\FreshMfaRequirement;
use App\Support\Auth\Mfa\SensitiveReadAssurance;
use App\Support\Authorization\CapabilityResolver;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * HR.R1-C6 (PAY-18, OD-HR-23): the reconciliation workspace, in the Payroll
 * area. Thin: the services decide, and re-read authority under the run lock.
 */
class PayrollReconciliationController extends Controller
{
    public function index(Request $request, TenantContext $context, PayrollReversalReconciliationReadService $reads): Response
    {
        $validated = $request->validate(['state' => ['sometimes', Rule::in(PayrollReversalReconciliationReadService::STATES)], 'page' => ['sometimes', 'integer', 'min:1']]);
        $state = $validated['state'] ?? 'open';

        return Inertia::render('App/Payroll/Reconciliations/Index', [
            'state' => $state,
            'reconciliations' => $reads->page($context->requireSchool(), $context->actor(), $state, (int) ($validated['page'] ?? 1))->withQueryString(),
        ]);
    }

    public function show(Request $request, TenantContext $context, PayrollReversalReconciliationReadService $reads, CapabilityResolver $capabilities, SensitiveReadAssurance $assurance, FreshMfaRequirement $mfa, string $payrollReconciliation): Response
    {
        $school = $context->requireSchool();
        $maySeeDetails = $capabilities->canInSchool($context->actor(), PayrollReversalReconciliationService::SENSITIVE_VIEW, $school) && $assurance->holds($request);

        return Inertia::render('App/Payroll/Reconciliations/Show', [
            'reconciliation' => $reads->show($school, $context->actor(), $payrollReconciliation, $maySeeDetails, max(1, (int) $request->query('independence_page', '1'))),
            'hasMfaFactor' => $mfa->hasActiveFactor($context->actor()),
        ]);
    }

    public function review(Request $request, TenantContext $context, PayrollReversalReconciliationService $reconciliations, FreshMfaRequirement $mfa, string $payrollReconciliation): RedirectResponse
    {
        $validated = $request->validate(['review_version' => ['required', 'string', 'size:64'], 'accepted' => ['required', 'boolean']]);
        // The route already required both capabilities; a fresh code now, outside the review transaction.
        $proof = $mfa->requireForAction($request, $context->actor());
        try {
            $reconciliations->review($context->requireSchool(), $payrollReconciliation, $context->actor(), $proof, $validated['review_version'], (bool) $validated['accepted']);
        } catch (PayrollException $e) {
            throw ValidationException::withMessages(['payroll' => $e->getMessage()]);
        }

        return redirect("/app/payroll/reconciliations/{$payrollReconciliation}")->with('status', 'Reconciliation reviewed.');
    }
}
