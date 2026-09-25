<?php

namespace Tests\Feature\Inventory;

use App\Domain\Inventory\Infrastructure\InventoryStockBalance;
use App\Domain\Inventory\Infrastructure\StockMovement;
use App\Models\School;
use App\Support\Tenancy\TenantContext;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * The THREE mandatory real-concurrency proofs for Phase 10E
 * (docs/modules/INVENTORY.md "Concurrency evidence") -- two GENUINELY
 * separate OS processes, never two sequential calls in one PHP
 * process. Mirrors HostelResidencyConcurrencyTest's exact pattern,
 * including `protected $connectionsToTransact = []` (the subprocesses
 * are separate PostgreSQL sessions and can never see this test
 * process's otherwise-uncommitted fixture rows) and cleanup via
 * `$school->delete()` in tearDown().
 */
class InventoryStockConcurrencyTest extends TestCase
{
    use CreatesTenancyFixtures;

    /** @var array<int, string> */
    protected $connectionsToTransact = [];

    private ?School $school = null;

    protected function tearDown(): void
    {
        if ($this->school !== null) {
            $this->deleteSchoolAsAdmin($this->school); // cascades inventory_items/inventory_locations/inventory_stock_balances/stock_movements
        }

        parent::tearDown();
    }

    /**
     * A. Over-issue: initial stock 10, two concurrent `issue(7)` calls
     * for the same Item x Location. Exactly one must succeed; the
     * loser is caught by its own post-lock "insufficient stock" check.
     * Final balance must be exactly 3, never negative.
     */
    #[Test]
    public function two_real_concurrent_processes_issuing_seven_from_a_stock_of_ten_leave_exactly_three(): void
    {
        $this->school = $this->createSchool();
        $context = app(TenantContext::class);

        $item = $this->createInventoryItem($this->school);
        $location = $this->createInventoryLocation($this->school);
        $context->withSchool($this->school, fn () => InventoryStockBalance::factory()->create([
            'school_id' => $this->school->id,
            'inventory_item_id' => $item->id,
            'inventory_location_id' => $location->id,
            'quantity_on_hand' => '10.000',
        ]));

        $script = __DIR__.'/../../Support/issue-inventory-stock.php';
        $processA = new Process(['php', $script, $this->school->id, $item->id, $location->id, '7']);
        $processB = new Process(['php', $script, $this->school->id, $item->id, $location->id, '7']);
        $processA->start();
        $processB->start();
        $processA->wait();
        $processB->wait();

        $outputs = [$processA->getOutput(), $processB->getOutput()];
        $issuedCount = count(array_filter($outputs, fn ($o) => str_starts_with($o, 'issued:')));

        $this->assertSame(1, $issuedCount, 'Exactly one of the two concurrent issue(7) calls must succeed.');
        $rejected = array_values(array_filter($outputs, fn ($o) => ! str_starts_with($o, 'issued:')));
        $this->assertCount(1, $rejected);
        $this->assertStringContainsString('InsufficientStockException', $rejected[0]);

        $balance = $context->withSchool(
            $this->school,
            fn () => InventoryStockBalance::query()->where('inventory_item_id', $item->id)->where('inventory_location_id', $location->id)->first(),
        );
        $this->assertSame('3.000', $balance->quantity_on_hand, 'Final balance must be exactly 3, never negative.');

        $issuedMovements = $context->withSchool(
            $this->school,
            fn () => StockMovement::query()->where('inventory_item_id', $item->id)->where('movement_type', 'issue')->count(),
        );
        $this->assertSame(1, $issuedMovements, 'Exactly one successful issue StockMovement must exist.');
    }

    /**
     * B. Concurrent first receipts: no balance row exists yet for this
     * Item x Location. Two concurrent `receive()` calls must both
     * succeed, resolve to the SAME canonical balance row (never a
     * duplicate-key error surfaced to either caller -- the
     * `INSERT ... ON CONFLICT DO NOTHING` primitive), and the final
     * balance must equal the sum of both receipts with no lost update.
     */
    #[Test]
    public function two_real_concurrent_first_ever_receipts_to_the_same_item_and_location_lose_no_update(): void
    {
        $this->school = $this->createSchool();
        $context = app(TenantContext::class);

        $item = $this->createInventoryItem($this->school);
        $location = $this->createInventoryLocation($this->school);

        // Deliberately NO balance row created here -- proves the
        // concurrency-safe missing-row primitive (checkpoint brief
        // section 21).
        $existsBefore = $context->withSchool(
            $this->school,
            fn () => InventoryStockBalance::query()->where('inventory_item_id', $item->id)->exists(),
        );
        $this->assertFalse($existsBefore);

        $script = __DIR__.'/../../Support/receive-inventory-stock.php';
        $processA = new Process(['php', $script, $this->school->id, $item->id, $location->id, '4']);
        $processB = new Process(['php', $script, $this->school->id, $item->id, $location->id, '6']);
        $processA->start();
        $processB->start();
        $processA->wait();
        $processB->wait();

        $outputs = [$processA->getOutput(), $processB->getOutput()];
        $this->assertCount(2, array_filter($outputs, fn ($o) => str_starts_with($o, 'received:')), 'Both concurrent first-ever receipts must succeed -- no unique-constraint leak to the client.');

        $balances = $context->withSchool(
            $this->school,
            fn () => InventoryStockBalance::query()->where('inventory_item_id', $item->id)->where('inventory_location_id', $location->id)->get(),
        );
        $this->assertCount(1, $balances, 'No duplicate balance row -- both processes must resolve to the same canonical row.');
        $this->assertSame('10.000', $balances->first()->quantity_on_hand, 'Final balance must equal the sum of both receipts (4 + 6) -- no lost update.');

        $receiptMovements = $context->withSchool(
            $this->school,
            fn () => StockMovement::query()->where('inventory_item_id', $item->id)->where('movement_type', 'receipt')->count(),
        );
        $this->assertSame(2, $receiptMovements, 'Both successful receipt StockMovements must exist.');
    }

    /**
     * C. Opposing concurrent transfers: Location A and Location B both
     * hold sufficient stock; process 1 transfers A->B while process 2
     * concurrently transfers B->A for the SAME Item. Both must
     * complete successfully with no deadlock -- proven by the
     * deterministic ascending-balance-id lock order (never a fixed
     * source-then-destination role order, which WOULD deadlock this
     * exact scenario). A bounded subprocess timeout makes a genuine
     * deadlock fail the test deterministically rather than hang the
     * suite.
     */
    #[Test]
    public function opposing_concurrent_transfers_between_two_locations_both_succeed_without_deadlock(): void
    {
        $this->school = $this->createSchool();
        $context = app(TenantContext::class);

        $item = $this->createInventoryItem($this->school);
        $locationA = $this->createInventoryLocation($this->school);
        $locationB = $this->createInventoryLocation($this->school);

        $context->withSchool($this->school, function () use ($item, $locationA, $locationB) {
            InventoryStockBalance::factory()->create([
                'school_id' => $this->school->id,
                'inventory_item_id' => $item->id,
                'inventory_location_id' => $locationA->id,
                'quantity_on_hand' => '20.000',
            ]);
            InventoryStockBalance::factory()->create([
                'school_id' => $this->school->id,
                'inventory_item_id' => $item->id,
                'inventory_location_id' => $locationB->id,
                'quantity_on_hand' => '20.000',
            ]);
        });

        $script = __DIR__.'/../../Support/transfer-inventory-stock.php';
        $processA = new Process(['php', $script, $this->school->id, $item->id, $locationA->id, $locationB->id, '5']);
        $processB = new Process(['php', $script, $this->school->id, $item->id, $locationB->id, $locationA->id, '5']);
        $processA->setTimeout(15);
        $processB->setTimeout(15);
        $processA->start();
        $processB->start();
        $processA->wait();
        $processB->wait();

        $outputs = [$processA->getOutput(), $processB->getOutput()];
        $this->assertCount(2, array_filter($outputs, fn ($o) => str_starts_with($o, 'transferred:')), 'Both opposing concurrent transfers must succeed -- no deadlock. Outputs: '.implode(' | ', $outputs));

        $balanceA = $context->withSchool(
            $this->school,
            fn () => InventoryStockBalance::query()->where('inventory_item_id', $item->id)->where('inventory_location_id', $locationA->id)->first(),
        );
        $balanceB = $context->withSchool(
            $this->school,
            fn () => InventoryStockBalance::query()->where('inventory_item_id', $item->id)->where('inventory_location_id', $locationB->id)->first(),
        );

        // A: 20 - 5 (A->B) + 5 (B->A) = 20. B: symmetric = 20.
        $this->assertSame('20.000', $balanceA->quantity_on_hand);
        $this->assertSame('20.000', $balanceB->quantity_on_hand);

        $transferMovements = $context->withSchool(
            $this->school,
            fn () => StockMovement::query()->where('inventory_item_id', $item->id)->where('movement_type', 'transfer')->count(),
        );
        $this->assertSame(2, $transferMovements, 'Both transfer StockMovements must exist.');
    }

    /**
     * D. Opposing concurrent issueMany() calls: two DIFFERENT Items
     * (X, Y) both held at sufficient quantity in ONE Location. Process
     * 1 calls `issueMany($location, [X, Y])`; process 2 concurrently
     * calls `issueMany($location, [Y, X])` -- the exact SAME two
     * balance rows, requested in OPPOSITE array order. A fixed
     * "requirement array order" lock order WOULD deadlock this
     * (process 1 holds X's lock waiting for Y while process 2 holds
     * Y's lock waiting for X); `lockBalancesInDeterministicOrder()`'s
     * ascending-balance-id order is what makes both calls complete
     * without deadlock -- generalizing `transfer()`'s own opposing-
     * transfers proof above from 2 rows to N. A bounded subprocess
     * timeout makes a genuine deadlock fail the test deterministically
     * rather than hang the suite.
     */
    #[Test]
    public function opposing_concurrent_issue_many_calls_for_the_same_two_items_in_opposite_order_do_not_deadlock(): void
    {
        $this->school = $this->createSchool();
        $context = app(TenantContext::class);

        $itemX = $this->createInventoryItem($this->school);
        $itemY = $this->createInventoryItem($this->school);
        $location = $this->createInventoryLocation($this->school);

        $context->withSchool($this->school, function () use ($itemX, $itemY, $location) {
            InventoryStockBalance::factory()->create([
                'school_id' => $this->school->id,
                'inventory_item_id' => $itemX->id,
                'inventory_location_id' => $location->id,
                'quantity_on_hand' => '20.000',
            ]);
            InventoryStockBalance::factory()->create([
                'school_id' => $this->school->id,
                'inventory_item_id' => $itemY->id,
                'inventory_location_id' => $location->id,
                'quantity_on_hand' => '20.000',
            ]);
        });

        $script = __DIR__.'/../../Support/issue-many-inventory-stock.php';
        // Process A: issueMany([X, Y]). Process B: issueMany([Y, X]) --
        // the same two balance rows, opposite requested order.
        $processA = new Process(['php', $script, $this->school->id, $location->id, $itemX->id, '5', $itemY->id, '3']);
        $processB = new Process(['php', $script, $this->school->id, $location->id, $itemY->id, '4', $itemX->id, '2']);
        $processA->setTimeout(15);
        $processB->setTimeout(15);
        $processA->start();
        $processB->start();
        $processA->wait();
        $processB->wait();

        $outputs = [$processA->getOutput(), $processB->getOutput()];
        $this->assertCount(2, array_filter($outputs, fn ($o) => str_starts_with($o, 'issued:')), 'Both opposing concurrent issueMany() calls must succeed -- no deadlock. Outputs: '.implode(' | ', $outputs));

        $balanceX = $context->withSchool(
            $this->school,
            fn () => InventoryStockBalance::query()->where('inventory_item_id', $itemX->id)->where('inventory_location_id', $location->id)->first(),
        );
        $balanceY = $context->withSchool(
            $this->school,
            fn () => InventoryStockBalance::query()->where('inventory_item_id', $itemY->id)->where('inventory_location_id', $location->id)->first(),
        );

        // X: 20 - 5 (A) - 2 (B) = 13. Y: 20 - 3 (A) - 4 (B) = 13.
        $this->assertSame('13.000', $balanceX->quantity_on_hand);
        $this->assertSame('13.000', $balanceY->quantity_on_hand);

        $issueMovements = $context->withSchool(
            $this->school,
            fn () => StockMovement::query()->whereIn('inventory_item_id', [$itemX->id, $itemY->id])->where('movement_type', 'issue')->count(),
        );
        $this->assertSame(4, $issueMovements, 'Both issueMany() calls together must have created exactly 4 issue StockMovements (2 items x 2 calls).');
    }
}
