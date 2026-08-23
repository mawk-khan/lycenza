<?php

namespace App\Domain\Communications\Application\Policy;

/**
 * Phase 5A.9 -- the closed, stable, machine-readable reason-code set
 * App\Domain\Communications\Application\Policy\CommunicationDeliveryTimingPolicyService::evaluate()
 * can produce. Mirrors CommunicationPolicyReason's own shape/purpose
 * for the channel-policy engine. Deliberately has no "bypass"/
 * "emergency" case -- brief §6/§40: an emergency bypass is a future,
 * separately-authorized concept, not a reason this engine can express
 * yet.
 */
enum CommunicationTimingReason: string
{
    case AllowedNow = 'allowed_now';
    case QuietHours = 'quiet_hours';
}
