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
 * ORG.7H-1 (ADR 0076 §54): the database freezes a used Inventory Location's
 * Campus for every writer, including raw SQL as the runtime role. Each
 * evidence column alone freezes it (A -> B, A -> NULL, NULL -> A); a no-op,
 * an unrelated field and an unused Location stay writable; same-School and
 * RLS protections are unchanged. Also pins the installed rule: one
 * predicate, the freeze trigger, one evidence guard per evidence table, and
 * every foreign key into inventory_locations classified.
 */
class InventoryLocationCampusFreezeDbTest extends TestCase
{
    use CreatesCanteenFixtures, CreatesTenancyFixtures;

    /** Every table holding a foreign key into inventory_locations: evidence (its Location columns) or configuration. */
    private const EVIDENCE = [
        'canteen_orders' => ['inventory_location_id'],
        'inventory_stock_balances' => ['inventory_location_id'],
        'stock_movements' => ['from_location_id', 'to_location_id'],
    ];

    /** Configuration, not history: an Outlet's Campus-bound composite key already pins its Location's Campus. */
    private const CONFIGURATION = ['canteen_outlets'];

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

    /** Raw runtime-role UPDATE inside a savepoint: the number of rows changed, or the refusal message. */
    private function rawCampus(InventoryLocation $location, ?string $campusId): int|string
    {
        try {
            return $this->runtime()->transaction(fn () => DB::connection('pgsql')->update('update inventory_locations set campus_id = ? where id = ?', [$campusId, $location->id]));
        } catch (QueryException $e) {
            return $e->getMessage();
        }
    }

    private function rawMovement(string $type, ?InventoryLocation $from, ?InventoryLocation $to): void
    {
        $this->runtime()->insert('insert into stock_movements (id, school_id, inventory_item_id, movement_type, from_location_id, to_location_id, quantity, occurred_at, created_at) values (?, ?, ?, ?, ?, ?, 1, now(), now())',
            [(string) new UuidV7, $this->school->id, $this->item->id, $type, $from?->id, $to?->id]);
    }

    private function used(InventoryLocation $location): bool
    {
        return (bool) $this->runtime()->selectOne('select inventory_location_has_operational_history(?, ?) as used', [$location->id, $this->school->id])->used;
    }

    private function assertFrozen(InventoryLocation $location, string $because): void
    {
        $this->assertTrue($this->used($location), $because);
        $was = $location->campus_id;
        $targets = $was === null ? [$this->a->id] : [$was === $this->a->id ? $this->b->id : $this->a->id, null];
        foreach ($targets as $target) {
            $this->assertStringContainsString('inventory_location_campus_frozen', (string) $this->rawCampus($location, $target), $because);
        }
        $this->assertSame(1, $this->rawCampus($location, $was), 'a no-op Campus write is not a change');
        $this->assertSame(1, $this->runtime()->update("update inventory_locations set name = 'Renamed', status = 'inactive' where id = ?", [$location->id]), 'other fields stay writable');
        $this->assertSame($was, $this->runtime()->selectOne('select campus_id from inventory_locations where id = ?', [$location->id])->campus_id);
    }

    #[Test]
    public function every_evidence_column_alone_freezes_the_campus_for_raw_runtime_sql(): void
    {
        $received = $this->location($this->a);
        $this->rawMovement('receipt', null, $received);
        $this->assertFrozen($received, 'stock_movements.to_location_id');

        $issued = $this->location($this->b);
        $this->rawMovement('issue', $issued, null);
        $this->assertFrozen($issued, 'stock_movements.from_location_id');

        $shared = $this->location(null);
        $this->createInventoryStockBalance($this->item, $shared, ['quantity_on_hand' => '0.000']);
        $this->assertFrozen($shared, 'inventory_stock_balances.inventory_location_id (NULL -> Campus refused)');

        $ordered = $this->location($this->a);
        $this->createCanteenOrderRaw($this->school, $this->createStudent($this->school), $this->createCanteenOutlet($this->school, $ordered));
        $this->assertFrozen($ordered, 'canteen_orders.inventory_location_id');
    }

    #[Test]
    public function an_unused_location_moves_freely_and_school_protections_are_unchanged(): void
    {
        $location = $this->location($this->a);
        $configured = $this->location($this->a);
        $this->createCanteenOutlet($this->school, $configured, ['campus_id' => null]);

        $this->assertFalse($this->used($location));
        $this->assertSame(1, $this->rawCampus($location, $this->b->id));
        $this->assertSame(1, $this->rawCampus($location, null));
        $this->assertSame(1, $this->rawCampus($location, $this->a->id));
        $this->assertSame(1, $this->rawCampus($configured, $this->b->id), 'an Outlet naming a Location is configuration, not history');

        $other = $this->createSchool();
        $this->assertStringContainsString('foreign key', (string) $this->rawCampus($location, $this->createCampus($other)->id), 'a Campus of another School is refused');

        $this->as = $other->id;
        $this->assertSame(0, $this->rawCampus($location, $this->b->id), 'another School cannot even see the Location');

        $rls = DB::connection('pgsql_admin')->selectOne("select relrowsecurity, relforcerowsecurity from pg_class where oid = 'public.inventory_locations'::regclass");
        $this->assertTrue($rls->relrowsecurity && $rls->relforcerowsecurity, 'RLS stays forced');
    }

