<?php

namespace App\Support\Observability\Signals;

use Carbon\CarbonInterface;

final class TaskHeartbeat
{
    public function __construct(
        public readonly string $name,
        public readonly ?CarbonInterface $lastSuccessAt,
        public readonly ?string $lastErrorCode,
        public readonly int $staleAfterSeconds,
        public readonly int $criticalAfterSeconds,
        public readonly bool $stale,
        public readonly bool $critical,
    ) {}
}
