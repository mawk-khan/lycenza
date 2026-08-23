<?php

namespace App\Domain\Communications\Application\Policy;

use Illuminate\Support\Carbon;

/**
 * Phase 5A.9 -- the structured output of
 * App\Domain\Communications\Application\Policy\CommunicationDeliveryTimingPolicyService::evaluate().
 * `availableAt` is always a UTC instant, never a School-local one --
 * the ONE canonical value written to
 * App\Domain\Communications\Infrastructure\CommunicationDelivery::next_attempt_at,
 * which already stores every other delivery timing value in UTC.
 *
 * Phase 5A.10 added `emergencyBypass()` -- `shouldDefer = false`,
 * identical in effect to `sendNow()` (every existing caller that only
 * branches on `shouldDefer` continues to work unchanged), but with a
 * distinct `reason` so the bypass is auditable/traceable separately
 * from an ordinary "outside quiet hours" send.
 */
final class CommunicationTimingDecision
{
    private function __construct(
        public readonly bool $shouldDefer,
        public readonly ?Carbon $availableAt,
        public readonly CommunicationTimingReason $reason,
    ) {}

    public static function sendNow(): self
    {
        return new self(false, null, CommunicationTimingReason::AllowedNow);
    }

    public static function deferUntil(Carbon $availableAt): self
    {
        return new self(true, $availableAt, CommunicationTimingReason::QuietHours);
    }

    public static function emergencyBypass(): self
    {
        return new self(false, null, CommunicationTimingReason::EmergencyQuietHoursBypass);
    }
}
