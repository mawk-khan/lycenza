<?php

namespace App\Http\Controllers\App\Payroll;

use App\Domain\Payroll\Application\PayrollPostingAdministrationService;
use App\Domain\Payroll\Infrastructure\PayrollRun;
use App\Http\Controllers\Controller;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

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
    public function post(TenantContext $context, string $payrollRun, PayrollPostingAdministrationService $service): RedirectResponse
    {
        $context->requireSchool();
        $run = PayrollRun::query()->findOrFail($payrollRun);
        $service->post($run, $context->actor());

        return redirect("/app/payroll/runs/{$run->id}");
    }

    public function reverse(Request $request, TenantContext $context, string $payrollRun, PayrollPostingAdministrationService $service): RedirectResponse
    {
        $context->requireSchool();
        $run = PayrollRun::query()->findOrFail($payrollRun);

        $validated = $request->validate([
            'reason' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        $service->reverse($run, $context->actor(), $validated['reason'] ?? null);

        return redirect("/app/payroll/runs/{$run->id}");
    }
}
