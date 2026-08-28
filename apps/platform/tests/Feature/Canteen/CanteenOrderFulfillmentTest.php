<?php

namespace Tests\Feature\Canteen;

use App\Domain\Canteen\Application\Exceptions\CanteenBillingAccountInvalidException;
use App\Domain\Canteen\Application\Exceptions\CanteenBillingNotConfiguredException;
use App\Domain\Canteen\Application\Exceptions\CanteenNoActiveAcademicYearException;
use App\Domain\Canteen\Application\Exceptions\CanteenOrderAlreadyFulfilledException;
use App\Domain\Canteen\Application\Exceptions\CanteenOrderCancelledCannotBeFulfilledException;
use App\Domain\Canteen\Infrastructure\CanteenBillingConfiguration;
use App\Domain\Canteen\Infrastructure\CanteenOrder;
use App\Domain\Canteen\Infrastructure\CanteenOrderStockConsumption;
use App\Domain\Fees\Infrastructure\Charge;
use App\Domain\Finance\Infrastructure\LedgerAccount;
use App\Domain\Inventory\Application\Exceptions\InsufficientStockException;
use App\Domain\Inventory\Application\Exceptions\LocationNotAvailableException;
use App\Domain\Inventory\Infrastructure\InventoryStockBalance;
use App\Domain\Inventory\Infrastructure\StockMovement;
use App\Models\School;
use App\Support\Tenancy\TenantContext;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCanteenFixtures;
use Tests\Concerns\CreatesFinanceFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 10F -- CanteenOrderService::fulfill(). Real integration tests
 * against InventoryStockService::issueMany() and ChargeService::assess()
 * -- never mocked. See CanteenOrderFulfillmentAtomicityTest for the
 * mandatory real-failure-injection cross-domain rollback proof.
 */
class CanteenOrderFulfillmentTest extends TestCase
{
    use CreatesCanteenFixtures, CreatesFinanceFixtures, CreatesTenancyFixtures;

    private function setUpBilling(School $school): void
    {
        $receivable = $this->createLedgerAccount($school, ['type' => 'asset']);
        $revenue = $this->createLedgerAccount($school, ['type' => 'income']);
        $this->configureCanteenBilling($school, $receivable, $revenue);
    }

    #[Test]
    public function a_zero_recipe_item_fulfills_without_touching_inventory(): void
    {
        $school = $this->createSchool();
        $this->createAcademicYear($school, ['status' => 'active']);
        $this->setUpBilling($school);
        $location = $this->createInventoryLocation($school);
        $outlet = $this->createCanteenOutlet($school, $location);
        $student = $this->createStudent($school);
        $item = $this->createCanteenItem($school, ['price' => '15.00']);

        $placed = $this->placeCanteenOrder($school, $student->id, $outlet->id, [
            ['canteenItemId' => $item->id, 'quantity' => 2],
        ]);

        $result = $this->fulfillCanteenOrder($school, $placed->orderId);

        $this->assertSame('fulfilled', $result->status);
        $this->assertNotNull($result->chargeId);

        $movementCount = app(TenantContext::class)->withSchool($school, fn () => StockMovement::query()->count());
        $this->assertSame(0, $movementCount);

        $consumptionCount = app(TenantContext::class)->withSchool($school, fn () => CanteenOrderStockConsumption::query()->count());
        $this->assertSame(0, $consumptionCount);
    }

