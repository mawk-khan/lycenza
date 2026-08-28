<?php

namespace App\Domain\Inventory\Application\Exceptions;

/**
 * Raised by `InventoryStockService::issueMany()` when the caller
 * supplies zero requirements. Deliberately a distinct exception from
 * `InvalidQuantityException` -- the caller-facing remedy is different
 * ("supply at least one requirement" vs. "fix this requirement's
 * quantity"), matching this file's existing one-exception-per-distinct-
 * remedy granularity.
 */
class EmptyIssueRequirementsException extends InventoryException
{
    public function __construct()
    {
        parent::__construct(422, 'INVENTORY_EMPTY_ISSUE_REQUIREMENTS', 'issueMany() requires at least one requirement.');
    }
}
