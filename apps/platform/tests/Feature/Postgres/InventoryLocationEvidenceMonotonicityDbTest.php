<?php

namespace Tests\Feature\Postgres;

use App\Domain\Inventory\Infrastructure\InventoryItem;
use App\Domain\Inventory\Infrastructure\InventoryLocation;
use App\Models\Campus;
use App\Models\School;
use App\Support\Operations\DatabaseRoleVerifier;
use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\UuidV7;
use Tests\Concerns\CreatesCanteenFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * ORG.7H-1R (ADR 0076 §55): "used" is monotonic. The ORG.7H-1 Campus freeze
 * is only as strong as the evidence its one predicate reads, so the runtime
 * role must not be able to erase or move that evidence: once a Location has
 * committed operational history it never becomes eligible for a Campus
 * change again. For each evidence source independently: make an unused
 * Location used, attempt every runtime-accessible mutation that could erase
 * or move the evidence (DELETE, TRUNCATE, re-pointing the Location, moving
 * the row to another School, deleting a parent), then attempt the Campus
 * change -- still refused. Raw SQL as the runtime role throughout.
 */
class InventoryLocationEvidenceMonotonicityDbTest extends TestCase
{
    use CreatesCanteenFixtures, CreatesTenancyFixtures;

    private School $school;

    private Campus $a;

    private Campus $b;

    private InventoryItem $item;

    /** The runtime session's tenant context for the raw statements (fixtures reset it). */
    private string $as;

    protected function setUp(): void
    {
        parent::setUp();
        $this->school = $this->createSchool();
        $this->a = $this->createCampus($this->school);
        $this->b = $this->createCampus($this->school);
        $this->item = $this->createInventoryItem($this->school);
        $this->as = $this->school->id;
    }

    private function runtime(): Connection
    {
        $db = DB::connection('pgsql');
        $db->select('select set_config(?, ?, false)', [TenantRls::SESSION_VAR, $this->as]);

        return $db;
    }

    private function location(?Campus $campus): InventoryLocation
    {
        return $this->createInventoryLocation($this->school, ['campus_id' => $campus?->id]);
    }

    /**
     * One raw runtime-role statement inside a savepoint: the rows it changed,
     * 'denied' (no privilege), 'refused' (the evidence guard) or the message.
     *
     * @param  list<mixed>  $bindings
     */
    private function attempt(string $sql, array $bindings = []): int|string
    {
        try {
            return $this->runtime()->transaction(fn () => DB::connection('pgsql')->affectingStatement($sql, $bindings));
        } catch (QueryException $e) {
            return match (true) {
                str_contains($e->getMessage(), 'permission denied') => 'denied',
                str_contains($e->getMessage(), 'inventory_location_evidence_immutable') => 'refused',
                default => $e->getMessage(),
            };
        }
    }

    private function used(InventoryLocation $location): bool
    {
        return (bool) $this->runtime()->selectOne('select inventory_location_has_operational_history(?, ?) as used', [$location->id, $this->school->id])->used;
    }

    /** Still used, and every Campus change (A -> B / A -> NULL, or NULL -> A) is refused. */
    private function assertStillFrozen(InventoryLocation $location, string $because): void
    {
        $this->assertTrue($this->used($location), "{$because}: the Location is still used");
        $was = $location->campus_id;
        $targets = $was === null ? [$this->a->id] : [$was === $this->a->id ? $this->b->id : $this->a->id, null];
        foreach ($targets as $target) {
            $result = $this->attempt('update inventory_locations set campus_id = ? where id = ?', [$target, $location->id]);
            $this->assertIsString($result, "{$because}: the Campus change must be refused");
            $this->assertStringContainsString('inventory_location_campus_frozen', $result, $because);
        }
        $this->assertSame($was, $this->runtime()->selectOne('select campus_id from inventory_locations where id = ?', [$location->id])->campus_id);
    }

    /**
     * @param  array<string, string>  $expected  label => expected refusal
     * @param  array<string, int|string>  $actual  label => attempt() result, run in order
     */
    private function assertOutcomes(array $expected, array $actual, string $source): void
    {
        foreach ($expected as $label => $outcome) {
            $this->assertIsString($actual[$label], "{$source} {$label}: changed ".$actual[$label].' row(s) -- the evidence was erased or moved');
            $this->assertStringContainsString($outcome, $actual[$label], "{$source} {$label}");
        }
    }

