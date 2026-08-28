<?php

namespace Tests\Feature\Postgres;

use App\Domain\Inventory\Infrastructure\StockMovement;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantRls;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCanteenFixtures;
use Tests\Concerns\CreatesFeesFixtures;
use Tests\Concerns\CreatesFinanceFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 10F -- raw-SQL proof that every entity a real Canteen Order
 * references (Outlet, CanteenItem, Student, Charge, InventoryLocation,
 * StockMovement) cannot be hard-deleted at the database level once
 * referenced, via the composite FK RESTRICT constraints declared on
 * `canteen_orders`/`canteen_order_lines`/`canteen_order_stock_consumptions`.
 * `canteen_outlets`' own referenced-deletion proof lives in
 * CanteenOutletsRlsIsolationTest already; this file covers the
 * remaining five referenced entities. Every insert/delete pair runs on
 * the SAME ('pgsql') connection/transaction the fixtures were created
 * on -- see CanteenOutletsRlsIsolationTest's own docblock for why a
 * separate `pgsql_admin` session cannot see this test's still-
 * uncommitted rows.
 */
class CanteenHistoricalNonDeletionTest extends TestCase
{
    use CreatesCanteenFixtures, CreatesFeesFixtures, CreatesFinanceFixtures, CreatesTenancyFixtures;

    private function setSchool(string $schoolId): void
    {
        DB::connection('pgsql')->select('select set_config(?, ?, false)', [TenantRls::SESSION_VAR, $schoolId]);
    }

    #[Test]
    public function a_canteen_item_referenced_by_an_order_line_cannot_be_deleted(): void
    {
        $school = $this->createSchool();
        $location = $this->createInventoryLocation($school);
        $outlet = $this->createCanteenOutlet($school, $location);
        $student = $this->createStudent($school);
        $item = $this->createCanteenItem($school);
        $order = $this->createCanteenOrderRaw($school, $student, $outlet);
        $this->createCanteenOrderLineRaw($school, $order, $item);

        $this->setSchool($school->id);

        $rejected = false;
        try {
            DB::connection('pgsql')->delete('delete from canteen_items where id = ?', [$item->id]);
        } catch (QueryException) {
            $rejected = true;
        }

        $this->assertTrue($rejected, 'canteen_order_lines_item_fk RESTRICT must reject deleting a CanteenItem a real Order line still references.');
    }

    #[Test]
    public function a_student_referenced_by_an_order_cannot_be_deleted(): void
    {
        $school = $this->createSchool();
        $location = $this->createInventoryLocation($school);
        $outlet = $this->createCanteenOutlet($school, $location);
        $student = $this->createStudent($school);
        $this->createCanteenOrderRaw($school, $student, $outlet);

        $this->setSchool($school->id);

        $rejected = false;
        try {
            DB::connection('pgsql')->delete('delete from students where id = ?', [$student->id]);
        } catch (QueryException) {
            $rejected = true;
        }

        $this->assertTrue($rejected, 'canteen_orders_student_fk RESTRICT must reject deleting a Student a real Order still references.');
    }

    #[Test]
    public function a_charge_referenced_by_a_fulfilled_order_cannot_be_deleted(): void
    {
        $school = $this->createSchool();
        $location = $this->createInventoryLocation($school);
        $outlet = $this->createCanteenOutlet($school, $location);
        $student = $this->createStudent($school);
        $academicYear = $this->createAcademicYear($school, ['status' => 'active']);
        $receivable = $this->createLedgerAccount($school, ['type' => 'asset']);
        $revenue = $this->createLedgerAccount($school, ['type' => 'income']);
        $charge = $this->assessCharge($school, $student, $academicYear, $receivable, $revenue);
        $this->createCanteenOrderRaw($school, $student, $outlet, [
            'status' => 'fulfilled', 'charge_id' => $charge->id, 'fulfilled_at' => now(),
        ]);

        $this->setSchool($school->id);

        $rejected = false;
        try {
            DB::connection('pgsql')->delete('delete from charges where id = ?', [$charge->id]);
        } catch (QueryException) {
            $rejected = true;
        }

        $this->assertTrue($rejected, 'canteen_orders_charge_fk RESTRICT must reject deleting a Charge a real Order still references.');
    }

    #[Test]
    public function an_inventory_location_referenced_by_an_order_cannot_be_deleted(): void
    {
        $school = $this->createSchool();
        $location = $this->createInventoryLocation($school);
        $outlet = $this->createCanteenOutlet($school, $location);
        $student = $this->createStudent($school);
        $this->createCanteenOrderRaw($school, $student, $outlet);

        $this->setSchool($school->id);

        $rejected = false;
        try {
            DB::connection('pgsql')->delete('delete from inventory_locations where id = ?', [$location->id]);
        } catch (QueryException) {
            $rejected = true;
        }

        $this->assertTrue($rejected, 'canteen_orders_location_fk RESTRICT must reject deleting an InventoryLocation a real Order still references (both directly and transitively via canteen_outlets_location_fk).');
    }

    #[Test]
    public function a_stock_movement_referenced_by_a_consumption_row_cannot_be_deleted(): void
    {
        $school = $this->createSchool();
        $location = $this->createInventoryLocation($school);
        $outlet = $this->createCanteenOutlet($school, $location);
        $student = $this->createStudent($school);
        $order = $this->createCanteenOrderRaw($school, $student, $outlet);
        $inventoryItem = $this->createInventoryItem($school);
        $movement = app(TenantContext::class)->withSchool($school, fn () => StockMovement::factory()->create([
            'school_id' => $school->id,
            'inventory_item_id' => $inventoryItem->id,
            'movement_type' => 'issue',
            'from_location_id' => $location->id,
            'to_location_id' => null,
        ]));
        $this->createCanteenOrderStockConsumptionRaw($school, $order, $movement);

        $this->setSchool($school->id);

        $rejected = false;
        try {
            DB::connection('pgsql')->delete('delete from stock_movements where id = ?', [$movement->id]);
        } catch (QueryException) {
            $rejected = true;
        }

        $this->assertTrue($rejected, 'canteen_order_stock_consumptions_movement_fk RESTRICT must reject deleting a StockMovement a real Consumption row still references.');
    }
}
