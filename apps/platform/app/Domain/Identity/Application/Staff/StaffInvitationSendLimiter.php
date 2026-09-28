<?php

namespace App\Domain\Identity\Application\Staff;

use Illuminate\Support\Facades\RateLimiter;

/**
 * Phase 0O.12B (ADR 0059 section 19): at most 10 staff invitation emails per
 * minute per administrator and 200 per day per School, for invite and
 * resend together -- the GuardianInvitationSendLimiter shape, separate keys.
 * Evaluated in the application layer once the School is known (never from
 * TenantContext inside a route throttle, CLAUDE.md rule 61); never keyed by
 * IP. Checked before anything else, so the refusal is identical for every
 * address.
 */
final class StaffInvitationSendLimiter
{
    public const PER_ACTOR_MINUTE = 10;

    public const PER_SCHOOL_DAY = 200;

    /**
     * @throws StaffAccountException
     */
    public function hit(string $schoolId, string $actorId): void
    {
        $keys = [
            ["staff-invitation-send:actor:{$schoolId}:{$actorId}:m", self::PER_ACTOR_MINUTE, 60],
            ["staff-invitation-send:school:{$schoolId}:d", self::PER_SCHOOL_DAY, 86400],
        ];

        foreach ($keys as [$key, $max]) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                throw new StaffAccountException('rate_limited');
            }
        }

        foreach ($keys as [$key, , $decay]) {
            RateLimiter::hit($key, $decay);
        }
    }
}
