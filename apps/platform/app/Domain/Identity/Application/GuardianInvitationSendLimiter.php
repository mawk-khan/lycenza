<?php

namespace App\Domain\Identity\Application;

use App\Domain\Identity\Application\Exceptions\InvitationSendRateLimitedException;
use Illuminate\Support\Facades\RateLimiter;

/**
 * ADR 0055 section 15 (Phase 0O.9A; fixes the unthrottled send/resend
 * finding): at most 10 invitation emails per minute per administrator and
 * 200 per day per School, for invite and resend together. Evaluated in
 * the application layer once the School is known -- never from
 * TenantContext inside a route throttle (CLAUDE.md rule 61), the same
 * shape as DomainCheckLimiter. Checked before anything else, so the
 * refusal is identical for every Guardian and address.
 */
final class GuardianInvitationSendLimiter
{
    public const PER_ACTOR_MINUTE = 10;

    public const PER_SCHOOL_DAY = 200;

    /**
     * @throws InvitationSendRateLimitedException
     */
    public function hit(string $schoolId, string $actorId): void
    {
        $keys = [
            ["guardian-invitation-send:actor:{$schoolId}:{$actorId}:m", self::PER_ACTOR_MINUTE, 60],
            ["guardian-invitation-send:school:{$schoolId}:d", self::PER_SCHOOL_DAY, 86400],
        ];

        foreach ($keys as [$key, $max]) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                throw new InvitationSendRateLimitedException;
            }
        }

        foreach ($keys as [$key, , $decay]) {
            RateLimiter::hit($key, $decay);
        }
    }
}
