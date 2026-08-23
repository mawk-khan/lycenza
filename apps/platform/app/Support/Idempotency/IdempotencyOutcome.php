<?php

namespace App\Support\Idempotency;

/**
 * The five outcomes App\Support\Idempotency\IdempotencyGuard::claim()
 * can produce for one request (section 27/29) -- used for both
 * structured logging and the metrics counters
 * (App\Support\Idempotency\IdempotencyMetrics), never for HTTP control
 * flow directly.
 */
enum IdempotencyOutcome: string
{
    case New = 'new';
    case Replay = 'replay';
    case Conflict = 'conflict';
    case InProgress = 'in_progress';
    case Failed = 'failed';
}
