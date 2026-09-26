<?php

namespace App\Support\Observability\Signals;

use Carbon\CarbonInterface;

final class QueueSignal
{
    public function __construct(
        public readonly string $queue,
        public readonly ?int $pending,
        public readonly ?int $delayed,
        public readonly ?int $reserved,
        public readonly ?int $oldestPendingAgeSeconds,
        public readonly ?CarbonInterface $heartbeatAt,
        public readonly bool $heartbeatStale,
        public readonly ?string $lastErrorCode,
    ) {}
}
