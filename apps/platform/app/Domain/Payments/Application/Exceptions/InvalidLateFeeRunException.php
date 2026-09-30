<?php

namespace App\Domain\Payments\Application\Exceptions;

class InvalidLateFeeRunException extends PaymentsException
{
    public function __construct(private readonly string $field, string $message)
    {
        parent::__construct(422, 'LATE_FEE_RUN_INVALID', $message);
    }

    public function field(): string
    {
        return $this->field;
    }
}
