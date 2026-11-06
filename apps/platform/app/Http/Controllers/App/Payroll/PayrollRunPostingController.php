<?php

namespace App\Http\Controllers\App\Payroll;

use App\Domain\Payroll\Application\Exceptions\PayrollException;
use App\Domain\Payroll\Application\PayrollPostingAdministrationService;
use App\Domain\Payroll\Application\PostingAttestation;
use App\Domain\Payroll\Infrastructure\PayrollRun;
use App\Http\Controllers\Controller;
use App\Support\Auth\Mfa\FreshMfaRequirement;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Phase 9.9 -- session-authenticated Inertia actions for posting and
 * reversing a Payroll run. Delegates to the SAME
 * `PayrollPostingAdministrationService::post()`/`reverse()` the JSON
 * API controller calls, but WITHOUT the `idempotent` route middleware
 * (see the detailed rationale in `routes/web.php`'s Payroll section: the
 * existing `EnsureIdempotent`/`IdempotencyGuard` primitive was built
 * exclusively for `JsonResponse`-shaped API responses and would replay
 * a broken, target-less redirect for these routes as-is). `$idempotencyRecord`
 * is therefore always `null` here -- the crash-safe `completeWithin()`
 * path those methods support is real and exercised on the API
 * transport (Phase 9.8), simply not reachable from this one yet.
 */
class PayrollRunPostingController extends Controller
{
    use AuthorizesCapability;

    public function post(Request $request, TenantContext $context, string $payrollRun, PayrollPostingAdministrationService $service, FreshMfaRequirement $mfa): RedirectResponse
    {
        $school = $context->requireSchool();
        $run = PayrollRun::query()->findOrFail($payrollRun);
        // SR.4 (ADR 0071 §26.7): capability first, then a fresh code, outside the posting transaction.
        $this->authorizeCapability('payroll.runs.post', $school);
        // HR.R1-C4 (PAY-16): only ever what the poster explicitly accepted, re-checked in the posting transaction.
        $validated = $request->validate(['attestation_version' => ['sometimes', 'string', 'size:64'], 'attestation_accepted' => ['required_with:attestation_version', 'boolean']]);
        $attestation = isset($validated['attestation_version']) ? new PostingAttestation($validated['attestation_version'], (bool) $validated['attestation_accepted']) : null;
        $proof = $mfa->requireForAction($request, $context->actor());
        try {
            $service->post($run, $context->actor(), $proof, null, $attestation);
        } catch (PayrollException $e) {
            // A refusal at posting is shown beside the attestation by its fixed message; the run stays approved.
            throw ValidationException::withMessages(['payroll' => $e->getMessage()]);
        }

        return redirect("/app/payroll/runs/{$run->id}");
    }

    public function reverse(Request $request, TenantContext $context, string $payrollRun, PayrollPostingAdministrationService $service, FreshMfaRequirement $mfa): RedirectResponse
    {
        $school = $context->requireSchool();
        $run = PayrollRun::query()->findOrFail($payrollRun);
        $this->authorizeCapability('payroll.runs.reverse', $school);

        $validated = $request->validate([
            'reason' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);
        $proof = $mfa->requireForAction($request, $context->actor());

        $service->reverse($run, $context->actor(), $proof, $validated['reason'] ?? null);

        return redirect("/app/payroll/runs/{$run->id}");
    }
}
