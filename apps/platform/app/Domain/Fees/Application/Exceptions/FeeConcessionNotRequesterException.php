<?php

namespace App\Domain\Fees\Application\Exceptions;

class FeeConcessionNotRequesterException extends FeesException
{
    public function __construct(string $concessionId)
    {
        parent::__construct(403, 'FEE_CONCESSION_NOT_REQUESTER', "Only the requester may withdraw fee concession '{$concessionId}'.");
    }
}
