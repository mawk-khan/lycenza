<?php

namespace App\Domain\Fees\Application\Exceptions;

class SelfApprovalNotAllowedException extends FeesException
{
    public function __construct(string $concessionId)
    {
        parent::__construct(403, 'FEE_CONCESSION_SELF_APPROVAL', "You requested fee concession '{$concessionId}'; another person must decide it.");
    }
}
