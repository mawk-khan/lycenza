<?php

namespace App\Domain\Inventory\Application\Exceptions;

class InvalidWriteOffReasonException extends InventoryException
{
    public function __construct()
    {
        parent::__construct(422, 'INVENTORY_WRITE_OFF_REASON_INVALID', 'Choose a write-off reason.');
    }
}