    #[Test]
    public function stock_movement_evidence_cannot_be_erased_or_moved(): void
    {
        foreach (['to_location_id' => 'receipt', 'from_location_id' => 'issue'] as $column => $type) {
            $location = $this->location($this->a);
            $elsewhere = $this->location($this->a);
            $id = (string) new UuidV7;
            $this->runtime()->insert('insert into stock_movements (id, school_id, inventory_item_id, movement_type, from_location_id, to_location_id, quantity, occurred_at, created_at) values (?, ?, ?, ?, ?, ?, 1, now(), now())',
                [$id, $this->school->id, $this->item->id, $type, $type === 'issue' ? $location->id : null, $type === 'receipt' ? $location->id : null]);
            $this->assertTrue($this->used($location));

            $this->assertOutcomes([
                'repoint' => 'denied',
                'detach' => 'denied',
                'requantify' => 'denied',
                'delete' => 'denied',
                'truncate' => 'denied',
            ], [
                'repoint' => $this->attempt("update stock_movements set {$column} = ? where id = ?", [$elsewhere->id, $id]),
                'detach' => $this->attempt("update stock_movements set {$column} = null where id = ?", [$id]),
                'requantify' => $this->attempt('update stock_movements set quantity = 2 where id = ?', [$id]),
                'delete' => $this->attempt('delete from stock_movements where id = ?', [$id]),
                'truncate' => $this->attempt('truncate stock_movements'),
            ], "stock_movements.{$column}");

            $this->assertSame($location->id, $this->runtime()->selectOne("select {$column} as l from stock_movements where id = ?", [$id])->l);
            $this->assertStillFrozen($location, "stock_movements.{$column}");
        }
    }

    #[Test]
    public function stock_balance_evidence_cannot_be_erased_or_moved_while_its_quantity_stays_current_state(): void
    {
        $location = $this->location(null);
        $elsewhere = $this->location(null);
        $balance = $this->createInventoryStockBalance($this->item, $location, ['quantity_on_hand' => '3.000']);
        $this->assertTrue($this->used($location));

        $this->assertOutcomes([
            'repoint' => 'refused',
            'delete' => 'denied',
            'truncate' => 'denied',
            'another School' => 'row-level security',
        ], [
            'repoint' => $this->attempt('update inventory_stock_balances set inventory_location_id = ? where id = ?', [$elsewhere->id, $balance->id]),
            'delete' => $this->attempt('delete from inventory_stock_balances where id = ?', [$balance->id]),
            'truncate' => $this->attempt('truncate inventory_stock_balances'),
            'another School' => $this->attempt('update inventory_stock_balances set school_id = ? where id = ?', [$this->createSchool()->id, $balance->id]),
        ], 'inventory_stock_balances');

        $this->assertSame(1, $this->attempt('update inventory_stock_balances set quantity_on_hand = 0 where id = ?', [$balance->id]), 'the quantity is current state and stays writable');
        $this->assertSame(1, $this->attempt('update inventory_stock_balances set inventory_location_id = inventory_location_id where id = ?', [$balance->id]), 'a no-op Location write is not a change');
        $this->assertStillFrozen($location, 'inventory_stock_balances.inventory_location_id (a zero balance is still use)');
    }

