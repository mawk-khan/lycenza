<?php

namespace App\Domain\Fees\Application\Exceptions;

class DuplicateFeeHeadCodeException extends FeesException
{
    public function __construct(string $code)
    {
        parent::__construct(409, 'FEE_HEAD_CODE_TAKEN', "A fee head with code '{$code}' already exists in this School.");
    }
}
