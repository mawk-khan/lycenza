<?php

namespace App\Domain\Payroll\Statutory\Application;

use App\Domain\Payroll\Statutory\Application\Exceptions\StatutoryPayrollUnavailableException;
use App\Support\Configuration\EnvironmentPolicy;
use Illuminate\Support\Env;

/**
 * HR-PG1/PG2 (ADR 0036 amendment, rule 153): statutory Payroll -- PF, ESI, PT,
 * LWF, TDS, statutory identifiers and configuration, calculation, ledger posting
 * and reversal, statutory reads and exports -- is available ONLY in the
 * development environments (`local`, `testing`), for synthetic development and
 * automated tests. Staging and production refuse it, whatever capability,
 * role, School entitlement or configuration exists: lifting this needs a new
 * Architecture decision, the legal/security release decisions, a code change,
 * tests and publication.
 *
 * Fail closed by construction: this is an ALLOWLIST
 * (`EnvironmentPolicy::isDevelopment()`), never "not hardened", so an unset,
 * unknown or malformed environment is unavailable (and boot already refuses an
 * unsupported one, `EnvironmentPolicy::assertSupported()`).
 *
 * TWO environments must both be development (HR-PG1/PG2-Q1-C, finding F1):
 *   - the application environment (`app()->environment()`), and
 *   - the deployment's real `APP_ENV` (`Env::get('APP_ENV')`).
 * On a console command Laravel takes `--env=<value>` straight into
 * `$app['env']` (`EnvironmentDetector::detectConsoleEnvironment()`), so
 * `artisan <command> --env=local` on a production host would satisfy the first
 * alone. `--env` never changes the real `APP_ENV`: the hardened images set it
 * as a process variable (`app.Dockerfile` `ENV APP_ENV=production`, staging's
 * `APP_ENV=staging`) and Laravel's environment repository is immutable, so no
 * `.env.<name>` file can replace it. This is the precedent
 * `AppServiceProvider::boot()` already uses for destructive database commands.
 * An unset `APP_ENV` (for example a cached configuration with no process
 * variable) is unavailable: fail closed, never a default.
 *
 * It reads no request, School, user, flag, database row, or any environment
 * variable other than `APP_ENV`.
 *
 * Independent boundaries use it: the `statutory-payroll-development-only`
 * route middleware, on every statutory route and declared by every statutory
 * controller (before `RequireSchoolContext` and the School-scoped binding,
 * capability, MFA and idempotency after it), and `assertAvailable()` as the
 * first statement of every public operation of the statutory application
 * services, so a direct, command, worker or console invocation is refused too.
 */
final class StatutoryPayrollAvailability
{
    /** The only environments where statutory Payroll runs (synthetic data only). */
    public const array ENVIRONMENTS = EnvironmentPolicy::DEVELOPMENT;

    public static function isAvailable(): bool
    {
        return self::isAvailableFor(EnvironmentPolicy::current(), Env::get('APP_ENV'));
    }

    /**
     * The decision itself, over both environments: available only when the
     * application environment AND the deployment's real `APP_ENV` are each a
     * development environment. Anything else -- a hardened, unset, unknown,
     * non-string or differently-cased value on either side -- is unavailable.
     */
    public static function isAvailableFor(string $applicationEnvironment, mixed $deploymentEnvironment): bool
    {
        return EnvironmentPolicy::isDevelopment($applicationEnvironment)
            && is_string($deploymentEnvironment)
            && EnvironmentPolicy::isDevelopment($deploymentEnvironment);
    }

    /** @throws StatutoryPayrollUnavailableException */
    public static function assertAvailable(): void
    {
        if (! self::isAvailable()) {
            throw new StatutoryPayrollUnavailableException;
        }
    }
}
