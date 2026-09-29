<?php

namespace App\Domain\Fees\Application\Exceptions;

class FeeStructureIllegalTransitionException extends FeesException
{
    public function __construct(string $from, string $to)
    {
        parent::__construct(409, 'FEE_STRUCTURE_ILLEGAL_TRANSITION', "A fee structure cannot move from {$from} to {$to}.");
    }
}
