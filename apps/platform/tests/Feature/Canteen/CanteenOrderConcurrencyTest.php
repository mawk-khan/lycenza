<?php

namespace Tests\Feature\Canteen;

use App\Domain\Canteen\Infrastructure\CanteenItemInventoryRequirement;
use App\Domain\Canteen\Infrastructure\CanteenOrder;
use App\Domain\Canteen\Infrastructure\CanteenOrderStockConsumption;
use App\Domain\Fees\Infrastructure\Charge;
use App\Domain\Inventory\Infrastructure\InventoryStockBalance;
use App\Domain\Inventory\Infrastructure\StockMovement;
use App\Models\School;
use App\Support\Tenancy\TenantContext;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;
use Tests\Concerns\CreatesCanteenFixtures;
use Tests\Concerns\CreatesFinanceFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * The FOUR mandatory real-concurrency proofs for Phase 10F -- two
 * GENUINELY separate OS processes, never two sequential calls in one
 * PHP process. Mirrors InventoryStockConcurrencyTest's exact pattern,
 * including `protected $connectionsToTransact = []` (the subprocesses
 * are separate PostgreSQL sessions and can never see this test
 * process's otherwise-uncommitted fixture rows) and cleanup via
 * `$school->delete()` in tearDown().
 */
class CanteenOrderConcurrencyTest extends TestCase
{
    use CreatesCanteenFixtures, CreatesFinanceFixtures, CreatesTenancyFixtures;

    /** @var array<int, string> */
    protected $connectionsToTransact = [];

    private ?School $school = null;

    protected function tearDown(): void
    {
        if ($this->school !== null) {
            $this->deleteSchoolAsAdmin($this->school); // cascades canteen_*/inventory_*/charges/journal_entries
        }

        parent::tearDown();
    }

    private function setUpBilling(School $school): void
    {
        $receivable = $this->createLedgerAccount($school, ['type' => 'asset']);
        $revenue = $this->createLedgerAccount($school, ['type' => 'income']);
        $this->configureCanteenBilling($school, $receivable, $revenue);
    }

    /**
     * A. Double-fulfillment: two concurrent `fulfill()` calls for the
     * SAME pending Order. Exactly one must succeed; the loser is
     * caught by its own post-lock status re-check
     * (CanteenOrderAlreadyFulfilledException). Stock and Charge must
     * each be affected exactly once, never twice.
     */
    #[Test]
    public function two_real_concurrent_processes_fulfilling_the_same_order_succeed_exactly_once(): void
    {
        $this->school = $this->createSchool();
        $school = $this->school;
        $context = app(TenantContext::class);

        $this->createAcademicYear($school, ['status' => 'active']);
        $this->setUpBilling($school);
        $location = $this->createInventoryLocation($school);
        $outlet = $this->createCanteenOutlet($school, $location);
        $student = $this->createStudent($school);
        $item = $this->createCanteenItem($school, ['price' => '20.00']);
        $flour = $this->createInventoryItem($school, ['unit_of_measure' => 'kg']);
        $context->withSchool($school, fn () => $this->createInventoryStockBalance($flour, $location, ['quantity_on_hand' => '10.000']));
        $this->setCanteenRecipeRequirement($school, $item, $flour, '1.000');

        $placed = $this->placeCanteenOrder($school, $student->id, $outlet->id, [
            ['canteenItemId' => $item->id, 'quantity' => 1],
        ]);

        $script = __DIR__.'/../../Support/fulfill-canteen-order.php';
        $processA = new Process(['php', $script, $school->id, $placed->orderId]);
        $processB = new Process(['php', $script, $school->id, $placed->orderId]);
        $processA->start();
        $processB->start();
        $processA->wait();
        $processB->wait();

        $outputs = [$processA->getOutput(), $processB->getOutput()];
        $fulfilledCount = count(array_filter($outputs, fn ($o) => str_starts_with($o, 'fulfilled:')));
        $this->assertSame(1, $fulfilledCount, 'Exactly one of the two concurrent fulfill() calls must succeed. Outputs: '.implode(' | ', $outputs));

        $rejected = array_values(array_filter($outputs, fn ($o) => ! str_starts_with($o, 'fulfilled:')));
        $this->assertCount(1, $rejected);
        $this->assertStringContainsString('CanteenOrderAlreadyFulfilledException', $rejected[0]);

        $order = $context->withSchool($school, fn () => CanteenOrder::query()->find($placed->orderId));
        $this->assertSame('fulfilled', $order->status);

        $chargeCount = $context->withSchool($school, fn () => Charge::query()->where('student_id', $student->id)->count());
        $this->assertSame(1, $chargeCount, 'Exactly one Charge must exist.');

        $balance = $context->withSchool($school, fn () => InventoryStockBalance::query()->where('inventory_item_id', $flour->id)->where('inventory_location_id', $location->id)->first());
        $this->assertSame('9.000', $balance->quantity_on_hand, 'Stock must be decremented exactly once (10 - 1 = 9), never twice.');

        $consumptionCount = $context->withSchool($school, fn () => CanteenOrderStockConsumption::query()->where('canteen_order_id', $placed->orderId)->count());
        $this->assertSame(1, $consumptionCount);
    }

    /**
     * B. Two orders racing a scarce shared ingredient: only 5kg on
     * hand, each Order needs 4kg -- only one can succeed. Stock must
     * never go negative; the loser stays pending with zero Charge/zero
     * Consumption.
     */
    #[Test]
    public function two_orders_racing_scarce_shared_stock_leave_exactly_one_fulfilled_and_stock_never_negative(): void
    {
        $this->school = $this->createSchool();
        $school = $this->school;
        $context = app(TenantContext::class);

        $this->createAcademicYear($school, ['status' => 'active']);
        $this->setUpBilling($school);
        $location = $this->createInventoryLocation($school);
        $outlet = $this->createCanteenOutlet($school, $location);
        $studentA = $this->createStudent($school);
        $studentB = $this->createStudent($school);
        $item = $this->createCanteenItem($school, ['price' => '15.00']);
        $rice = $this->createInventoryItem($school, ['unit_of_measure' => 'kg']);
        $context->withSchool($school, fn () => $this->createInventoryStockBalance($rice, $location, ['quantity_on_hand' => '5.000']));
        $this->setCanteenRecipeRequirement($school, $item, $rice, '4.000');

        $placedA = $this->placeCanteenOrder($school, $studentA->id, $outlet->id, [['canteenItemId' => $item->id, 'quantity' => 1]]);
        $placedB = $this->placeCanteenOrder($school, $studentB->id, $outlet->id, [['canteenItemId' => $item->id, 'quantity' => 1]]);

        $script = __DIR__.'/../../Support/fulfill-canteen-order.php';
        $processA = new Process(['php', $script, $school->id, $placedA->orderId]);
        $processB = new Process(['php', $script, $school->id, $placedB->orderId]);
        $processA->start();
        $processB->start();
        $processA->wait();
        $processB->wait();

        $outputs = [$processA->getOutput(), $processB->getOutput()];
        $fulfilledCount = count(array_filter($outputs, fn ($o) => str_starts_with($o, 'fulfilled:')));
        $this->assertSame(1, $fulfilledCount, 'Exactly one of the two Orders must be fulfillable given only 5kg for two 4kg requirements. Outputs: '.implode(' | ', $outputs));

        $rejected = array_values(array_filter($outputs, fn ($o) => ! str_starts_with($o, 'fulfilled:')));
        $this->assertCount(1, $rejected);
        $this->assertStringContainsString('InsufficientStockException', $rejected[0]);

        $balance = $context->withSchool($school, fn () => InventoryStockBalance::query()->where('inventory_item_id', $rice->id)->where('inventory_location_id', $location->id)->first());
        $this->assertSame('1.000', $balance->quantity_on_hand, 'Exactly one 4kg consumption must have occurred (5 - 4 = 1), stock must never go negative.');

        $orderA = $context->withSchool($school, fn () => CanteenOrder::query()->find($placedA->orderId));
        $orderB = $context->withSchool($school, fn () => CanteenOrder::query()->find($placedB->orderId));
        $statuses = [$orderA->status, $orderB->status];
        sort($statuses);
        $this->assertSame(['fulfilled', 'pending'], $statuses);

        $loserOrder = $orderA->status === 'pending' ? $orderA : $orderB;
        $this->assertNull($loserOrder->charge_id);
        $loserConsumptions = $context->withSchool($school, fn () => CanteenOrderStockConsumption::query()->where('canteen_order_id', $loserOrder->id)->count());
        $this->assertSame(0, $loserConsumptions, 'The losing Order must have zero Consumption rows.');
    }

    /**
     * C. Cancel-vs-fulfill race on the same pending Order: exactly one
     * terminal transition wins, never a contradictory state (fulfilled
     * AND cancelled, or a Charge on a cancelled Order).
     */
    #[Test]
    public function cancel_and_fulfill_racing_the_same_order_produce_exactly_one_terminal_state(): void
    {
        $this->school = $this->createSchool();
        $school = $this->school;
        $context = app(TenantContext::class);

        $this->createAcademicYear($school, ['status' => 'active']);
        $this->setUpBilling($school);
        $location = $this->createInventoryLocation($school);
        $outlet = $this->createCanteenOutlet($school, $location);
        $student = $this->createStudent($school);
        $item = $this->createCanteenItem($school, ['price' => '10.00']);

        $placed = $this->placeCanteenOrder($school, $student->id, $outlet->id, [
            ['canteenItemId' => $item->id, 'quantity' => 1],
        ]);

        $fulfillScript = __DIR__.'/../../Support/fulfill-canteen-order.php';
        $cancelScript = __DIR__.'/../../Support/cancel-canteen-order.php';
        $processFulfill = new Process(['php', $fulfillScript, $school->id, $placed->orderId]);
        $processCancel = new Process(['php', $cancelScript, $school->id, $placed->orderId]);
        $processFulfill->start();
        $processCancel->start();
        $processFulfill->wait();
        $processCancel->wait();

        $fulfillOutput = $processFulfill->getOutput();
        $cancelOutput = $processCancel->getOutput();

        $order = $context->withSchool($school, fn () => CanteenOrder::query()->find($placed->orderId));

        if (str_starts_with($fulfillOutput, 'fulfilled:')) {
            $this->assertSame('fulfilled', $order->status);
            $this->assertNotNull($order->charge_id);
            $this->assertNull($order->cancelled_at);
            $this->assertStringContainsString('CanteenOrderFulfilledCannotBeCancelledException', $cancelOutput);
        } else {
            $this->assertSame('cancelled', $order->status);
            $this->assertNull($order->charge_id);
            $this->assertNotNull($order->cancelled_at);
            $this->assertSame('cancelled', $cancelOutput);
            $this->assertStringContainsString('CanteenOrderCancelledCannotBeFulfilledException', $fulfillOutput);
        }

        // Never both a charge AND a cancellation timestamp.
        $this->assertFalse($order->charge_id !== null && $order->cancelled_at !== null, 'An Order must never simultaneously carry a charge_id and a cancelled_at.');
    }

    /**
     * D. Recipe-mutation-vs-fulfillment race: a concurrent recipe
     * update (0.100 -> 0.500) and a fulfillment reading that same
     * Item's recipe must never produce a torn read -- the consumed
     * quantity must match EXACTLY ONE of the two recipe versions
     * (whichever one's CanteenItem row lock was acquired first), never
     * a mixed/inconsistent value, and never more than one StockMovement.
     */
    #[Test]
    public function a_concurrent_recipe_mutation_and_fulfillment_never_produce_a_torn_read(): void
    {
        $this->school = $this->createSchool();
        $school = $this->school;
        $context = app(TenantContext::class);

        $this->createAcademicYear($school, ['status' => 'active']);
        $this->setUpBilling($school);
        $location = $this->createInventoryLocation($school);
        $outlet = $this->createCanteenOutlet($school, $location);
        $student = $this->createStudent($school);
        $item = $this->createCanteenItem($school, ['price' => '10.00']);
        $flour = $this->createInventoryItem($school, ['unit_of_measure' => 'kg']);
        $context->withSchool($school, fn () => $this->createInventoryStockBalance($flour, $location, ['quantity_on_hand' => '10.000']));
        $this->setCanteenRecipeRequirement($school, $item, $flour, '0.100');

        $placed = $this->placeCanteenOrder($school, $student->id, $outlet->id, [
            ['canteenItemId' => $item->id, 'quantity' => 1],
        ]);

        $fulfillScript = __DIR__.'/../../Support/fulfill-canteen-order.php';
        $recipeScript = __DIR__.'/../../Support/set-canteen-recipe-requirement.php';
        $processFulfill = new Process(['php', $fulfillScript, $school->id, $placed->orderId]);
        $processRecipe = new Process(['php', $recipeScript, $school->id, $item->id, $flour->id, '0.500']);
        $processFulfill->start();
        $processRecipe->start();
        $processFulfill->wait();
        $processRecipe->wait();

        $this->assertStringStartsWith('fulfilled:', $processFulfill->getOutput(), 'Fulfillment must succeed regardless of which recipe version it observes.');
        $this->assertSame('set', $processRecipe->getOutput(), 'The recipe update must always succeed -- it is never itself in a lifecycle conflict.');

        $movements = $context->withSchool($school, fn () => StockMovement::query()->where('inventory_item_id', $flour->id)->where('movement_type', 'issue')->get());
        $this->assertCount(1, $movements, 'Exactly one issue StockMovement must exist -- never a torn/duplicate read.');

        $consumedQuantity = $movements->first()->quantity;
        $this->assertContains($consumedQuantity, ['0.100', '0.500'], "Consumed quantity must match exactly one whole recipe version, got {$consumedQuantity}.");

        $consumption = $context->withSchool($school, fn () => CanteenOrderStockConsumption::query()->where('canteen_order_id', $placed->orderId)->first());
        $this->assertNotNull($consumption);
        $this->assertSame($movements->first()->id, $consumption->stock_movement_id, 'The Consumption row must link to exactly the movement that was actually created.');

        $finalRequirement = $context->withSchool($school, fn () => CanteenItemInventoryRequirement::query()->where('canteen_item_id', $item->id)->first());
        $this->assertSame('0.500', $finalRequirement->quantity_required, 'The recipe update itself must always land, regardless of fulfillment ordering.');
    }
}
