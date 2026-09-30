<?php

namespace App\Domain\Fees\Application\Exceptions;

class FeeConcessionNotFoundException extends FeesException
{
    public function __construct(string $concessionId)
    {
        parent::__construct(404, 'FEE_CONCESSION_NOT_FOUND', "Fee concession '{$concessionId}' was not found.");
    }
}
