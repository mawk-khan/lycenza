<?php

namespace App\Support\Idempotency\Exceptions;

class IdempotencyKeyInvalidException extends IdempotencyException
{
    public function __construct(string $reason)
    {
        parent::__construct(400, 'IDEMPOTENCY_KEY_INVALID', "Idempotency-Key is invalid: {$reason}");
    }
}
