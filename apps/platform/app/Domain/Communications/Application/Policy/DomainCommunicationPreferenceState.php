<?php

namespace App\Domain\Communications\Application\Policy;

use App\Domain\Communications\Domain\CommunicationConsentStatus;

/**
 * Phase 5D.2 §36 -- the compact read model UI/services consume instead
 * of querying `CommunicationDomainPreference`/`CommunicationDomainConsentEvent`
 * directly. `preferenceEnabled: null` and `consentStatus: null` both
 * mean "no explicit record exists" -- rendered as "default"/"unknown"
 * respectively, never coerced into a false boolean.
 */
final class DomainCommunicationPreferenceState
{
    public function __construct(
        public readonly string $channel,
        public readonly ?bool $preferenceEnabled,
        public readonly ?CommunicationConsentStatus $consentStatus,
        public readonly bool $endpointAvailable,
    ) {}
}
