<?php

namespace App\Support\Email;

use Closure;
use Illuminate\Contracts\Config\Repository;

/**
 * ADR 0055 section 10: 30 s, 2 min, 10 min, 30 min, 2 h with +/-20 %
 * jitter; at most 6 attempts. The random source is injectable so tests are
 * deterministic (bind a RetrySchedule with a fixed source).
 */
final class RetrySchedule
{
    /** @var Closure(): float a value in [0, 1] */
    private Closure $random;

    public function __construct(private readonly Repository $config, ?Closure $random = null)
    {
        $this->random = $random ?? fn (): float => mt_rand() / mt_getrandmax();
    }

    public function maxAttempts(): int
    {
        return (int) $this->config->get('email.submission.max_attempts');
    }

    /** Seconds to wait after the given (1-based) counted attempt failed transiently. */
    public function delayAfter(int $attempt): int
    {
        /** @var list<int> $schedule */
        $schedule = $this->config->get('email.submission.backoff_seconds');
        $base = $schedule[min(max(0, $attempt - 1), count($schedule) - 1)];
        $jitter = (float) $this->config->get('email.submission.jitter');
        $factor = 1 + $jitter * (2 * ($this->random)() - 1);

        return max(1, (int) round($base * $factor));
    }
}