    #[Test]
    public function the_installed_rule_is_one_predicate_one_freeze_and_one_guard_per_evidence_table(): void
    {
        $guards = collect(DB::select("select c.relname, pg_get_triggerdef(t.oid) as def, t.tgenabled from pg_trigger t join pg_class c on c.oid = t.tgrelid
            where t.tgname = 'trg_inventory_location_evidence' and not t.tgisinternal order by c.relname"));
        $this->assertSame(self::EVIDENCE, $guards->mapWithKeys(fn ($r) => [$r->relname => array_map(
            fn (string $arg) => trim($arg, " '"),
            explode(',', preg_replace('/.*inventory_location_evidence_guard\((.*)\)$/', '$1', $r->def)),
        )])->all());
        foreach ($guards as $guard) {
            $this->assertStringContainsString('BEFORE INSERT OR UPDATE OF', $guard->def);
        }

        $freeze = DB::selectOne("select pg_get_triggerdef(t.oid) as def, t.tgenabled from pg_trigger t where t.tgname = 'trg_inventory_location_campus_freeze' and t.tgrelid = 'inventory_locations'::regclass");
        $this->assertStringContainsString('BEFORE UPDATE OF campus_id ON public.inventory_locations FOR EACH ROW', $freeze->def);
        $this->assertSame(['O'], $guards->pluck('tgenabled')->push($freeze->tgenabled)->unique()->values()->all(), 'every trigger is enabled');

        $predicate = (string) DB::selectOne("select pg_get_functiondef('inventory_location_has_operational_history(uuid, uuid)'::regprocedure) as d")->d;
        foreach (self::EVIDENCE as $table => $columns) {
            foreach ($columns as $column) {
                $this->assertStringContainsString("e.{$column} = p_location", $predicate, "{$table}.{$column} is evidence");
            }
            $this->assertStringContainsString("public.{$table} e WHERE e.school_id = p_school", $predicate);
        }
        $this->assertStringContainsString('public.inventory_location_has_operational_history(OLD.id, OLD.school_id)',
            (string) DB::selectOne("select pg_get_functiondef('inventory_location_campus_freeze'::regproc) as d")->d, 'the freeze applies the one predicate');
        $this->assertStringContainsString('FOR SHARE', (string) DB::selectOne("select pg_get_functiondef('inventory_location_evidence_guard'::regproc) as d")->d);

        foreach (['inventory_location_has_operational_history', 'inventory_location_campus_freeze', 'inventory_location_evidence_guard'] as $function) {
            $fn = DB::selectOne("select p.prosecdef, p.provolatile, coalesce(array_to_string(p.proconfig, ','), '') as config,
                has_function_privilege('public', p.oid, 'EXECUTE') as public_exec from pg_proc p where p.proname = ?", [$function]);
            $this->assertFalse($fn->prosecdef, "{$function} runs as the caller (SECURITY INVOKER)");
            $this->assertSame('v', $fn->provolatile, "{$function} is VOLATILE: it sees rows committed by writers it waited for");
            $this->assertStringContainsString('search_path=pg_catalog, public, pg_temp', $fn->config);
            $this->assertFalse($fn->public_exec, "{$function} is not executable by PUBLIC");
        }
        $this->assertTrue((bool) DB::selectOne("select has_function_privilege('school_os_app', 'inventory_location_has_operational_history(uuid, uuid)', 'EXECUTE') as ok")->ok);
    }

    #[Test]
    public function every_foreign_key_into_inventory_locations_is_classified(): void
    {
        $referencing = collect(DB::select("select distinct c.conrelid::regclass::text as tbl from pg_constraint c
            where c.contype = 'f' and c.confrelid = 'public.inventory_locations'::regclass"))->pluck('tbl')->sort()->values()->all();

        $classified = [...array_keys(self::EVIDENCE), ...self::CONFIGURATION];
        sort($classified);
        $this->assertSame($classified, $referencing, 'a new table referencing a Location must be classified as operational evidence (predicate + guard) or configuration');

        // ORG.7H-1R (ADR 0076 §55): evidence must be monotonic -- the runtime role can never remove it.
        foreach (array_keys(self::EVIDENCE) as $table) {
            $this->assertContains($table, DatabaseRoleVerifier::NO_RUNTIME_DELETE, "{$table} is Location-use evidence: platform:verify-database must refuse a runtime DELETE grant");
        }
    }
}
