<?php

namespace App\Domain\Fees\Application\Exceptions;

class FeeStructureNotDraftException extends FeesException
{
    public function __construct(string $feeStructureId, string $status)
    {
        parent::__construct(409, 'FEE_STRUCTURE_NOT_DRAFT', "Fee structure '{$feeStructureId}' is {$status}; only a draft can be edited.");
    }
}