    #[Test]
    public function a_single_ingredient_recipe_decrements_stock_and_creates_a_consumption_link(): void
    {
        $school = $this->createSchool();
        $this->createAcademicYear($school, ['status' => 'active']);
        $this->setUpBilling($school);
        $location = $this->createInventoryLocation($school);
        $outlet = $this->createCanteenOutlet($school, $location);
        $student = $this->createStudent($school);
        $item = $this->createCanteenItem($school, ['price' => '15.00']);
        $flour = $this->createInventoryItem($school, ['unit_of_measure' => 'kg']);
        app(TenantContext::class)->withSchool($school, fn () => $this->createInventoryStockBalance($flour, $location, ['quantity_on_hand' => '5.000']));
        $this->setCanteenRecipeRequirement($school, $item, $flour, '0.200');

        $placed = $this->placeCanteenOrder($school, $student->id, $outlet->id, [
            ['canteenItemId' => $item->id, 'quantity' => 3],
        ]);

        $this->fulfillCanteenOrder($school, $placed->orderId);

        // Re-query the actual canonical balance row (never re-call
        // createInventoryStockBalance() here -- that is a FACTORY CREATE,
        // which would insert a second, constraint-violating row).
        $actualBalance = app(TenantContext::class)->withSchool($school, function () use ($flour, $location) {
            return InventoryStockBalance::query()
                ->where('inventory_item_id', $flour->id)
                ->where('inventory_location_id', $location->id)
                ->first();
        });

        // 5.000 - (0.200 * 3) = 5.000 - 0.600 = 4.400
        $this->assertSame('4.400', $actualBalance->quantity_on_hand);

        $consumptions = app(TenantContext::class)->withSchool($school, fn () => CanteenOrderStockConsumption::query()->where('canteen_order_id', $placed->orderId)->get());
        $this->assertCount(1, $consumptions);

        $movements = app(TenantContext::class)->withSchool($school, fn () => StockMovement::query()->where('movement_type', 'issue')->get());
        $this->assertCount(1, $movements);
        $this->assertSame('0.600', $movements->first()->quantity);
        $this->assertSame($movements->first()->id, $consumptions->first()->stock_movement_id);
    }

    #[Test]
    public function a_multi_ingredient_recipe_decrements_every_ingredient(): void
    {
        $school = $this->createSchool();
        $this->createAcademicYear($school, ['status' => 'active']);
        $this->setUpBilling($school);
        $location = $this->createInventoryLocation($school);
        $outlet = $this->createCanteenOutlet($school, $location);
        $student = $this->createStudent($school);
        $item = $this->createCanteenItem($school, ['price' => '50.00']);
        $bread = $this->createInventoryItem($school, ['unit_of_measure' => 'each']);
        $cheese = $this->createInventoryItem($school, ['unit_of_measure' => 'kg']);
        app(TenantContext::class)->withSchool($school, function () use ($bread, $cheese, $location) {
            $this->createInventoryStockBalance($bread, $location, ['quantity_on_hand' => '20.000']);
            $this->createInventoryStockBalance($cheese, $location, ['quantity_on_hand' => '3.000']);
        });
        $this->setCanteenRecipeRequirement($school, $item, $bread, '2.000');
        $this->setCanteenRecipeRequirement($school, $item, $cheese, '0.050');

        $placed = $this->placeCanteenOrder($school, $student->id, $outlet->id, [
            ['canteenItemId' => $item->id, 'quantity' => 4],
        ]);
        $this->fulfillCanteenOrder($school, $placed->orderId);

        [$breadBalance, $cheeseBalance] = app(TenantContext::class)->withSchool($school, function () use ($bread, $cheese, $location) {
            $balanceModel = InventoryStockBalance::class;

            return [
                $balanceModel::query()->where('inventory_item_id', $bread->id)->where('inventory_location_id', $location->id)->first(),
                $balanceModel::query()->where('inventory_item_id', $cheese->id)->where('inventory_location_id', $location->id)->first(),
            ];
        });

        // bread: 20 - (2 * 4) = 12 ; cheese: 3 - (0.05 * 4) = 2.800
        $this->assertSame('12.000', $breadBalance->quantity_on_hand);
        $this->assertSame('2.800', $cheeseBalance->quantity_on_hand);
    }

