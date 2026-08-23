<?php

namespace App\Support\Settings;

use InvalidArgumentException;

/**
 * The closed catalog of School settings that exist. Deliberately
 * minimal in Phase 0C -- no speculative business settings (section 30
 * explicitly forbids it). One real entry proves the mechanism.
 */
class SettingRegistry
{
    /** @var array<string, SettingDefinition> */
    private array $definitions = [];

    public function __construct()
    {
        $this->register(new SettingDefinition(
            key: 'communications.digest_frequency',
            type: 'string',
            default: 'daily',
            allowed: ['daily', 'weekly', 'off'],
        ));
    }

    public function register(SettingDefinition $definition): void
    {
        $this->definitions[$definition->key] = $definition;
    }

    public function get(string $key): SettingDefinition
    {
        return $this->definitions[$key]
            ?? throw new InvalidArgumentException("Unknown School setting key: {$key}");
    }

    public function has(string $key): bool
    {
        return isset($this->definitions[$key]);
    }
}
