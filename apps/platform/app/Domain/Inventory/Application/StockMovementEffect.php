<?php

namespace App\Domain\Inventory\Application;

use InvalidArgumentException;

/**
 * ORG.9C-3 (ADR 0076 §71): THE one definition of what a movement does to the
 * stock of a SET of Locations -- the same rule InventoryStockService applies
 * to balances, stated once for every read model:
 *
 *   receipt     +q if its destination is in the set
 *   issue       -q if its source is in the set (Canteen consumption is an issue)
 *   write_off   -q if its source is in the set
 *   transfer    +q in, -q out; 0 when both ends are in the set (a transfer
 *               between two Locations of the same set never changes its total)
 *
 * The set is one Location, one Campus's Locations or the reader's whole
 * scope; so a School-wide total is unchanged by any transfer.
 */
final class StockMovementEffect
{
    /** Movement types, in display order. */
    public const TYPES = ['receipt', 'transfer', 'issue', 'write_off'];

    /** The signed multiplier (+1, 0, -1) of a movement for a set, given whether each end is in it. */
    public static function sign(string $type, bool $fromInSet, bool $toInSet): int
    {
        return match ($type) {
            'receipt' => $toInSet ? 1 : 0,
            'issue', 'write_off' => $fromInSet ? -1 : 0,
            'transfer' => ($toInSet ? 1 : 0) - ($fromInSet ? 1 : 0),
            default => throw new InvalidArgumentException("Unknown stock movement type: {$type}"),
        };
    }

    /**
     * The same rule in SQL. $type, $fromInSet and $toInSet are SQL expressions
     * (the boolean ones are true when that end is a member of the set).
     */
    public static function signSql(string $type, string $fromInSet, string $toInSet): string
    {
        $in = fn (string $end) => "(CASE WHEN {$end} THEN 1 ELSE 0 END)";

        return "(CASE {$type} WHEN 'receipt' THEN {$in($toInSet)}"
            ." WHEN 'issue' THEN -{$in($fromInSet)}"
            ." WHEN 'write_off' THEN -{$in($fromInSet)}"
            ." WHEN 'transfer' THEN {$in($toInSet)} - {$in($fromInSet)}"
            .' END)';
    }
}
