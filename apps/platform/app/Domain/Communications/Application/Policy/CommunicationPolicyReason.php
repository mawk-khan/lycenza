<?php

namespace App\Domain\Communications\Application\Policy;

/**
 * Phase 5A.5 §39 -- the closed, stable, machine-readable reason-code
 * set the policy engine can produce. Deliberately does NOT include any
 * provider/transport-failure reason (`email_transport_unavailable`
 * etc, App\Domain\Communications\Application\Channels\CommunicationDeliveryResult) --
 * those belong to a real delivery ATTEMPT, never to a policy decision
 * made before a delivery was even created (brief §21: suppression is
 * not failure).
 */
enum CommunicationPolicyReason: string
{
    case Allowed = 'allowed';
    case CanonicalInApp = 'canonical_in_app';
    case RecipientPreferenceDisabled = 'recipient_preference_disabled';
    case SchoolOptionalChannelDisabled = 'school_optional_channel_disabled';
    case SchoolRequiredChannelDisabled = 'school_required_channel_disabled';
    case RecipientIneligible = 'recipient_ineligible';
    case UnsupportedChannel = 'unsupported_channel';
}
