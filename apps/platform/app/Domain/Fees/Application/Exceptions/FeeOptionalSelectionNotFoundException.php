<?php

namespace App\Domain\Fees\Application\Exceptions;

class FeeOptionalSelectionNotFoundException extends FeesException
{
    public function __construct(string $selectionId)
    {
        parent::__construct(404, 'FEE_OPTIONAL_SELECTION_NOT_FOUND', "Optional fee selection '{$selectionId}' was not found.");
    }
}
