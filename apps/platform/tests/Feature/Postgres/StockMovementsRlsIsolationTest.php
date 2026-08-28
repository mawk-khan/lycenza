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
 * three composite-FK cross-School denials and every movement-shape
 * CHECK constraint (checkpoint brief section 12) at the raw-SQL
 * level -- movement shape is a database-level guarantee, never left
 * to controller validation alone.
 */
class StockMovementsRlsIsolationTest extends TestCase
{
    use CreatesTenancyFixtures;

    private function setSchool(string $schoolId): void
    {
        DB::connection('pgsql')->select('select set_config(?, ?, false)', [TenantRls::SESSION_VAR, $schoolId]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function insertMovement(array $overrides): void
    {
        DB::connection('pgsql')->table('stock_movements')->insert(array_merge([
            'id' => (string) new UuidV7,
            'quantity' => '1.000',
            'occurred_at' => now(),
            'created_at' => now(),
        ], $overrides));
    }

    #[Test]
    public function the_table_has_rls_enabled_and_forced(): void
    {
        $row = DB::connection('pgsql_admin')->selectOne(
            'select relrowsecurity, relforcerowsecurity from pg_class '.
            'where relname = ? and relnamespace = ?::regnamespace',
            ['stock_movements', 'public'],
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

        $this->setSchool($school->id);

        DB::connection('pgsql')->table('stock_movements')->insert([
            'id' => (string) new UuidV7,
            'school_id' => $school->id,
            'inventory_item_id' => $item->id,
            'movement_type' => 'receipt',
            'to_location_id' => $location->id,
            'quantity' => '1.000',
            'occurred_at' => now(),
            'created_at' => now(),
        ]);

        DB::connection('pgsql')->statement('RESET '.TenantRls::SESSION_VAR);

        $count = DB::connection('pgsql')->selectOne('select count(*) as c from stock_movements')->c;
        $this->assertSame(0, (int) $count);
    }

    #[Test]
    public function school_a_cannot_select_school_bs_movement(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $itemB = $this->createInventoryItem($schoolB);
        $locationB = $this->createInventoryLocation($schoolB);
        $movementId = (string) new UuidV7;

        $this->setSchool($schoolB->id);

        DB::connection('pgsql')->table('stock_movements')->insert([
            'id' => $movementId,
            'school_id' => $schoolB->id,
            'inventory_item_id' => $itemB->id,
            'movement_type' => 'receipt',
            'to_location_id' => $locationB->id,
            'quantity' => '1.000',
            'occurred_at' => now(),
            'created_at' => now(),
        ]);

        $this->setSchool($schoolA->id);

        $rows = DB::connection('pgsql')->select('select id from stock_movements where id = ?', [$movementId]);
        $this->assertCount(0, $rows);
    }

    #[Test]
    public function a_movement_cannot_reference_an_item_from_a_different_school_at_the_database_level(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $itemB = $this->createInventoryItem($schoolB);
        $locationA = $this->createInventoryLocation($schoolA);

        $this->setSchool($schoolA->id);

        $rejected = false;

        try {
            $this->insertMovement([
                'school_id' => $schoolA->id,
                'inventory_item_id' => $itemB->id,
                'movement_type' => 'receipt',
                'to_location_id' => $locationA->id,
            ]);
        } catch (QueryException) {
            $rejected = true;
        }

        $this->assertTrue($rejected, 'The composite FK (inventory_item_id, school_id) -> inventory_items(id, school_id) must reject a cross-School Item reference.');
    }

    #[Test]
    public function a_movement_cannot_reference_a_from_location_from_a_different_school_at_the_database_level(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $itemA = $this->createInventoryItem($schoolA);
        $locationB = $this->createInventoryLocation($schoolB);

        $this->setSchool($schoolA->id);

        $rejected = false;

        try {
            $this->insertMovement([
                'school_id' => $schoolA->id,
                'inventory_item_id' => $itemA->id,
                'movement_type' => 'issue',
                'from_location_id' => $locationB->id,
            ]);
        } catch (QueryException) {
            $rejected = true;
        }

        $this->assertTrue($rejected, 'The composite FK (from_location_id, school_id) -> inventory_locations(id, school_id) must reject a cross-School Location reference.');
    }

    #[Test]
    public function a_movement_cannot_reference_a_to_location_from_a_different_school_at_the_database_level(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $itemA = $this->createInventoryItem($schoolA);
        $locationB = $this->createInventoryLocation($schoolB);

        $this->setSchool($schoolA->id);

        $rejected = false;

        try {
            $this->insertMovement([
                'school_id' => $schoolA->id,
                'inventory_item_id' => $itemA->id,
                'movement_type' => 'receipt',
                'to_location_id' => $locationB->id,
            ]);
        } catch (QueryException) {
            $rejected = true;
        }

        $this->assertTrue($rejected, 'The composite FK (to_location_id, school_id) -> inventory_locations(id, school_id) must reject a cross-School Location reference.');
    }

    #[Test]
    public function the_database_rejects_a_non_positive_quantity(): void
    {
        $school = $this->createSchool();
        $item = $this->createInventoryItem($school);
        $location = $this->createInventoryLocation($school);

        $this->setSchool($school->id);

        $rejected = false;

        try {
            $this->insertMovement([
                'school_id' => $school->id,
                'inventory_item_id' => $item->id,
                'movement_type' => 'receipt',
                'to_location_id' => $location->id,
                'quantity' => '0.000',
            ]);
        } catch (QueryException) {
            $rejected = true;
        }

        $this->assertTrue($rejected, 'stock_movements_quantity_positive_check must reject a zero/negative quantity.');
    }

    #[Test]
    public function the_database_rejects_a_receipt_with_a_from_location(): void
    {
        $school = $this->createSchool();
        $item = $this->createInventoryItem($school);
        $location = $this->createInventoryLocation($school);

        $this->setSchool($school->id);

        $rejected = false;

        try {
            $this->insertMovement([
                'school_id' => $school->id,
                'inventory_item_id' => $item->id,
                'movement_type' => 'receipt',
                'from_location_id' => $location->id,
                'to_location_id' => $location->id,
            ]);
        } catch (QueryException) {
            $rejected = true;
        }

        $this->assertTrue($rejected, 'stock_movements_shape_check must reject a receipt with a from_location_id set.');
    }

    #[Test]
    public function the_database_rejects_an_issue_without_a_from_location(): void
    {
        $school = $this->createSchool();
        $item = $this->createInventoryItem($school);

        $this->setSchool($school->id);

        $rejected = false;

        try {
            $this->insertMovement([
                'school_id' => $school->id,
                'inventory_item_id' => $item->id,
                'movement_type' => 'issue',
                'from_location_id' => null,
            ]);
        } catch (QueryException) {
            $rejected = true;
        }

        $this->assertTrue($rejected, 'stock_movements_shape_check must reject an issue without a from_location_id.');
    }

    #[Test]
    public function the_database_rejects_a_transfer_missing_either_location(): void
    {
        $school = $this->createSchool();
        $item = $this->createInventoryItem($school);
        $location = $this->createInventoryLocation($school);

        $this->setSchool($school->id);

        $rejected = false;

        try {
            $this->insertMovement([
                'school_id' => $school->id,
                'inventory_item_id' => $item->id,
                'movement_type' => 'transfer',
                'from_location_id' => $location->id,
                'to_location_id' => null,
            ]);
        } catch (QueryException) {
            $rejected = true;
        }

        $this->assertTrue($rejected, 'stock_movements_shape_check must reject a transfer missing to_location_id.');
    }

    #[Test]
    public function the_database_rejects_a_transfer_to_the_same_location(): void
    {
        $school = $this->createSchool();
        $item = $this->createInventoryItem($school);
        $location = $this->createInventoryLocation($school);

        $this->setSchool($school->id);

        $rejected = false;

        try {
            $this->insertMovement([
                'school_id' => $school->id,
                'inventory_item_id' => $item->id,
                'movement_type' => 'transfer',
                'from_location_id' => $location->id,
                'to_location_id' => $location->id,
            ]);
        } catch (QueryException) {
            $rejected = true;
        }

        $this->assertTrue($rejected, 'stock_movements_transfer_locations_differ_check must reject a transfer where from_location_id = to_location_id.');
    }

    #[Test]
    public function the_database_rejects_an_unrecognized_movement_type(): void
    {
        $school = $this->createSchool();
        $item = $this->createInventoryItem($school);
        $location = $this->createInventoryLocation($school);

        $this->setSchool($school->id);

        $rejected = false;

        try {
            $this->insertMovement([
                'school_id' => $school->id,
                'inventory_item_id' => $item->id,
                'movement_type' => 'adjustment',
                'to_location_id' => $location->id,
            ]);
        } catch (QueryException) {
            $rejected = true;
        }

        $this->assertTrue($rejected, 'stock_movements_movement_type_check must reject a movement_type outside receipt|issue|transfer -- no arbitrary movement type may be submitted.');
    }
}
