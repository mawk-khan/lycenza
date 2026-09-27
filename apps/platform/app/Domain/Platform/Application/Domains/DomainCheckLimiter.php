<?php

namespace App\Domain\Platform\Application\Domains;

use Illuminate\Support\Facades\RateLimiter;

/**
 * ADR 0054 section 10.5: manual "check now" is never an open DNS query
 * engine -- 6 per minute and 30 per hour per School, and 3 per minute per
 * domain. Keyed by the School established by `school-context` (evaluated in
 * the action, after the School is known -- never from TenantContext inside
 * a route throttle, CLAUDE.md rule 61). Scheduled checks are bounded
 * separately (`domains.checks_per_run`).
 */
final class DomainCheckLimiter
{
    public const PER_SCHOOL_MINUTE = 6;

    public const PER_SCHOOL_HOUR = 30;

    public const PER_DOMAIN_MINUTE = 3;

    /**
     * @throws SchoolDomainException rate_limited
     */
    public function hit(string $schoolId, string $domainId): void
    {
        $keys = [
            ["domain-checks:school:{$schoolId}:m", self::PER_SCHOOL_MINUTE, 60],
            ["domain-checks:school:{$schoolId}:h", self::PER_SCHOOL_HOUR, 3600],
            ["domain-checks:domain:{$domainId}:m", self::PER_DOMAIN_MINUTE, 60],
        ];

        foreach ($keys as [$key, $max]) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                throw new SchoolDomainException('rate_limited');
            }
        }

        foreach ($keys as [$key, , $decay]) {
            RateLimiter::hit($key, $decay);
        }
    }
}
