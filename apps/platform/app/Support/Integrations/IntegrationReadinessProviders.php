<?php

namespace App\Support\Integrations;

/**
 * ORG.12G (ADR 0080 §12): the closed set of integration readiness providers,
 * wired in PlatformServiceProvider. Every AVAILABLE catalogue family has
 * exactly one provider and no NOT_AVAILABLE family has one
 * (OrganizationIntegrationCatalogueTest).
 */
class IntegrationReadinessProviders
{
    /** @var array<string, IntegrationReadinessProvider> */
    private array $byKey = [];

    public function register(IntegrationReadinessProvider $provider): void
    {
        foreach ($provider->keys() as $key) {
            if (isset($this->byKey[$key])) {
                throw new \LogicException("Integration {$key} has two readiness providers.");
            }
            $this->byKey[$key] = $provider;
        }
    }

    public function for(string $key): IntegrationReadinessProvider
    {
        return $this->byKey[$key] ?? throw new \LogicException("Integration {$key} has no readiness provider.");
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_keys($this->byKey);
    }
}
