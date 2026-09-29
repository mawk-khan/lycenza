<?php

namespace App\Domain\Fees\Application\Exceptions;

class FeeStructureActivationConflictException extends FeesException
{
    public function __construct(string $feeStructureId)
    {
        parent::__construct(409, 'FEE_STRUCTURE_ACTIVATION_CONFLICT', "Another fee structure became active for the same academic year, grade and campus first; fee structure '{$feeStructureId}' was not activated.");
    }
}
