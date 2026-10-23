<?php

namespace App\Domain\Inventory\Application\Exceptions;

/** ORG.9C-2: the Department cannot receive stock from this Location (inactive, unknown or another Campus). */
class IssueRecipientNotAvailableException extends InventoryException
{
    public function __construct()
    {
        parent::__construct(422, 'INVENTORY_ISSUE_RECIPIENT_NOT_AVAILABLE', 'That department cannot receive stock from this location.');
    }
}
