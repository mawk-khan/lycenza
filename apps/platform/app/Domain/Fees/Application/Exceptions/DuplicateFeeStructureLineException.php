<?php

namespace App\Domain\Fees\Application\Exceptions;

class DuplicateFeeStructureLineException extends FeesException
{
    public function __construct(string $feeHeadId)
    {
        parent::__construct(409, 'FEE_STRUCTURE_LINE_DUPLICATE', "This fee structure already has a line for fee head '{$feeHeadId}'.");
    }
}
