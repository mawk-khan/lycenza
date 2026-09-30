<?php

namespace App\Domain\Fees\Application\Exceptions;

class FeeConcessionIllegalTransitionException extends FeesException
{
    public function __construct(string $concessionId, string $from, string $action)
    {
        parent::__construct(409, 'FEE_CONCESSION_ILLEGAL_TRANSITION', "Fee concession '{$concessionId}' is {$from} and cannot be {$action}.");
    }
}
