<?php

namespace App\Domain\Fees\Application\Exceptions;

class DuplicateFeeOptionalSelectionException extends FeesException
{
    public function __construct()
    {
        parent::__construct(409, 'FEE_OPTIONAL_SELECTION_EXISTS', 'This Student already has an active selection for this optional fee in this academic year.');
    }
}
