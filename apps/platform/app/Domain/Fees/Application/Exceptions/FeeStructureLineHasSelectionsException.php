<?php

namespace App\Domain\Fees\Application\Exceptions;

class FeeStructureLineHasSelectionsException extends FeesException
{
    public function __construct(string $lineId)
    {
        parent::__construct(409, 'FEE_STRUCTURE_LINE_HAS_SELECTIONS', "Fee structure line '{$lineId}' has optional fee selections and cannot be removed.");
    }
}
