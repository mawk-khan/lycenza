<?php

namespace App\Domain\Fees\Application\Exceptions;

class FeeStructureIncompleteException extends FeesException
{
    public function __construct(string $message)
    {
        parent::__construct(422, 'FEE_STRUCTURE_INCOMPLETE', $message);
    }
}
