<?php

namespace App\Domain\Inventory\Application\Exceptions;

/** ORG.9C-2: a form key already claimed by a DIFFERENT stock movement (never replayed). */
class StockRequestAlreadySubmittedException extends InventoryException
{
    public function __construct()
    {
        parent::__construct(409, 'INVENTORY_REQUEST_ALREADY_SUBMITTED', 'This form was already submitted with different details. Reload the page to start again.');
    }
}
