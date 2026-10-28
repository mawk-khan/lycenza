<?php

namespace App\Support\Setup;

/**
 * ORG.12A (ADR 0080 §8): the closed set of readiness providers, wired in
 * PlatformServiceProvider (like the Data Health checks). Each setup key has
 * exactly one provider; OrganizationSetupRegistryTest keeps the provider
 * keys equal to the catalogue keys.
 */
class SetupReadinessProviders
{
    /** @var array<string, SetupReadinessProvider> */
    private array $byKey = [];

    public function register(SetupReadinessProvider $provider): void
    {
        foreach ($provider->keys() as $key) {
            if (isset($this->byKey[$key])) {
                throw new \LogicException("Setup key {$key} has two readiness providers.");
            }
            $this->byKey[$key] = $provider;
        }
    }

    public function for(string $key): SetupReadinessProvider
    {
        return $this->byKey[$key] ?? throw new \LogicException("Setup key {$key} has no readiness provider.");
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_keys($this->byKey);
    }
}
