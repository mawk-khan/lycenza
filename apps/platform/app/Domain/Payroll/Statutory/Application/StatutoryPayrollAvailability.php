<?php

namespace App\Domain\Payroll\Statutory\Application;

use App\Domain\Payroll\Statutory\Application\Exceptions\StatutoryPayrollUnavailableException;
use App\Support\Configuration\EnvironmentPolicy;

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
 * unsupported one, `EnvironmentPolicy::assertSupported()`). It reads no
 * request, School, user, flag, database row or other environment variable.
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
        return EnvironmentPolicy::isDevelopment();
    }

    /** @throws StatutoryPayrollUnavailableException */
    public static function assertAvailable(): void
    {
        if (! self::isAvailable()) {
            throw new StatutoryPayrollUnavailableException;
        }
    }
}
