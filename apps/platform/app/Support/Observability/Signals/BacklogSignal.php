<?php

namespace App\Support\Observability\Signals;

final class BacklogSignal
{
    /**
     * @param  array<string, int>  $states  unfinished items per state
     */
    public function __construct(
        public readonly string $source,
        public readonly array $states,
        public readonly int $overdue,
        public readonly int $oldestOverdueAgeSeconds,
    ) {}
}
