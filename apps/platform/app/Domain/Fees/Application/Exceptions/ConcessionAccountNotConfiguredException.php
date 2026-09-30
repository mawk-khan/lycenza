<?php

namespace App\Domain\Fees\Application\Exceptions;

class ConcessionAccountNotConfiguredException extends FeesException
{
    public function __construct()
    {
        parent::__construct(409, 'FEE_CONCESSION_ACCOUNT_INVALID', 'The School\'s fee concession account is not configured, or is not an active expense account. Configure it in Fee settings first.');
    }
}
