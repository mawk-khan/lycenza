<?php

namespace Tests\Feature\Canteen;

use App\Domain\Canteen\Application\CanteenOrderService;
use App\Domain\Canteen\Infrastructure\CanteenOrder;
use App\Domain\Canteen\Infrastructure\CanteenOrderStockConsumption;
use App\Domain\Fees\Infrastructure\Charge;
use App\Domain\Inventory\Infrastructure\InventoryStockBalance;
use App\Domain\Inventory\Infrastructure\StockMovement;
use App\Models\School;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCanteenFixtures;
use Tests\Concerns\CreatesFinanceFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 10F -- the mandatory cross-domain atomicity proof: a REAL
 * (never mocked) failure injected at the Charge-creation step, AFTER
 * InventoryStockService::issueMany() has already decremented stock and
 * inserted StockMovement rows within the SAME outer transaction, must
 * roll back EVERYTHING -- the Order stays pending, no Charge, no
 * StockMovement, no Consumption row survives.
 *
 * Mechanism: `withFailingTrigger()` mirrors
 * Tests\Feature\Finance\LedgerServiceAtomicityTest's exact established
 * pattern -- a TEMPORARY, uniquely-named `BEFORE INSERT` trigger (via
 * `pgsql_admin`) on the `charges` table that unconditionally
 * `RAISE EXCEPTION`s, installed only for the duration of one callback,
 * removed in a `finally` block regardless of outcome. No production
 * schema/migration is touched.
 *
 * Deliberately does NOT use DatabaseTransactions ($connectionsToTransact
 * = []) for the identical reason LedgerServiceAtomicityTest gives:
 * `CREATE TRIGGER` needs a lock that would self-deadlock against this
 * same PHP process's own still-open transaction on the default
 * connection. Manual cleanup (deleting the created School, which
 * cascades) replaces the automatic rollback.
 */
class CanteenOrderFulfillmentAtomicityTest extends TestCase
{
    use CreatesCanteenFixtures, CreatesFinanceFixtures, CreatesTenancyFixtures;

    /** @var array<int, string> */
    protected $connectionsToTransact = [];

    private ?School $school = null;

    protected function tearDown(): void
    {
        if ($this->school !== null) {
            $this->school->delete(); // cascades canteen_*/inventory_*/charges/journal_entries
        }

        parent::tearDown();
    }

    /**
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    private function withFailingTrigger(string $table, callable $callback): mixed
    {
        $suffix = str_replace('-', '_', (string) Str::uuid());
        $functionName = "test_inject_failure_{$suffix}";
        $triggerName = "test_inject_failure_trigger_{$suffix}";

        DB::connection('pgsql_admin')->statement(
            "CREATE FUNCTION {$functionName}() RETURNS trigger AS ".
            '$body$ BEGIN RAISE EXCEPTION \'test-injected failure\'; END; $body$ '.
            'LANGUAGE plpgsql'
        );

        DB::connection('pgsql_admin')->statement(
            "CREATE TRIGGER {$triggerName} BEFORE INSERT ON {$table} FOR EACH ROW EXECUTE FUNCTION {$functionName}()"
        );

        try {
            return $callback();
        } finally {
            DB::connection('pgsql_admin')->statement("DROP TRIGGER IF EXISTS {$triggerName} ON {$table}");
            DB::connection('pgsql_admin')->statement("DROP FUNCTION IF EXISTS {$functionName}()");
        }
    }

    #[Test]
    public function a_charge_insert_failure_after_inventory_has_already_been_decremented_rolls_back_everything(): void
    {
        $this->school = $this->createSchool();
        $school = $this->school;

        $this->createAcademicYear($school, ['status' => 'active']);
        $receivable = $this->createLedgerAccount($school, ['type' => 'asset']);
        $revenue = $this->createLedgerAccount($school, ['type' => 'income']);
        $this->configureCanteenBilling($school, $receivable, $revenue);

        $location = $this->createInventoryLocation($school);
        $outlet = $this->createCanteenOutlet($school, $location);
        $student = $this->createStudent($school);
        $item = $this->createCanteenItem($school, ['price' => '25.00']);
        $flour = $this->createInventoryItem($school, ['unit_of_measure' => 'kg']);
        app(TenantContext::class)->withSchool($school, fn () => $this->createInventoryStockBalance($flour, $location, ['quantity_on_hand' => '10.000']));
        $this->setCanteenRecipeRequirement($school, $item, $flour, '0.500');

        $placed = $this->placeCanteenOrder($school, $student->id, $outlet->id, [
            ['canteenItemId' => $item->id, 'quantity' => 2],
        ]);

        try {
            $this->withFailingTrigger('charges', function () use ($school, $placed) {
                app(TenantContext::class)->withSchool(
                    $school,
                    fn () => app(CanteenOrderService::class)->fulfill($school, $placed->orderId),
                );
            });
            $this->fail('Expected a QueryException from the injected charges-table failure.');
        } catch (QueryException) {
            // expected -- the injected failure
        }

        $state = app(TenantContext::class)->withSchool($school, function () use ($placed, $flour, $location) {
            $order = CanteenOrder::query()->find($placed->orderId);
            $balance = InventoryStockBalance::query()
                ->where('inventory_item_id', $flour->id)
                ->where('inventory_location_id', $location->id)
                ->first();

            return [
                'orderStatus' => $order->status,
                'orderChargeId' => $order->charge_id,
                'balanceQuantity' => $balance?->quantity_on_hand,
                'movementCount' => StockMovement::query()->where('inventory_item_id', $flour->id)->count(),
                'consumptionCount' => CanteenOrderStockConsumption::query()->where('canteen_order_id', $placed->orderId)->count(),
                'chargeCount' => Charge::query()->where('student_id', $placed->studentId)->count(),
            ];
        });

        $this->assertSame('pending', $state['orderStatus'], 'The Order must stay pending -- the whole transaction rolled back.');
        $this->assertNull($state['orderChargeId']);
        $this->assertSame('10.000', $state['balanceQuantity'], 'The already-issued stock decrement must be rolled back -- balance must be untouched.');
        $this->assertSame(0, $state['movementCount'], 'No StockMovement may survive the rollback.');
        $this->assertSame(0, $state['consumptionCount'], 'No Consumption row may survive the rollback.');
        $this->assertSame(0, $state['chargeCount'], 'No Charge may survive the rollback.');
    }
}
