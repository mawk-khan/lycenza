<?php

namespace App\Domain\Fees\Application\Exceptions;

class DuplicateFeeStructureCodeException extends FeesException
{
    public function __construct(string $code)
    {
        parent::__construct(409, 'FEE_STRUCTURE_CODE_TAKEN', "A fee structure with code '{$code}' already exists in this academic year.");
    }
}
