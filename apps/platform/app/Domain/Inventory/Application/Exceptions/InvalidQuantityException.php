<?php

namespace App\Domain\Inventory\Application\Exceptions;

/**
 * Covers both a non-positive quantity and a fractional quantity
 * submitted against an Item whose `unit_of_measure` does not allow
 * fractions (docs/modules/INVENTORY.md "Unit / decimal handling") --
 * one exception for both, since the caller-facing remedy is identical
 * ("submit a valid quantity for this Item's unit").
 */
class InvalidQuantityException extends InventoryException
{
    public function __construct(string $reason)
    {
        parent::__construct(422, 'INVENTORY_INVALID_QUANTITY', $reason);
    }
}
