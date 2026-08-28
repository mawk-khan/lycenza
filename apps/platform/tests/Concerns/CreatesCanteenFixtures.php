<?php

namespace Tests\Concerns;

use App\Domain\Canteen\Application\CanteenBillingConfigurationService;
use App\Domain\Canteen\Application\CanteenOrderLineData;
use App\Domain\Canteen\Application\CanteenOrderResult;
use App\Domain\Canteen\Application\CanteenOrderService;
use App\Domain\Canteen\Application\CanteenRecipeService;
use App\Domain\Canteen\Application\PlaceCanteenOrderData;
use App\Domain\Canteen\Infrastructure\CanteenBillingConfiguration;
use App\Domain\Canteen\Infrastructure\CanteenItem;
use App\Domain\Canteen\Infrastructure\CanteenItemInventoryRequirement;
use App\Domain\Canteen\Infrastructure\CanteenOrder;
use App\Domain\Canteen\Infrastructure\CanteenOrderLine;
use App\Domain\Canteen\Infrastructure\CanteenOrderStockConsumption;
use App\Domain\Canteen\Infrastructure\CanteenOutlet;
use App\Domain\Finance\Infrastructure\LedgerAccount;
use App\Domain\Inventory\Infrastructure\InventoryItem;
use App\Domain\Inventory\Infrastructure\InventoryLocation;
use App\Domain\Inventory\Infrastructure\StockMovement;
use App\Domain\Students\Infrastructure\Student;
use App\Models\School;
use App\Support\Tenancy\TenantContext;

/**
 * Phase 10F test fixtures. Every raw-model helper here takes its
 * PARENT objects EXPLICITLY (never relies on a Canteen factory's own
 * lazy relationship defaults to happen to match a pre-existing School)
 * -- the same discipline `Tests\Concerns\CreatesTenancyFixtures::createInventoryStockBalance()`
 * already establishes. This matters structurally, not just stylistically:
 * every Canteen factory's `definition()` uses LAZY (never eager
 * nested-`create()`) relationship references specifically so it is
 * safe to invoke from inside an already-active `TenantContext::withSchool()`
 * for a school the factory did not itself create (see
 * `Database\Factories\CanteenOutletFactory`'s own docblock) -- a caller
 * that forgets to override a foreign key here will get a real,
 * loud RLS/composite-FK violation, not a silent cross-School row.
 *
 * Business-flow fixtures (Order placement/fulfillment/cancellation,
 * billing configuration validation, recipe locking) delegate to the
 * REAL Application service, mirroring
 * Tests\Concerns\CreatesFeesFixtures::assessCharge()'s exact "don't
 * maintain a second, competing implementation of business semantics"
 * discipline.
 */
trait CreatesCanteenFixtures
{
    protected function createCanteenOutlet(School $school, InventoryLocation $location, array $attributes = []): CanteenOutlet
    {
        return app(TenantContext::class)->withSchool(
            $school,
            fn () => CanteenOutlet::factory()->create(array_merge([
                'school_id' => $school->id,
                'campus_id' => null,
                'inventory_location_id' => $location->id,
            ], $attributes)),
        );
    }

    protected function createCanteenItem(School $school, array $attributes = []): CanteenItem
    {
        return app(TenantContext::class)->withSchool(
            $school,
            fn () => CanteenItem::factory()->create(array_merge([
                'school_id' => $school->id,
            ], $attributes)),
        );
    }

    protected function createCanteenItemInventoryRequirementRaw(School $school, CanteenItem $item, InventoryItem $inventoryItem, array $attributes = []): CanteenItemInventoryRequirement
    {
        return app(TenantContext::class)->withSchool(
            $school,
            fn () => CanteenItemInventoryRequirement::factory()->create(array_merge([
                'school_id' => $school->id,
                'canteen_item_id' => $item->id,
                'inventory_item_id' => $inventoryItem->id,
            ], $attributes)),
        );
    }

    protected function createCanteenBillingConfigurationRaw(School $school, LedgerAccount $receivable, LedgerAccount $revenue, array $attributes = []): CanteenBillingConfiguration
    {
        return app(TenantContext::class)->withSchool(
            $school,
            fn () => CanteenBillingConfiguration::factory()->create(array_merge([
                'school_id' => $school->id,
                'receivable_ledger_account_id' => $receivable->id,
                'revenue_ledger_account_id' => $revenue->id,
            ], $attributes)),
        );
    }

    protected function createCanteenOrderRaw(School $school, Student $student, CanteenOutlet $outlet, array $attributes = []): CanteenOrder
    {
        return app(TenantContext::class)->withSchool(
            $school,
            fn () => CanteenOrder::factory()->create(array_merge([
                'school_id' => $school->id,
                'student_id' => $student->id,
                'outlet_id' => $outlet->id,
                'inventory_location_id' => $outlet->inventory_location_id,
            ], $attributes)),
        );
    }

    protected function createCanteenOrderLineRaw(School $school, CanteenOrder $order, CanteenItem $item, array $attributes = []): CanteenOrderLine
    {
        return app(TenantContext::class)->withSchool(
            $school,
            fn () => CanteenOrderLine::factory()->create(array_merge([
                'school_id' => $school->id,
                'order_id' => $order->id,
                'canteen_item_id' => $item->id,
            ], $attributes)),
        );
    }

    protected function createCanteenOrderStockConsumptionRaw(School $school, CanteenOrder $order, StockMovement $movement, array $attributes = []): CanteenOrderStockConsumption
    {
        return app(TenantContext::class)->withSchool(
            $school,
            fn () => CanteenOrderStockConsumption::factory()->create(array_merge([
                'school_id' => $school->id,
                'canteen_order_id' => $order->id,
                'stock_movement_id' => $movement->id,
            ], $attributes)),
        );
    }

    protected function setCanteenRecipeRequirement(School $school, CanteenItem $item, InventoryItem $inventoryItem, string $quantityRequired): CanteenItemInventoryRequirement
    {
        return app(TenantContext::class)->withSchool(
            $school,
            fn () => app(CanteenRecipeService::class)->setRequirement($school, $item, $inventoryItem, $quantityRequired),
        );
    }

    protected function configureCanteenBilling(School $school, LedgerAccount $receivable, LedgerAccount $revenue): CanteenBillingConfiguration
    {
        return app(TenantContext::class)->withSchool(
            $school,
            fn () => app(CanteenBillingConfigurationService::class)->configure($school, $receivable->id, $revenue->id),
        );
    }

    /**
     * @param  list<array{canteenItemId: string, quantity: int}>  $lines
     */
    protected function placeCanteenOrder(School $school, string $studentId, string $outletId, array $lines): CanteenOrderResult
    {
        $lineData = array_map(fn (array $l) => new CanteenOrderLineData($l['canteenItemId'], $l['quantity']), $lines);

        return app(TenantContext::class)->withSchool(
            $school,
            fn () => app(CanteenOrderService::class)->place($school, new PlaceCanteenOrderData($studentId, $outletId, $lineData)),
        );
    }

    protected function fulfillCanteenOrder(School $school, string $orderId): CanteenOrderResult
    {
        return app(TenantContext::class)->withSchool(
            $school,
            fn () => app(CanteenOrderService::class)->fulfill($school, $orderId),
        );
    }

    protected function cancelCanteenOrder(School $school, string $orderId): CanteenOrderResult
    {
        return app(TenantContext::class)->withSchool(
            $school,
            fn () => app(CanteenOrderService::class)->cancel($school, $orderId),
        );
    }
}
