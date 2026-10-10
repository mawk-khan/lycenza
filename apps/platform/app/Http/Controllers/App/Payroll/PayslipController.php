<?php

namespace App\Http\Controllers\App\Payroll;

use App\Domain\Payroll\Application\Exceptions\PayrollException;
use App\Domain\Payroll\Application\PayslipPresenter;
use App\Domain\Payroll\Application\PayslipReadService;
use App\Http\Controllers\Controller;
use App\Support\Auth\Mfa\SensitiveReadAssurance;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Phase 9.10 -- session-authenticated Inertia printable payslip view.
 * Delegates entirely to `PayslipReadService` (`payroll.compensation.sensitive.view`)
 * -- never a raw Eloquent query for anything that service already
 * authorizes/assembles, mirroring `PayrollRunController`'s identical
 * "same Application services as the JSON API, different transport"
 * shape. Renders on demand only -- no output is ever persisted or
 * emailed by this route.
 *
 * Unlike `PayrollRunController::show()` (which never lets a domain
 * "not found" exception reach this layer at all -- it resolves the
 * run itself via `PayrollRun::query()->findOrFail()`, so Laravel's own
 * `ModelNotFoundException` handling already renders a clean 404),
 * `PayslipReadService::render()` does its own run/result lookup and
 * eligibility check internally and throws a `PayrollException`
 * subtype for each expected failure -- these carry a real
 * `getStatusCode()`/`errorCode()` (rendered automatically for the
 * JSON API by bootstrap/app.php's `/api/*`-only exception envelope),
 * but that envelope deliberately does not apply to this `/app/*`
 * Inertia route. `abort()` is the standard Laravel translation for an
 * already-known HTTP status on this transport -- without it, a
 * bookmarked/typed URL for a since-reverted-to-draft or nonexistent
 * run/EmploymentRecord would 500 instead of showing the correct
 * 404/422.
 */
class PayslipController extends Controller
{
    use AuthorizesCapability;

    public function show(Request $request, TenantContext $context, string $payrollRun, string $employmentRecord, PayslipReadService $service, SensitiveReadAssurance $assurance): Response
    {
        $school = $context->requireSchool();
        // SR.4 (ADR 0071 §26.7): an administrative payslip is Highly Sensitive -- capability, then current MFA assurance.
        $this->authorizeCapability('payroll.compensation.sensitive.view', $school);
        $assurance->requireForPage($request);

        try {
            $payslip = $service->render($school, $payrollRun, $employmentRecord, $context->actor());
        } catch (PayrollException $e) {
            abort($e->getStatusCode(), $e->getMessage());
        }

        return Inertia::render('App/Payroll/Payslips/Show', [
            'payslip' => PayslipPresenter::present($payslip),
        ]);
    }
}
