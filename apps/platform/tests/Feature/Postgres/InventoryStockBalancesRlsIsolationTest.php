<?php

namespace Tests\Feature\Postgres;

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\UuidV7;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 10E (mandatory per root CLAUDE.md rule 28). Also proves the
 * two composite-FK cross-School denials, the
 * `(school_id, inventory_item_id, inventory_location_id)` uniqueness
 * constraint (the conflict target the concurrency-safe balance
 * resolver depends on), and the `quantity_on_hand >= 0` CHECK
 * constraint (the negative-stock invariant's final structural
 * backstop).
 */
class InventoryStockBalancesRlsIsolationTest extends TestCase
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
            ['inventory_stock_balances', 'public'],
        );

        $this->assertNotNull($row);
        $this->assertTrue($row->relrowsecurity);
        $this->assertTrue($row->relforcerowsecurity);
    }

    #[Test]
    public function no_school_context_sees_zero_rows(): void
    {
        $school = $this->createSchool();
        $item = $this->createInventoryItem($school);
        $location = $this->createInventoryLocation($school);
        $this->createInventoryStockBalance($item, $location);

        DB::connection('pgsql')->statement('RESET '.TenantRls::SESSION_VAR);

        $count = DB::connection('pgsql')->selectOne('select count(*) as c from inventory_stock_balances')->c;
        $this->assertSame(0, (int) $count);
    }

    #[Test]
    public function school_a_cannot_select_school_bs_balance(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $itemB = $this->createInventoryItem($schoolB);
        $locationB = $this->createInventoryLocation($schoolB);
        $balanceB = $this->createInventoryStockBalance($itemB, $locationB);

        $this->setSchool($schoolA->id);

        $rows = DB::connection('pgsql')->select('select id from inventory_stock_balances where id = ?', [$balanceB->id]);
        $this->assertCount(0, $rows);
    }

    #[Test]
    public function school_a_cannot_update_school_bs_balance(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $itemB = $this->createInventoryItem($schoolB);
        $locationB = $this->createInventoryLocation($schoolB);
        $balanceB = $this->createInventoryStockBalance($itemB, $locationB);

        $this->setSchool($schoolA->id);

        $updated = DB::connection('pgsql')->update(
            'update inventory_stock_balances set quantity_on_hand = ? where id = ?',
            ['999.000', $balanceB->id],
        );

        $this->assertSame(0, $updated);
    }

    #[Test]
    public function a_balance_cannot_reference_an_item_from_a_different_school_at_the_database_level(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $itemB = $this->createInventoryItem($schoolB);
        $locationA = $this->createInventoryLocation($schoolA);

        $rejected = false;

        try {
            DB::connection('pgsql_admin')->table('inventory_stock_balances')->insert([
                'id' => (string) new UuidV7,
                'school_id' => $schoolA->id,
                'inventory_item_id' => $itemB->id,
                'inventory_location_id' => $locationA->id,
                'quantity_on_hand' => '0.000',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (QueryException) {
            $rejected = true;
        }

        $this->assertTrue($rejected, 'The composite FK (inventory_item_id, school_id) -> inventory_items(id, school_id) must reject a cross-School Item reference.');
    }

    #[Test]
    public function a_balance_cannot_reference_a_location_from_a_different_school_at_the_database_level(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $itemA = $this->createInventoryItem($schoolA);
        $locationB = $this->createInventoryLocation($schoolB);

        $rejected = false;

        try {
            DB::connection('pgsql_admin')->table('inventory_stock_balances')->insert([
                'id' => (string) new UuidV7,
                'school_id' => $schoolA->id,
                'inventory_item_id' => $itemA->id,
                'inventory_location_id' => $locationB->id,
                'quantity_on_hand' => '0.000',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (QueryException) {
            $rejected = true;
        }

        $this->assertTrue($rejected, 'The composite FK (inventory_location_id, school_id) -> inventory_locations(id, school_id) must reject a cross-School Location reference.');
    }

    #[Test]
    public function the_database_rejects_a_second_balance_row_for_the_same_item_and_location(): void
    {
        $school = $this->createSchool();
        $item = $this->createInventoryItem($school);
        $location = $this->createInventoryLocation($school);
        $this->createInventoryStockBalance($item, $location);

        $this->setSchool($school->id);

        $rejected = false;

        try {
            DB::connection('pgsql')->table('inventory_stock_balances')->insert([
                'id' => (string) new UuidV7,
                'school_id' => $school->id,
                'inventory_item_id' => $item->id,
                'inventory_location_id' => $location->id,
                'quantity_on_hand' => '0.000',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            $rejected = true;
        }

        $this->assertTrue($rejected, 'inventory_stock_balances_item_location_unique must reject a second balance row for the same (school_id, item, location) -- this is the conflict target the concurrency-safe balance resolver depends on.');
    }

    #[Test]
    public function the_database_rejects_a_negative_quantity_on_hand(): void
    {
        $school = $this->createSchool();
        $item = $this->createInventoryItem($school);
        $location = $this->createInventoryLocation($school);

        $this->setSchool($school->id);

        $rejected = false;

        try {
            DB::connection('pgsql')->table('inventory_stock_balances')->insert([
                'id' => (string) new UuidV7,
                'school_id' => $school->id,
                'inventory_item_id' => $item->id,
                'inventory_location_id' => $location->id,
                'quantity_on_hand' => '-1.000',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (QueryException) {
            $rejected = true;
        }

        $this->assertTrue($rejected, 'inventory_stock_balances_quantity_non_negative_check must reject a negative quantity_on_hand -- the negative-stock invariant\'s final structural backstop.');
    }
}
