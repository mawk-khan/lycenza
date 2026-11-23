<?php

namespace App\Http\Middleware;

use App\Domain\Payroll\Statutory\Application\Exceptions\StatutoryPayrollUnavailableException;
use App\Domain\Payroll\Statutory\Application\StatutoryPayrollAvailability;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * HR-PG1/PG2 (rule 153): the `statutory-payroll-development-only` route
 * middleware. Pinned in the middleware priority list immediately before
 * `RequireSchoolContext` (bootstrap/app.php), so outside `local`/`testing` it
 * refuses before `RequireSchoolContext` and every School-scoped step after it
 * -- route-model binding, membership, `capability:`, MFA, idempotency and the
 * controller -- (only identity, session and School-resolution middleware run
 * first): no statutory, Employee or Payroll-run row is
 * read, and the refusal is the same for a valid, unknown or another School's
 * identifier.
 *
 * The refusal is the fixed 403 with `Cache-Control: private, no-store`: on
 * /api the domain refusal (`StatutoryPayrollUnavailableException`) through the
 * standard /api JSON envelope, which carries the exception's headers; on every
 * other request -- a browser page, an Inertia visit, or a web route called
 * with `Accept: application/json` (the Statutory page's own employee search) --
 * an HTTP 403 with the same message and headers. (A domain exception on a
 * non-/api JSON request would otherwise render as a 500.)
 *
 * Additive only: capability, School, MFA, RLS and the service-level
 * `StatutoryPayrollAvailability::assertAvailable()` stay in force.
 */
class EnsureStatutoryPayrollDevelopmentOnly
{
    public function handle(Request $request, Closure $next): Response
    {
        if (StatutoryPayrollAvailability::isAvailable()) {
            return $next($request);
        }

        $refusal = new StatutoryPayrollUnavailableException;
        if ($request->is('api/*')) {
            throw $refusal;
        }

        throw new AccessDeniedHttpException(StatutoryPayrollUnavailableException::MESSAGE, null, 0, $refusal->getHeaders());
    }
}
