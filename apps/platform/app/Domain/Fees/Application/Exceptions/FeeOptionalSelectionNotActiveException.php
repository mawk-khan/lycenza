<?php

namespace App\Domain\Fees\Application\Exceptions;

class FeeOptionalSelectionNotActiveException extends FeesException
{
    public function __construct(string $selectionId)
    {
        parent::__construct(409, 'FEE_OPTIONAL_SELECTION_NOT_ACTIVE', "Optional fee selection '{$selectionId}' is already withdrawn.");
    }
}
