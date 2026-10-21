<?php

namespace App\Domain\Inventory\Application\Exceptions;

/**
 * ORG.7H-1 (ADR 0076 §54): a used Inventory Location's Campus is frozen.
 */
class LocationCampusFrozenException extends InventoryException
{
    public function __construct()
    {
        parent::__construct(409, 'INVENTORY_LOCATION_CAMPUS_FROZEN', 'This Inventory Location already has stock history, so its Campus can no longer change.');
    }
}
