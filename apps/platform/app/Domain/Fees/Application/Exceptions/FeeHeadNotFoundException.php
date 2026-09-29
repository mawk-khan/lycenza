<?php

namespace App\Domain\Fees\Application\Exceptions;

class FeeHeadNotFoundException extends FeesException
{
    public function __construct(string $feeHeadId)
    {
        parent::__construct(404, 'FEE_HEAD_NOT_FOUND', "Fee head '{$feeHeadId}' was not found.");
    }
}