    #[Test]
    public function overlapping_ingredients_across_multiple_lines_aggregate_exactly(): void
    {
        $school = $this->createSchool();
        $this->createAcademicYear($school, ['status' => 'active']);
        $this->setUpBilling($school);
        $location = $this->createInventoryLocation($school);
        $outlet = $this->createCanteenOutlet($school, $location);
        $student = $this->createStudent($school);
        $rice = $this->createInventoryItem($school, ['unit_of_measure' => 'kg']);
        app(TenantContext::class)->withSchool($school, fn () => $this->createInventoryStockBalance($rice, $location, ['quantity_on_hand' => '10.000']));

        $itemA = $this->createCanteenItem($school, ['price' => '30.00']);
        $itemB = $this->createCanteenItem($school, ['price' => '40.00']);
        $this->setCanteenRecipeRequirement($school, $itemA, $rice, '0.150');
        $this->setCanteenRecipeRequirement($school, $itemB, $rice, '0.100');

        $placed = $this->placeCanteenOrder($school, $student->id, $outlet->id, [
            ['canteenItemId' => $itemA->id, 'quantity' => 2],
            ['canteenItemId' => $itemB->id, 'quantity' => 3],
        ]);
        $this->fulfillCanteenOrder($school, $placed->orderId);

        $riceBalance = app(TenantContext::class)->withSchool($school, fn () => InventoryStockBalance::query()
            ->where('inventory_item_id', $rice->id)->where('inventory_location_id', $location->id)->first());

        // (0.150*2) + (0.100*3) = 0.300 + 0.300 = 0.600 consumed. 10 - 0.6 = 9.400
        $this->assertSame('9.400', $riceBalance->quantity_on_hand);

        // Exactly ONE aggregated issue movement for rice, not two.
        $movements = app(TenantContext::class)->withSchool($school, fn () => StockMovement::query()->where('inventory_item_id', $rice->id)->where('movement_type', 'issue')->get());
        $this->assertCount(1, $movements);
        $this->assertSame('0.600', $movements->first()->quantity);
    }

    #[Test]
    public function fulfillment_uses_the_recipe_current_at_fulfillment_time_not_at_placement_time(): void
    {
        $school = $this->createSchool();
        $this->createAcademicYear($school, ['status' => 'active']);
        $this->setUpBilling($school);
        $location = $this->createInventoryLocation($school);
        $outlet = $this->createCanteenOutlet($school, $location);
        $student = $this->createStudent($school);
        $item = $this->createCanteenItem($school, ['price' => '20.00']);
        $sugar = $this->createInventoryItem($school, ['unit_of_measure' => 'kg']);
        app(TenantContext::class)->withSchool($school, fn () => $this->createInventoryStockBalance($sugar, $location, ['quantity_on_hand' => '10.000']));
        $this->setCanteenRecipeRequirement($school, $item, $sugar, '0.100');

        $placed = $this->placeCanteenOrder($school, $student->id, $outlet->id, [
            ['canteenItemId' => $item->id, 'quantity' => 1],
        ]);

        // Recipe changes AFTER placement, BEFORE fulfillment.
        $this->setCanteenRecipeRequirement($school, $item, $sugar, '0.500');

        $this->fulfillCanteenOrder($school, $placed->orderId);

        $balance = app(TenantContext::class)->withSchool($school, fn () => InventoryStockBalance::query()
            ->where('inventory_item_id', $sugar->id)->where('inventory_location_id', $location->id)->first());

        // Must use the 0.500 recipe (current at fulfillment), not 0.100.
        $this->assertSame('9.500', $balance->quantity_on_hand);
    }

