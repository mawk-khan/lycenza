<?php

namespace App\Domain\Fees\Application\Exceptions;

class InvalidFeeLateFeeRuleException extends FeesException
{
    public function __construct(private readonly string $field, string $message)
    {
        parent::__construct(422, 'LATE_FEE_RULE_INVALID', $message);
    }

    public function field(): string
    {
        return $this->field;
    }
}
