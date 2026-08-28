<?php

namespace Tests\Feature\Postgres;

use App\Domain\Inventory\Infrastructure\StockMovement;
use App\Models\School;
use App\Support\Tenancy\TenantContext;
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
 * cross-School denials and the `unique(school_id, stock_movement_id)`
 * one-movement-per-order constraint.
 */
class CanteenOrderStockConsumptionsRlsIsolationTest extends TestCase
{
    use CreatesCanteenFixtures, CreatesTenancyFixtures;

    private function setSchool(string $schoolId): void
    {
        DB::connection('pgsql')->select('select set_config(?, ?, false)', [TenantRls::SESSION_VAR, $schoolId]);
    }

    private function issueMovement(School $school): StockMovement
    {
        return app(TenantContext::class)->withSchool($school, function () use ($school) {
            $location = $this->createInventoryLocation($school);
            $item = $this->createInventoryItem($school);

            return StockMovement::factory()->create([
                'school_id' => $school->id,
                'inventory_item_id' => $item->id,
                'movement_type' => 'issue',
                'from_location_id' => $location->id,
                'to_location_id' => null,
            ]);
        });
    }

    #[Test]
    public function the_table_has_rls_enabled_and_forced(): void
    {
        $row = DB::connection('pgsql_admin')->selectOne(
            'select relrowsecurity, relforcerowsecurity from pg_class '.
            'where relname = ? and relnamespace = ?::regnamespace',
            ['canteen_order_stock_consumptions', 'public'],
        );

        $this->assertNotNull($row);
        $this->assertTrue($row->relrowsecurity);
        $this->assertTrue($row->relforcerowsecurity);
    }

    #[Test]
    public function no_school_context_sees_zero_rows(): void
    {
        $school = $this->createSchool();
        $location = $this->createInventoryLocation($school);
        $outlet = $this->createCanteenOutlet($school, $location);
        $student = $this->createStudent($school);
        $order = $this->createCanteenOrderRaw($school, $student, $outlet);
        $movement = $this->issueMovement($school);
        $this->createCanteenOrderStockConsumptionRaw($school, $order, $movement);

        DB::connection('pgsql')->statement('RESET '.TenantRls::SESSION_VAR);

        $count = DB::connection('pgsql')->selectOne('select count(*) as c from canteen_order_stock_consumptions')->c;
        $this->assertSame(0, (int) $count);
    }

    #[Test]
    public function school_a_cannot_select_school_bs_consumption(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $locationB = $this->createInventoryLocation($schoolB);
        $outletB = $this->createCanteenOutlet($schoolB, $locationB);
        $studentB = $this->createStudent($schoolB);
        $orderB = $this->createCanteenOrderRaw($schoolB, $studentB, $outletB);
        $movementB = $this->issueMovement($schoolB);
        $consumptionB = $this->createCanteenOrderStockConsumptionRaw($schoolB, $orderB, $movementB);

        $this->setSchool($schoolA->id);

        $rows = DB::connection('pgsql')->select('select id from canteen_order_stock_consumptions where id = ?', [$consumptionB->id]);
        $this->assertCount(0, $rows);
    }

    #[Test]
    public function a_consumption_cannot_reference_an_order_from_a_different_school_at_the_database_level(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $locationB = $this->createInventoryLocation($schoolB);
        $outletB = $this->createCanteenOutlet($schoolB, $locationB);
        $studentB = $this->createStudent($schoolB);
        $orderB = $this->createCanteenOrderRaw($schoolB, $studentB, $outletB);
        $movementA = $this->issueMovement($schoolA);

        $rejected = false;
        try {
            DB::connection('pgsql_admin')->table('canteen_order_stock_consumptions')->insert([
                'id' => (string) new UuidV7,
                'school_id' => $schoolA->id,
                'canteen_order_id' => $orderB->id,
                'stock_movement_id' => $movementA->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (QueryException) {
            $rejected = true;
        }

        $this->assertTrue($rejected, 'canteen_order_stock_consumptions_order_fk must reject a cross-School Order reference.');
    }

    #[Test]
    public function a_consumption_cannot_reference_a_stock_movement_from_a_different_school_at_the_database_level(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $locationA = $this->createInventoryLocation($schoolA);
        $outletA = $this->createCanteenOutlet($schoolA, $locationA);
        $studentA = $this->createStudent($schoolA);
        $orderA = $this->createCanteenOrderRaw($schoolA, $studentA, $outletA);
        $movementB = $this->issueMovement($schoolB);

        $rejected = false;
        try {
            DB::connection('pgsql_admin')->table('canteen_order_stock_consumptions')->insert([
                'id' => (string) new UuidV7,
                'school_id' => $schoolA->id,
                'canteen_order_id' => $orderA->id,
                'stock_movement_id' => $movementB->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (QueryException) {
            $rejected = true;
        }

        $this->assertTrue($rejected, 'canteen_order_stock_consumptions_movement_fk must reject a cross-School StockMovement reference.');
    }

    #[Test]
    public function the_database_rejects_a_second_consumption_row_for_the_same_movement(): void
    {
        $school = $this->createSchool();
        $location = $this->createInventoryLocation($school);
        $outlet = $this->createCanteenOutlet($school, $location);
        $student = $this->createStudent($school);
        $orderA = $this->createCanteenOrderRaw($school, $student, $outlet);
        $orderB = $this->createCanteenOrderRaw($school, $student, $outlet);
        $movement = $this->issueMovement($school);
        $this->createCanteenOrderStockConsumptionRaw($school, $orderA, $movement);

        $this->setSchool($school->id);

        $rejected = false;
        try {
            DB::connection('pgsql')->table('canteen_order_stock_consumptions')->insert([
                'id' => (string) new UuidV7,
                'school_id' => $school->id,
                'canteen_order_id' => $orderB->id,
                'stock_movement_id' => $movement->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (QueryException) {
            $rejected = true;
        }

        $this->assertTrue($rejected, 'canteen_order_stock_consumptions_movement_unique must reject a second Consumption row claiming the same StockMovement.');
    }
}