    #[Test]
    public function an_inactive_snapshotted_location_blocks_fulfillment(): void
    {
        $school = $this->createSchool();
        $this->createAcademicYear($school, ['status' => 'active']);
        $this->setUpBilling($school);
        $location = $this->createInventoryLocation($school);
        $outlet = $this->createCanteenOutlet($school, $location);
        $student = $this->createStudent($school);
        $item = $this->createCanteenItem($school, ['price' => '10.00']);

        $placed = $this->placeCanteenOrder($school, $student->id, $outlet->id, [
            ['canteenItemId' => $item->id, 'quantity' => 1],
        ]);

        app(TenantContext::class)->withSchool($school, fn () => $location->update(['status' => 'inactive']));

        // Item has zero recipe requirements, so issueMany() is skipped
        // entirely -- add a real ingredient requirement so the Location
        // is actually consulted.
        $flour = $this->createInventoryItem($school);
        app(TenantContext::class)->withSchool($school, fn () => $this->createInventoryStockBalance($flour, $location, ['quantity_on_hand' => '5.000']));
        $this->setCanteenRecipeRequirement($school, $item, $flour, '0.100');

        $this->expectException(LocationNotAvailableException::class);
        $this->fulfillCanteenOrder($school, $placed->orderId);
    }

    #[Test]
    public function missing_billing_configuration_blocks_fulfillment(): void
    {
        $school = $this->createSchool();
        $this->createAcademicYear($school, ['status' => 'active']);
        $location = $this->createInventoryLocation($school);
        $outlet = $this->createCanteenOutlet($school, $location);
        $student = $this->createStudent($school);
        $item = $this->createCanteenItem($school, ['price' => '10.00']);

        $placed = $this->placeCanteenOrder($school, $student->id, $outlet->id, [
            ['canteenItemId' => $item->id, 'quantity' => 1],
        ]);

        $this->expectException(CanteenBillingNotConfiguredException::class);
        $this->fulfillCanteenOrder($school, $placed->orderId);
    }

    #[Test]
    public function an_invalid_billing_configuration_blocks_fulfillment(): void
    {
        $school = $this->createSchool();
        $this->createAcademicYear($school, ['status' => 'active']);
        $location = $this->createInventoryLocation($school);
        $outlet = $this->createCanteenOutlet($school, $location);
        $student = $this->createStudent($school);
        $item = $this->createCanteenItem($school, ['price' => '10.00']);
        $this->setUpBilling($school);

        $placed = $this->placeCanteenOrder($school, $student->id, $outlet->id, [
            ['canteenItemId' => $item->id, 'quantity' => 1],
        ]);

        // The configured receivable account goes inactive AFTER
        // configuration but BEFORE fulfillment.
        $config = app(TenantContext::class)->withSchool($school, fn () => CanteenBillingConfiguration::query()->where('school_id', $school->id)->first());
        $receivable = app(TenantContext::class)->withSchool($school, fn () => LedgerAccount::query()->find($config->receivable_ledger_account_id));
        app(TenantContext::class)->withSchool($school, fn () => $receivable->update(['status' => 'inactive']));

        $this->expectException(CanteenBillingAccountInvalidException::class);
        $this->fulfillCanteenOrder($school, $placed->orderId);
    }

    #[Test]
    public function missing_active_academic_year_blocks_fulfillment(): void
    {
        $school = $this->createSchool();
        $this->setUpBilling($school);
        $location = $this->createInventoryLocation($school);
        $outlet = $this->createCanteenOutlet($school, $location);
        $student = $this->createStudent($school);
        $item = $this->createCanteenItem($school, ['price' => '10.00']);

        $placed = $this->placeCanteenOrder($school, $student->id, $outlet->id, [
            ['canteenItemId' => $item->id, 'quantity' => 1],
        ]);

        $this->expectException(CanteenNoActiveAcademicYearException::class);
        $this->fulfillCanteenOrder($school, $placed->orderId);
    }

