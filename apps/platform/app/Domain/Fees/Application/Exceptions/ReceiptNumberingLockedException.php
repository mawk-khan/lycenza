<?php

namespace App\Domain\Fees\Application\Exceptions;

class ReceiptNumberingLockedException extends FeesException
{
    public function __construct(string $message)
    {
        parent::__construct(409, 'RECEIPT_NUMBERING_LOCKED', $message);
    }
}
