<?php

namespace Tests\Feature\Postgres;

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\UuidV7;
use Tests\Concerns\CreatesCanteenFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 10F (CLAUDE.md rule 28). Also proves both composite-FK
 * cross-School denials, the no-duplicate-recipe-line uniqueness
 * constraint, and the `quantity_required > 0` CHECK constraint.
 */
class CanteenItemInventoryRequirementsRlsIsolationTest extends TestCase
{
    use CreatesCanteenFixtures, CreatesTenancyFixtures;

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
            ['canteen_item_inventory_requirements', 'public'],
        );

        $this->assertNotNull($row);
        $this->assertTrue($row->relrowsecurity);
        $this->assertTrue($row->relforcerowsecurity);
    }

    #[Test]
    public function no_school_context_sees_zero_rows(): void
    {
        $school = $this->createSchool();
        $item = $this->createCanteenItem($school);
        $inventoryItem = $this->createInventoryItem($school);
        $this->createCanteenItemInventoryRequirementRaw($school, $item, $inventoryItem);

        DB::connection('pgsql')->statement('RESET '.TenantRls::SESSION_VAR);

        $count = DB::connection('pgsql')->selectOne('select count(*) as c from canteen_item_inventory_requirements')->c;
        $this->assertSame(0, (int) $count);
    }

    #[Test]
    public function school_a_cannot_select_school_bs_requirement(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $itemB = $this->createCanteenItem($schoolB);
        $inventoryItemB = $this->createInventoryItem($schoolB);
        $requirementB = $this->createCanteenItemInventoryRequirementRaw($schoolB, $itemB, $inventoryItemB);

        $this->setSchool($schoolA->id);

        $rows = DB::connection('pgsql')->select('select id from canteen_item_inventory_requirements where id = ?', [$requirementB->id]);
        $this->assertCount(0, $rows);
    }

    #[Test]
    public function a_requirement_cannot_reference_a_canteen_item_from_a_different_school_at_the_database_level(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $itemB = $this->createCanteenItem($schoolB);
        $inventoryItemA = $this->createInventoryItem($schoolA);

        $rejected = false;
        try {
            DB::connection('pgsql_admin')->table('canteen_item_inventory_requirements')->insert([
                'id' => (string) new UuidV7,
                'school_id' => $schoolA->id,
                'canteen_item_id' => $itemB->id,
                'inventory_item_id' => $inventoryItemA->id,
                'quantity_required' => '1.000',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (QueryException) {
            $rejected = true;
        }

        $this->assertTrue($rejected, 'canteen_item_inv_req_item_fk must reject a cross-School CanteenItem reference.');
    }

    #[Test]
    public function a_requirement_cannot_reference_an_inventory_item_from_a_different_school_at_the_database_level(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $itemA = $this->createCanteenItem($schoolA);
        $inventoryItemB = $this->createInventoryItem($schoolB);

        $rejected = false;
        try {
            DB::connection('pgsql_admin')->table('canteen_item_inventory_requirements')->insert([
                'id' => (string) new UuidV7,
                'school_id' => $schoolA->id,
                'canteen_item_id' => $itemA->id,
                'inventory_item_id' => $inventoryItemB->id,
                'quantity_required' => '1.000',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (QueryException) {
            $rejected = true;
        }

        $this->assertTrue($rejected, 'canteen_item_inv_req_inv_item_fk must reject a cross-School InventoryItem reference.');
    }

    #[Test]
    public function the_database_rejects_a_duplicate_recipe_line(): void
    {
        $school = $this->createSchool();
        $item = $this->createCanteenItem($school);
        $inventoryItem = $this->createInventoryItem($school);
        $this->createCanteenItemInventoryRequirementRaw($school, $item, $inventoryItem);

        $this->setSchool($school->id);

        $rejected = false;
        try {
            DB::connection('pgsql')->table('canteen_item_inventory_requirements')->insert([
                'id' => (string) new UuidV7,
                'school_id' => $school->id,
                'canteen_item_id' => $item->id,
                'inventory_item_id' => $inventoryItem->id,
                'quantity_required' => '2.000',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (QueryException) {
            $rejected = true;
        }

        $this->assertTrue($rejected, 'canteen_item_inventory_requirements_unique must reject a duplicate (canteen_item_id, inventory_item_id) recipe line.');
    }

    #[Test]
    public function the_database_rejects_a_non_positive_quantity_required(): void
    {
        $school = $this->createSchool();
        $item = $this->createCanteenItem($school);
        $inventoryItem = $this->createInventoryItem($school);
        $this->setSchool($school->id);

        $rejected = false;
        try {
            DB::connection('pgsql')->table('canteen_item_inventory_requirements')->insert([
                'id' => (string) new UuidV7,
                'school_id' => $school->id,
                'canteen_item_id' => $item->id,
                'inventory_item_id' => $inventoryItem->id,
                'quantity_required' => '0.000',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (QueryException) {
            $rejected = true;
        }

        $this->assertTrue($rejected, 'canteen_item_inv_req_quantity_positive_check must reject a zero/negative quantity_required.');
    }
}