    #[Test]
    public function the_charge_amount_equals_the_orders_frozen_total(): void
    {
        $school = $this->createSchool();
        $this->createAcademicYear($school, ['status' => 'active']);
        $this->setUpBilling($school);
        $location = $this->createInventoryLocation($school);
        $outlet = $this->createCanteenOutlet($school, $location);
        $student = $this->createStudent($school);
        $item = $this->createCanteenItem($school, ['price' => '33.33']);

        $placed = $this->placeCanteenOrder($school, $student->id, $outlet->id, [
            ['canteenItemId' => $item->id, 'quantity' => 3],
        ]);
        $result = $this->fulfillCanteenOrder($school, $placed->orderId);

        $charge = app(TenantContext::class)->withSchool($school, fn () => Charge::query()->find($result->chargeId));
        $this->assertSame('99.99', $charge->amount);
        $this->assertSame($placed->totalAmount, $charge->amount);

        $chargeCount = app(TenantContext::class)->withSchool($school, fn () => Charge::query()->where('student_id', $student->id)->count());
        $this->assertSame(1, $chargeCount);
    }

    #[Test]
    public function fulfilling_an_already_fulfilled_order_is_rejected(): void
    {
        $school = $this->createSchool();
        $this->createAcademicYear($school, ['status' => 'active']);
        $this->setUpBilling($school);
        $location = $this->createInventoryLocation($school);
        $outlet = $this->createCanteenOutlet($school, $location);
        $student = $this->createStudent($school);
        $item = $this->createCanteenItem($school, ['price' => '10.00']);

        $placed = $this->placeCanteenOrder($school, $student->id, $outlet->id, [
            ['canteenItemId' => $item->id, 'quantity' => 1],
        ]);
        $this->fulfillCanteenOrder($school, $placed->orderId);

        $this->expectException(CanteenOrderAlreadyFulfilledException::class);
        $this->fulfillCanteenOrder($school, $placed->orderId);
    }

    #[Test]
    public function fulfilling_a_cancelled_order_is_rejected(): void
    {
        $school = $this->createSchool();
        $this->createAcademicYear($school, ['status' => 'active']);
        $this->setUpBilling($school);
        $location = $this->createInventoryLocation($school);
        $outlet = $this->createCanteenOutlet($school, $location);
        $student = $this->createStudent($school);
        $item = $this->createCanteenItem($school, ['price' => '10.00']);

        $placed = $this->placeCanteenOrder($school, $student->id, $outlet->id, [
            ['canteenItemId' => $item->id, 'quantity' => 1],
        ]);
        $this->cancelCanteenOrder($school, $placed->orderId);

        $this->expectException(CanteenOrderCancelledCannotBeFulfilledException::class);
        $this->fulfillCanteenOrder($school, $placed->orderId);
    }

    #[Test]
    public function insufficient_stock_propagates_and_leaves_the_order_pending_with_no_charge(): void
    {
        $school = $this->createSchool();
        $this->createAcademicYear($school, ['status' => 'active']);
        $this->setUpBilling($school);
        $location = $this->createInventoryLocation($school);
        $outlet = $this->createCanteenOutlet($school, $location);
        $student = $this->createStudent($school);
        $item = $this->createCanteenItem($school, ['price' => '10.00']);
        $flour = $this->createInventoryItem($school, ['unit_of_measure' => 'kg']);
        app(TenantContext::class)->withSchool($school, fn () => $this->createInventoryStockBalance($flour, $location, ['quantity_on_hand' => '1.000']));
        $this->setCanteenRecipeRequirement($school, $item, $flour, '0.500');

        $placed = $this->placeCanteenOrder($school, $student->id, $outlet->id, [
            ['canteenItemId' => $item->id, 'quantity' => 5], // needs 2.5kg, only 1kg available
        ]);

        try {
            $this->fulfillCanteenOrder($school, $placed->orderId);
            $this->fail('Expected InsufficientStockException.');
        } catch (InsufficientStockException) {
            // expected
        }

        $order = app(TenantContext::class)->withSchool($school, fn () => CanteenOrder::query()->find($placed->orderId));
        $this->assertSame('pending', $order->status);
        $this->assertNull($order->charge_id);

        $chargeCount = app(TenantContext::class)->withSchool($school, fn () => Charge::query()->where('student_id', $student->id)->count());
        $this->assertSame(0, $chargeCount);
    }
}
