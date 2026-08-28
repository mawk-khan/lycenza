<?php

namespace Tests\Feature\Inventory;

use App\Domain\Inventory\Application\InventoryStockService;
use App\Domain\Inventory\Infrastructure\InventoryStockBalance;
use App\Domain\Inventory\Infrastructure\StockMovement;
use App\Support\Tenancy\TenantContext;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * THE mandatory anti-drift proof for the selected Model B architecture
 * (docs/modules/INVENTORY.md "Balance <-> movement reconciliation"):
 * after a mixed sequence of receipts/issues/transfers, the derived
 * signed sum of StockMovement history for each (item, location) pair
 * must equal `inventory_stock_balances.quantity_on_hand` EXACTLY.
 * `inventory_stock_balances` is the authoritative resource for fast
 * current-quantity lookups; this test is what proves it never drifts
 * from the immutable ledger that is authoritative for history.
 */
class InventoryStockReconciliationTest extends TestCase
{
    use CreatesTenancyFixtures;

    #[Test]
    public function derived_balances_from_movement_history_exactly_match_stored_balances_after_a_mixed_sequence(): void
    {
        [$admin, $school] = $this->createSchoolAdmin();
        $context = app(TenantContext::class);
        $service = app(InventoryStockService::class);

        $itemA = $this->createInventoryItem($school, ['unit_of_measure' => 'each']);
        $itemB = $this->createInventoryItem($school, ['unit_of_measure' => 'kg']);
        $locationX = $this->createInventoryLocation($school);
        $locationY = $this->createInventoryLocation($school);
        $locationZ = $this->createInventoryLocation($school);

        // Item A: receipts at X and Y, issues from X, a transfer X -> Z.
        $context->withSchool($school, function () use ($service, $itemA, $locationX, $locationY, $locationZ, $admin) {
            $service->receive($itemA, $locationX, '50.000', $admin);
            $service->receive($itemA, $locationY, '20.000', $admin);
            $service->issue($itemA, $locationX, '12.000', $admin);
            $service->transfer($itemA, $locationX, $locationZ, '8.000', $admin);
        });

        // Item B (fractional unit): a receipt, a partial issue, a transfer.
        $context->withSchool($school, function () use ($service, $itemB, $locationX, $locationY, $admin) {
            $service->receive($itemB, $locationX, '10.750', $admin);
            $service->issue($itemB, $locationX, '3.250', $admin);
            $service->transfer($itemB, $locationX, $locationY, '2.500', $admin);
        });

        $pairs = [
            [$itemA, $locationX],
            [$itemA, $locationY],
            [$itemA, $locationZ],
            [$itemB, $locationX],
            [$itemB, $locationY],
        ];

        foreach ($pairs as [$item, $location]) {
            $derived = $context->withSchool($school, function () use ($item, $location) {
                $receipts = StockMovement::query()->where('inventory_item_id', $item->id)->where('movement_type', 'receipt')->where('to_location_id', $location->id)->sum('quantity');
                $transfersIn = StockMovement::query()->where('inventory_item_id', $item->id)->where('movement_type', 'transfer')->where('to_location_id', $location->id)->sum('quantity');
                $issues = StockMovement::query()->where('inventory_item_id', $item->id)->where('movement_type', 'issue')->where('from_location_id', $location->id)->sum('quantity');
                $transfersOut = StockMovement::query()->where('inventory_item_id', $item->id)->where('movement_type', 'transfer')->where('from_location_id', $location->id)->sum('quantity');

                return bcsub(bcadd((string) $receipts, (string) $transfersIn, 3), bcadd((string) $issues, (string) $transfersOut, 3), 3);
            });

            $stored = $context->withSchool($school, fn () => InventoryStockBalance::query()
                ->where('inventory_item_id', $item->id)
                ->where('inventory_location_id', $location->id)
                ->value('quantity_on_hand'));

            $this->assertSame(
                $derived,
                $stored,
                "Derived balance from movement history must exactly equal the stored balance for item {$item->code} at location {$location->code}.",
            );
        }

        // Expected exact values, computed by hand, as an independent cross-check.
        $balanceAX = $context->withSchool($school, fn () => InventoryStockBalance::query()->where('inventory_item_id', $itemA->id)->where('inventory_location_id', $locationX->id)->value('quantity_on_hand'));
        $this->assertSame('30.000', $balanceAX); // 50 - 12 - 8
        $balanceAY = $context->withSchool($school, fn () => InventoryStockBalance::query()->where('inventory_item_id', $itemA->id)->where('inventory_location_id', $locationY->id)->value('quantity_on_hand'));
        $this->assertSame('20.000', $balanceAY);
        $balanceAZ = $context->withSchool($school, fn () => InventoryStockBalance::query()->where('inventory_item_id', $itemA->id)->where('inventory_location_id', $locationZ->id)->value('quantity_on_hand'));
        $this->assertSame('8.000', $balanceAZ);
        $balanceBX = $context->withSchool($school, fn () => InventoryStockBalance::query()->where('inventory_item_id', $itemB->id)->where('inventory_location_id', $locationX->id)->value('quantity_on_hand'));
        $this->assertSame('5.000', $balanceBX); // 10.750 - 3.250 - 2.500
        $balanceBY = $context->withSchool($school, fn () => InventoryStockBalance::query()->where('inventory_item_id', $itemB->id)->where('inventory_location_id', $locationY->id)->value('quantity_on_hand'));
        $this->assertSame('2.500', $balanceBY);
    }
}
