<?php

namespace App\Domain\Communications\Application\Policy;

/**
 * Phase 5A.9 -- the closed, stable, machine-readable reason-code set
 * App\Domain\Communications\Application\Policy\CommunicationDeliveryTimingPolicyService::evaluate()
 * can produce. Mirrors CommunicationPolicyReason's own shape/purpose
 * for the channel-policy engine.
 *
 * Phase 5A.10 added `EmergencyQuietHoursBypass` -- the ONE new reason
 * this checkpoint introduces, so an operator can always distinguish "a
 * STANDARD message deferred" from "an EMERGENCY message bypassed quiet
 * hours because the School explicitly enabled it" purely from this
 * stable code, without free-form text.
 */
enum CommunicationTimingReason: string
{
    case AllowedNow = 'allowed_now';
    case QuietHours = 'quiet_hours';
    case EmergencyQuietHoursBypass = 'emergency_quiet_hours_bypass';
}
