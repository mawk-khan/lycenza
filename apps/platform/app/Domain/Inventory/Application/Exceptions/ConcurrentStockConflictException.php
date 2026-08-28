<?php

namespace App\Domain\Inventory\Application\Exceptions;

/**
 * Surfaced when the database's own `inventory_stock_balances_quantity_non_negative_check`
 * CHECK constraint rejects a mutation that survived past the
 * service's own row lock + post-lock check -- the final structural
 * backstop, mirroring
 * App\Domain\Hostel\Application\Exceptions\ConcurrentResidencyConflictException
 * exactly. Should not occur under normal operation (the row lock is
 * the primary mechanism); this exists for the same defense-in-depth
 * reason the CHECK constraint itself exists.
 */
class ConcurrentStockConflictException extends InventoryException
{
    public function __construct()
    {
        parent::__construct(409, 'INVENTORY_STOCK_CONFLICT', 'This stock balance was changed by someone else concurrently. Refresh and try again.');
    }
}
