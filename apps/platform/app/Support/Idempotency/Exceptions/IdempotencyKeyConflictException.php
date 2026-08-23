<?php

namespace App\Support\Idempotency\Exceptions;

/**
 * Section 14: the same (School, actor, route/action, key) scope was
 * reused with a DIFFERENT request fingerprint. The second, differing
 * request is always rejected -- it is never silently executed, and the
 * original stored result is never overwritten.
 */
class IdempotencyKeyConflictException extends IdempotencyException
{
    public function __construct()
    {
        parent::__construct(409, 'IDEMPOTENCY_KEY_CONFLICT', 'This Idempotency-Key was already used with a different request.');
    }
}
