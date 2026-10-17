<?php

namespace App\Domain\Canteen\Application;

use App\Domain\Canteen\Infrastructure\CanteenItem;
use App\Domain\Canteen\Infrastructure\CanteenOrder;
use App\Domain\Canteen\Infrastructure\CanteenOrderLine;
use App\Models\School;
use App\Support\Tenancy\SchoolTimezone;
use Illuminate\Support\Collection;

/**
 * LC-3 (M3): the human description of the Charge a fulfilled order raises,
 * e.g. "Canteen · Veg Thali ×2, Juice ×1 · 17 Oct 2026" -- what was bought
 * and on which School day, in the same "A · B" style as fee-run charges.
 *
 * It becomes the Charge's and its journal entry's description, both
 * immutable once posted (`fees_reject_recognized_charge_mutation`), so it
 * is a snapshot: renaming an item later never changes it. It never carries
 * an internal id. Charges raised before LC-3 keep their "Canteen order
 * <id>" text (FINANCE.md: posted history is never rewritten).
 */
final class CanteenChargeDescription
{
    /** The Charge and journal entry description columns are varchar(255). */
    public const MAX_LENGTH = 255;

    /**
     * @param  Collection<int, CanteenOrderLine>  $lines
     * @param  array<string, CanteenItem>  $itemsById
     */
    public static function for(School $school, CanteenOrder $order, Collection $lines, array $itemsById): string
    {
        $day = $order->placed_at->copy()->setTimezone(SchoolTimezone::resolve($school))->format('j M Y');

        $parts = $lines
            ->sortBy(fn (CanteenOrderLine $l) => mb_strtolower($itemsById[$l->canteen_item_id]->name ?? ''))
            ->map(fn (CanteenOrderLine $l) => ($itemsById[$l->canteen_item_id]->name ?? 'Item').' ×'.$l->quantity)
            ->values()->all();

        // Fit within the column, naming as many items as fit and counting the rest.
        for ($shown = count($parts); $shown >= 1; $shown--) {
            $rest = count($parts) - $shown;
            $items = implode(', ', array_slice($parts, 0, $shown)).($rest > 0 ? " and {$rest} more" : '');
            $description = "Canteen · {$items} · {$day}";
            if (mb_strlen($description) <= self::MAX_LENGTH) {
                return $description;
            }
        }

        return 'Canteen · '.count($parts).(count($parts) === 1 ? ' item' : ' items').' · '.$day;
    }
}
