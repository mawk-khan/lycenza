<?php

namespace App\Support\Idempotency\Exceptions;

class IdempotencyKeyRequiredException extends IdempotencyException
{
    public function __construct()
    {
        parent::__construct(400, 'IDEMPOTENCY_KEY_REQUIRED', 'The Idempotency-Key header is required for this endpoint.');
    }
}