    #[Test]
    public function a_canteen_orders_historical_location_cannot_be_erased_or_moved(): void
    {
        $location = $this->location($this->a);
        $elsewhere = $this->location($this->b);
        $outlet = $this->createCanteenOutlet($this->school, $location);
        $order = $this->createCanteenOrderRaw($this->school, $this->createStudent($this->school), $outlet);
        $this->assertTrue($this->used($location));

        $this->assertOutcomes([
            'repoint' => 'refused',
            'delete' => 'denied',
            'truncate' => 'denied',
            'another School' => 'row-level security',
        ], [
            'repoint' => $this->attempt('update canteen_orders set inventory_location_id = ? where id = ?', [$elsewhere->id, $order->id]),
            'delete' => $this->attempt('delete from canteen_orders where id = ?', [$order->id]),
            'truncate' => $this->attempt('truncate canteen_orders'),
            'another School' => $this->attempt('update canteen_orders set school_id = ? where id = ?', [$this->createSchool()->id, $order->id]),
        ], 'canteen_orders');

        $this->assertSame(1, $this->attempt("update canteen_orders set status = 'cancelled', cancelled_at = now() where id = ?", [$order->id]), 'the Order lifecycle (cancel) stays writable');
        $this->assertSame($location->id, $this->runtime()->selectOne('select inventory_location_id as l from canteen_orders where id = ?', [$order->id])->l);
        $this->assertStillFrozen($location, 'canteen_orders.inventory_location_id (a cancelled Order is history too)');
    }

    #[Test]
    public function no_parent_deletion_cascades_the_evidence_away(): void
    {
        $location = $this->location($this->a);
        $this->runtime()->insert("insert into stock_movements (id, school_id, inventory_item_id, movement_type, to_location_id, quantity, occurred_at, created_at) values (?, ?, ?, 'receipt', ?, 1, now(), now())",
            [(string) new UuidV7, $this->school->id, $this->item->id, $location->id]);
        $this->createInventoryStockBalance($this->item, $location);
        $outlet = $this->createCanteenOutlet($this->school, $location);
        $this->createCanteenOrderRaw($this->school, $this->createStudent($this->school), $outlet);

        $this->assertSame('denied', $this->attempt('delete from schools where id = ?', [$this->school->id]), 'the School (whose key cascades) is never runtime-deleted');
        foreach ([['inventory_items', $this->item->id], ['inventory_locations', $location->id], ['canteen_outlets', $outlet->id]] as [$table, $id]) {
            $this->assertStringContainsString('foreign key', (string) $this->attempt("delete from {$table} where id = ?", [$id]), "{$table} is RESTRICTed by its history");
        }
        $this->assertStillFrozen($location, 'every evidence source at once');
    }

    #[Test]
    public function the_evidence_relations_are_monotonic_by_privilege_and_trigger(): void
    {
        $privileges = fn (string $table) => collect(['INSERT', 'UPDATE', 'DELETE', 'TRUNCATE'])
            ->mapWithKeys(fn (string $p) => [$p => (bool) DB::selectOne('select has_table_privilege(?, ?, ?) as p', ['school_os_app', 'public.'.$table, $p])->p])->all();

        $this->assertSame(['INSERT' => true, 'UPDATE' => false, 'DELETE' => false, 'TRUNCATE' => false], $privileges('stock_movements'), 'the movement ledger is append-only for the runtime role');
        $this->assertSame(['INSERT' => true, 'UPDATE' => true, 'DELETE' => false, 'TRUNCATE' => false], $privileges('inventory_stock_balances'), 'a balance row is never removed; its quantity stays current state');
        $this->assertSame(['INSERT' => true, 'UPDATE' => true, 'DELETE' => false, 'TRUNCATE' => false], $privileges('canteen_orders'), 'an Order is never removed; its lifecycle stays writable');

        foreach (['stock_movements', 'inventory_stock_balances', 'canteen_orders'] as $table) {
            $this->assertContains($table, DatabaseRoleVerifier::NO_RUNTIME_DELETE, 'platform:verify-database checks it');
            $this->assertNotSame('school_os_app', DB::selectOne('select relowner::regrole::text as o from pg_class where oid = ?::regclass', ['public.'.$table])->o, 'the runtime role cannot disable a trigger');
        }
        $this->assertContains('stock_movements', DatabaseRoleVerifier::NO_RUNTIME_UPDATE);

        $guard = DB::selectOne("select pg_get_functiondef('inventory_location_evidence_guard'::regproc) as d, pg_get_userbyid(proowner) as owner from pg_proc where proname = 'inventory_location_evidence_guard'");
        $this->assertStringContainsString('inventory_location_evidence_immutable', $guard->d, 'an UPDATE never moves evidence to another Location');
        $this->assertStringContainsString('FOR SHARE', $guard->d, 'first use still serializes with a Campus change');
        $this->assertNotSame('school_os_app', $guard->owner);
    }
}
