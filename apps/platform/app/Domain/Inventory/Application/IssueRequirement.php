<?php

namespace App\Domain\Inventory\Application;

/**
 * Phase 10F foundation: the typed input for one Item's worth of
 * `InventoryStockService::issueMany()` -- matches
 * App\Domain\Fees\Application\AssessChargeData's established
 * precedent (only fields this class actually declares can ever reach
 * the service).
 *
 * `inventoryItemId` is a trusted, already-known identifier -- this
 * class does not validate its existence, School ownership, or
 * lifecycle state; `issueMany()` itself resolves and validates every
 * Item (see its docblock). `quantity` is an exact-decimal string,
 * never a float, matching every other quantity in this file.
 */
final class IssueRequirement
{
    public function __construct(
        public readonly string $inventoryItemId,
        public readonly string $quantity,
    ) {}
}
