<?php

namespace App\Domain\Inventory\Application\Exceptions;

/**
 * Raised by `InventoryStockService::issueMany()` when the same
 * `inventoryItemId` appears more than once across the caller's
 * requirement set. Checked FIRST, before any database work
 * (docs/modules/INVENTORY.md "Multi-item issue") -- a caller asking
 * to issue the same Item twice in one atomic call has no sane
 * resolution here (which requirement's quantity would "win"?); the
 * caller must aggregate to one requirement per Item before calling.
 */
class DuplicateInventoryItemRequirementException extends InventoryException
{
    public function __construct(public readonly string $inventoryItemId)
    {
        parent::__construct(422, 'INVENTORY_DUPLICATE_ITEM_REQUIREMENT', "Inventory Item '{$inventoryItemId}' was requested more than once in the same issueMany() call.");
    }
}
