<?php

namespace App\Domain\Fees\Application\Exceptions;

class FeeStructureNotFoundException extends FeesException
{
    public function __construct(string $feeStructureId)
    {
        parent::__construct(404, 'FEE_STRUCTURE_NOT_FOUND', "Fee structure '{$feeStructureId}' was not found.");
    }
}
