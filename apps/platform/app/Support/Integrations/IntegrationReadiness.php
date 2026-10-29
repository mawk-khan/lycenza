<?php

namespace App\Support\Integrations;

/**
 * ORG.12G (ADR 0080 §12): one provider's answer for one integration family.
 *
 * A status and one short, code-owned explanation -- nothing else. It never
 * carries a credential, secret, key id, hash, URL, host, address, recipient,
 * provider message, error text, record id or count of people
 * (IntegrationHubArchitectureTest). NOT_APPLICABLE is decided from module
 * entitlement and ERROR by the Hub when a provider fails; neither comes from
 * a provider.
 */
final readonly class IntegrationReadiness
{
    public const int MAX_EXPLANATION_LENGTH = 200;

    public function __construct(
        public IntegrationStatus $status,
        public string $explanation,
    ) {
        if (in_array($status, [IntegrationStatus::NOT_APPLICABLE, IntegrationStatus::ERROR], true)) {
            throw new \InvalidArgumentException('NOT_APPLICABLE and ERROR are decided by the Hub, never by a readiness provider.');
        }
        if (trim($explanation) === '' || mb_strlen($explanation) > self::MAX_EXPLANATION_LENGTH) {
            throw new \InvalidArgumentException('A readiness explanation is required and stays short.');
        }
    }
}
