<?php

namespace App\Support\Idempotency\Exceptions;

/**
 * Section 15: a duplicate request arrived while the FIRST request
 * still owns the key (still 'processing' and within the in-flight
 * timeout, App\Support\Idempotency\IdempotencyGuard). Deliberately a
 * hard, immediate 409 rather than an unbounded wait inside an HTTP
 * worker -- the client is expected to retry with the same key after a
 * short backoff, at which point it will either see the completed
 * replay or (if the original owner crashed) reclaim the key itself
 * once the in-flight timeout elapses.
 */
class IdempotencyRequestInProgressException extends IdempotencyException
{
    public function __construct()
    {
        parent::__construct(409, 'IDEMPOTENCY_REQUEST_IN_PROGRESS', 'A request with this Idempotency-Key is still being processed.');
    }
}
