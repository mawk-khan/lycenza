<?php

namespace App\Domain\Inventory\Application\Exceptions;

/**
 * Raised by `InventoryStockService::issueMany()` when a requirement's
 * `inventoryItemId` does not resolve to a real `InventoryItem` row in
 * the issuing Location's School. `ItemNotAvailableException` (the
 * existing exception every single-item method throws) is deliberately
 * scoped to "this Item exists but is inactive" only -- its message
 * says so explicitly -- so it is the wrong exception for "this id
 * does not exist at all"; every single-item method (`receive()`,
 * `issue()`, `transfer()`) is handed an already-resolved `InventoryItem`
 * by its caller and never needed a "not found" case of its own.
 * `issueMany()` is the first method that resolves Items by raw id
 * itself, so it needs this companion. Mirrors
 * App\Domain\Fees\Application\Exceptions\StudentNotFoundException's
 * shape (404, "not found in this School" -- never distinguishing
 * "does not exist" from "exists in another School").
 */
class InventoryItemNotFoundException extends InventoryException
{
    public function __construct(public readonly string $inventoryItemId)
    {
        parent::__construct(404, 'INVENTORY_ITEM_NOT_FOUND', "No Inventory Item with id '{$inventoryItemId}' was found in this School.");
    }
}
