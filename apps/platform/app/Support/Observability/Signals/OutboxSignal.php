<?php

namespace App\Support\Observability\Signals;

final class OutboxSignal
{
    public function __construct(
        public readonly int $pending,
        public readonly int $oldestPendingAgeSeconds,
        public readonly int $unacknowledged,
        public readonly int $stale,
        public readonly int $oldestStaleAgeSeconds,
        public readonly int $failed,
    ) {}
}
