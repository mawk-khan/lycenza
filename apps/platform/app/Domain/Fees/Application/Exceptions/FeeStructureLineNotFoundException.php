<?php

namespace App\Domain\Fees\Application\Exceptions;

class FeeStructureLineNotFoundException extends FeesException
{
    public function __construct(string $lineId)
    {
        parent::__construct(404, 'FEE_STRUCTURE_LINE_NOT_FOUND', "Fee structure line '{$lineId}' was not found.");
    }
}
