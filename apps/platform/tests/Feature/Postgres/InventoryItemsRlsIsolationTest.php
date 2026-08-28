<?php

namespace Tests\Feature\Postgres;

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\UuidV7;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 10E (mandatory per root CLAUDE.md rule 28). Also proves the
 * historical-integrity deletion protection for an Item referenced by
 * a current stock balance AND by historical StockMovement rows.
 */
class InventoryItemsRlsIsolationTest extends TestCase
{
    use CreatesTenancyFixtures;

    private function setSchool(string $schoolId): void
    {
        DB::connection('pgsql')->select('select set_config(?, ?, false)', [TenantRls::SESSION_VAR, $schoolId]);
    }

    #[Test]
    public function the_table_has_rls_enabled_and_forced(): void
    {
        $row = DB::connection('pgsql_admin')->selectOne(
            'select relrowsecurity, relforcerowsecurity from pg_class '.
            'where relname = ? and relnamespace = ?::regnamespace',
            ['inventory_items', 'public'],
        );

        $this->assertNotNull($row);
        $this->assertTrue($row->relrowsecurity);
        $this->assertTrue($row->relforcerowsecurity);
    }

    #[Test]
    public function no_school_context_sees_zero_rows(): void
    {
        $school = $this->createSchool();
        $this->createInventoryItem($school);

        DB::connection('pgsql')->statement('RESET '.TenantRls::SESSION_VAR);

        $count = DB::connection('pgsql')->selectOne('select count(*) as c from inventory_items')->c;
        $this->assertSame(0, (int) $count);
    }

    #[Test]
    public function school_a_cannot_select_school_bs_item(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $itemB = $this->createInventoryItem($schoolB);

        $this->setSchool($schoolA->id);

        $rows = DB::connection('pgsql')->select('select id from inventory_items where id = ?', [$itemB->id]);
        $this->assertCount(0, $rows);
    }

    #[Test]
    public function school_a_cannot_insert_an_item_for_school_b(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();

        $this->setSchool($schoolA->id);

        $rejected = false;

        try {
            DB::connection('pgsql')->table('inventory_items')->insert([
                'id' => (string) new UuidV7,
                'school_id' => $schoolB->id,
                'code' => 'CROSS-001',
                'name' => 'Cross School Item',
                'unit_of_measure' => 'each',
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (QueryException) {
            $rejected = true;
        }

        $this->assertTrue($rejected, 'RLS must reject an INSERT for a different School id under School A\'s session context.');
    }

    #[Test]
    public function school_a_cannot_update_school_bs_item(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $itemB = $this->createInventoryItem($schoolB);

        $this->setSchool($schoolA->id);

        $updated = DB::connection('pgsql')->update(
            'update inventory_items set status = ? where id = ?',
            ['inactive', $itemB->id],
        );

        $this->assertSame(0, $updated);
    }

    #[Test]
    public function an_item_referenced_by_a_stock_balance_cannot_be_deleted(): void
    {
        $school = $this->createSchool();
        $item = $this->createInventoryItem($school);
        $location = $this->createInventoryLocation($school);
        $this->createInventoryStockBalance($item, $location);

        $this->setSchool($school->id);

        $rejected = false;

        try {
            DB::connection('pgsql')->transaction(function () use ($item): void {
                DB::connection('pgsql')->table('inventory_items')->where('id', $item->id)->delete();
            });
        } catch (QueryException) {
            $rejected = true;
        }

        $this->assertTrue($rejected, 'inventory_stock_balances_inventory_item_id_school_id_foreign must RESTRICT deletion of an Item referenced by a stock balance.');

        $itemStillExists = DB::connection('pgsql')->table('inventory_items')->where('id', $item->id)->exists();
        $this->assertTrue($itemStillExists);
    }

    #[Test]
    public function an_item_referenced_by_a_stock_movement_cannot_be_deleted(): void
    {
        $school = $this->createSchool();
        $item = $this->createInventoryItem($school);
        $location = $this->createInventoryLocation($school);
        $balance = $this->createInventoryStockBalance($item, $location, ['quantity_on_hand' => '5.000']);

        $this->setSchool($school->id);

        DB::connection('pgsql')->table('stock_movements')->insert([
            'id' => (string) new UuidV7,
            'school_id' => $school->id,
            'inventory_item_id' => $item->id,
            'movement_type' => 'receipt',
            'from_location_id' => null,
            'to_location_id' => $location->id,
            'quantity' => '5.000',
            'occurred_at' => now(),
            'created_at' => now(),
        ]);

        $rejected = false;

        try {
            DB::connection('pgsql')->transaction(function () use ($item, $balance): void {
                DB::connection('pgsql')->table('inventory_stock_balances')->where('id', $balance->id)->delete();
                DB::connection('pgsql')->table('inventory_items')->where('id', $item->id)->delete();
            });
        } catch (QueryException) {
            $rejected = true;
        }

        $this->assertTrue($rejected, 'stock_movements_inventory_item_id_school_id_foreign must RESTRICT deletion of an Item referenced by historical StockMovement rows.');

        $itemStillExists = DB::connection('pgsql')->table('inventory_items')->where('id', $item->id)->exists();
        $this->assertTrue($itemStillExists);

        $movementStillExists = DB::connection('pgsql')->table('stock_movements')->where('inventory_item_id', $item->id)->exists();
        $this->assertTrue($movementStillExists);
    }
}
