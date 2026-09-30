<?php

namespace App\Domain\Fees\Application\Exceptions;

class FeeConcessionIdempotencyConflictException extends FeesException
{
    public function __construct()
    {
        parent::__construct(409, 'FEE_CONCESSION_IDEMPOTENCY_CONFLICT', 'This request key was already used for a different concession request. Reload the form and try again.');
    }
}
