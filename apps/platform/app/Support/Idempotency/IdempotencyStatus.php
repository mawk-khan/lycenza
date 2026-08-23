<?php

namespace App\Support\Idempotency;

/**
 * Durable record state machine (section 11). "Expired" is included for
 * schema completeness and the database CHECK constraint's sake, but
 * App\Support\Idempotency\IdempotencyGuard treats an existing row whose
 * `expires_at` has passed as equivalent to "no record" rather than
 * eagerly writing this status -- see the guard's docblock.
 */
enum IdempotencyStatus: string
{
    case Processing = 'processing';
    case Completed = 'completed';
    case Failed = 'failed';
    case Expired = 'expired';
}
