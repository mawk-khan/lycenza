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
 * Campus composite-FK cross-School rejection and the historical-
 * integrity deletion protection for a Location referenced by a
 * current stock balance and by historical StockMovement rows (both
 * `from_location_id` and `to_location_id`).
 */
class InventoryLocationsRlsIsolationTest extends TestCase
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
            ['inventory_locations', 'public'],
        );

        $this->assertNotNull($row);
        $this->assertTrue($row->relrowsecurity);
        $this->assertTrue($row->relforcerowsecurity);
    }

    #[Test]
    public function no_school_context_sees_zero_rows(): void
    {
        $school = $this->createSchool();
        $this->createInventoryLocation($school);

        DB::connection('pgsql')->statement('RESET '.TenantRls::SESSION_VAR);

        $count = DB::connection('pgsql')->selectOne('select count(*) as c from inventory_locations')->c;
        $this->assertSame(0, (int) $count);
    }

    #[Test]
    public function school_a_cannot_select_school_bs_location(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $locationB = $this->createInventoryLocation($schoolB);

        $this->setSchool($schoolA->id);

        $rows = DB::connection('pgsql')->select('select id from inventory_locations where id = ?', [$locationB->id]);
        $this->assertCount(0, $rows);
    }

    #[Test]
    public function school_a_cannot_update_school_bs_location(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $locationB = $this->createInventoryLocation($schoolB);

        $this->setSchool($schoolA->id);

        $updated = DB::connection('pgsql')->update(
            'update inventory_locations set status = ? where id = ?',
            ['inactive', $locationB->id],
        );

        $this->assertSame(0, $updated);
    }

    #[Test]
    public function a_location_cannot_reference_a_campus_from_a_different_school_at_the_database_level(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $campusB = $this->createCampus($schoolB);

        $rejected = false;

        try {
            DB::connection('pgsql_admin')->table('inventory_locations')->insert([
                'id' => (string) new UuidV7,
                'school_id' => $schoolA->id,
                'campus_id' => $campusB->id,
                'code' => 'CROSS-LOC-001',
                'name' => 'Cross School Location',
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (QueryException) {
            $rejected = true;
        }

        $this->assertTrue($rejected, 'The composite FK (campus_id, school_id) -> campuses(id, school_id) must reject a cross-School Campus reference.');
    }

    #[Test]
    public function a_location_with_a_null_campus_id_is_accepted(): void
    {
        $school = $this->createSchool();

        $location = $this->createInventoryLocation($school, ['campus_id' => null]);

        $this->assertNull($location->campus_id);
    }

    #[Test]
    public function a_location_referenced_by_a_stock_balance_cannot_be_deleted(): void
    {
        $school = $this->createSchool();
        $item = $this->createInventoryItem($school);
        $location = $this->createInventoryLocation($school);
        $this->createInventoryStockBalance($item, $location);

        $this->setSchool($school->id);

        $rejected = false;

        try {
            DB::connection('pgsql')->transaction(function () use ($location): void {
                DB::connection('pgsql')->table('inventory_locations')->where('id', $location->id)->delete();
            });
        } catch (QueryException) {
            $rejected = true;
        }

        $this->assertTrue($rejected, 'inventory_stock_balances_inventory_location_id_school_id_foreign must RESTRICT deletion of a Location referenced by a stock balance.');

        $locationStillExists = DB::connection('pgsql')->table('inventory_locations')->where('id', $location->id)->exists();
        $this->assertTrue($locationStillExists);
    }

    #[Test]
    public function a_location_referenced_as_a_movements_to_location_cannot_be_deleted(): void
    {
        $school = $this->createSchool();
        $item = $this->createInventoryItem($school);
        $location = $this->createInventoryLocation($school);

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
            DB::connection('pgsql')->transaction(function () use ($location): void {
                DB::connection('pgsql')->table('inventory_locations')->where('id', $location->id)->delete();
            });
        } catch (QueryException) {
            $rejected = true;
        }

        $this->assertTrue($rejected, 'stock_movements_to_location_id_school_id_foreign must RESTRICT deletion of a Location referenced as a movement\'s destination.');

        $movementStillExists = DB::connection('pgsql')->table('stock_movements')->where('to_location_id', $location->id)->exists();
        $this->assertTrue($movementStillExists);
    }
}
