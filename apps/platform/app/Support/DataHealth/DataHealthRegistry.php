<?php

namespace App\Support\DataHealth;

/**
 * The closed list of Data Health checks (wired in PlatformServiceProvider,
 * like the audience and event-consumer registries). A check exists only
 * if it is registered here; codes are unique.
 */
class DataHealthRegistry
{
    /** @var array<string, DataHealthCheck> */
    private array $checks = [];

    public function register(DataHealthCheck $check): void
    {
        $code = $check->definition()->code;
        if (isset($this->checks[$code])) {
            throw new \LogicException("Data Health check {$code} is registered twice.");
        }
        $this->checks[$code] = $check;
    }

    /** @return array<string, DataHealthCheck> */
    public function all(): array
    {
        return $this->checks;
    }

    public function find(string $code): ?DataHealthCheck
    {
        return $this->checks[$code] ?? null;
    }
}
