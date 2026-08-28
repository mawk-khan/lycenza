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
 * cross-School denials, `quantity > 0`, and the
 * `line_total = unit_price * quantity` exact-NUMERIC arithmetic CHECK
 * constraint.
 */
class CanteenOrderLinesRlsIsolationTest extends TestCase
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
            ['canteen_order_lines', 'public'],
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
        $item = $this->createCanteenItem($school);
        $this->createCanteenOrderLineRaw($school, $order, $item);

        DB::connection('pgsql')->statement('RESET '.TenantRls::SESSION_VAR);

        $count = DB::connection('pgsql')->selectOne('select count(*) as c from canteen_order_lines')->c;
        $this->assertSame(0, (int) $count);
    }

    #[Test]
    public function school_a_cannot_select_school_bs_line(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $locationB = $this->createInventoryLocation($schoolB);
        $outletB = $this->createCanteenOutlet($schoolB, $locationB);
        $studentB = $this->createStudent($schoolB);
        $orderB = $this->createCanteenOrderRaw($schoolB, $studentB, $outletB);
        $itemB = $this->createCanteenItem($schoolB);
        $lineB = $this->createCanteenOrderLineRaw($schoolB, $orderB, $itemB);

        $this->setSchool($schoolA->id);

        $rows = DB::connection('pgsql')->select('select id from canteen_order_lines where id = ?', [$lineB->id]);
        $this->assertCount(0, $rows);
    }

    #[Test]
    public function a_line_cannot_reference_an_order_from_a_different_school_at_the_database_level(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $locationB = $this->createInventoryLocation($schoolB);
        $outletB = $this->createCanteenOutlet($schoolB, $locationB);
        $studentB = $this->createStudent($schoolB);
        $orderB = $this->createCanteenOrderRaw($schoolB, $studentB, $outletB);
        $itemA = $this->createCanteenItem($schoolA);

        $rejected = false;
        try {
            DB::connection('pgsql_admin')->table('canteen_order_lines')->insert([
                'id' => (string) new UuidV7,
                'school_id' => $schoolA->id,
                'order_id' => $orderB->id,
                'canteen_item_id' => $itemA->id,
                'quantity' => 1,
                'unit_price' => '10.00',
                'line_total' => '10.00',
                'currency' => 'INR',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (QueryException) {
            $rejected = true;
        }

        $this->assertTrue($rejected, 'canteen_order_lines_order_fk must reject a cross-School Order reference.');
    }

    #[Test]
    public function a_line_cannot_reference_a_canteen_item_from_a_different_school_at_the_database_level(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $locationA = $this->createInventoryLocation($schoolA);
        $outletA = $this->createCanteenOutlet($schoolA, $locationA);
        $studentA = $this->createStudent($schoolA);
        $orderA = $this->createCanteenOrderRaw($schoolA, $studentA, $outletA);
        $itemB = $this->createCanteenItem($schoolB);

        $rejected = false;
        try {
            DB::connection('pgsql_admin')->table('canteen_order_lines')->insert([
                'id' => (string) new UuidV7,
                'school_id' => $schoolA->id,
                'order_id' => $orderA->id,
                'canteen_item_id' => $itemB->id,
                'quantity' => 1,
                'unit_price' => '10.00',
                'line_total' => '10.00',
                'currency' => 'INR',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (QueryException) {
            $rejected = true;
        }

        $this->assertTrue($rejected, 'canteen_order_lines_item_fk must reject a cross-School CanteenItem reference.');
    }

    #[Test]
    public function the_database_rejects_a_non_positive_quantity(): void
    {
        $school = $this->createSchool();
        $location = $this->createInventoryLocation($school);
        $outlet = $this->createCanteenOutlet($school, $location);
        $student = $this->createStudent($school);
        $order = $this->createCanteenOrderRaw($school, $student, $outlet);
        $item = $this->createCanteenItem($school);
        $this->setSchool($school->id);

        $rejected = false;
        try {
            DB::connection('pgsql')->table('canteen_order_lines')->insert([
                'id' => (string) new UuidV7,
                'school_id' => $school->id,
                'order_id' => $order->id,
                'canteen_item_id' => $item->id,
                'quantity' => 0,
                'unit_price' => '10.00',
                'line_total' => '0.00',
                'currency' => 'INR',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (QueryException) {
            $rejected = true;
        }

        $this->assertTrue($rejected, 'canteen_order_lines_quantity_positive_check must reject a zero/negative quantity.');
    }

    #[Test]
    public function the_database_rejects_a_line_total_that_does_not_equal_unit_price_times_quantity(): void
    {
        $school = $this->createSchool();
        $location = $this->createInventoryLocation($school);
        $outlet = $this->createCanteenOutlet($school, $location);
        $student = $this->createStudent($school);
        $order = $this->createCanteenOrderRaw($school, $student, $outlet);
        $item = $this->createCanteenItem($school);
        $this->setSchool($school->id);

        $rejected = false;
        try {
            DB::connection('pgsql')->table('canteen_order_lines')->insert([
                'id' => (string) new UuidV7,
                'school_id' => $school->id,
                'order_id' => $order->id,
                'canteen_item_id' => $item->id,
                'quantity' => 3,
                'unit_price' => '10.00',
                'line_total' => '25.00', // should be 30.00
                'currency' => 'INR',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (QueryException) {
            $rejected = true;
        }

        $this->assertTrue($rejected, 'canteen_order_lines_line_total_arithmetic_check must reject line_total != unit_price * quantity.');
    }

    #[Test]
    public function the_database_accepts_an_exact_line_total(): void
    {
        $school = $this->createSchool();
        $location = $this->createInventoryLocation($school);
        $outlet = $this->createCanteenOutlet($school, $location);
        $student = $this->createStudent($school);
        $order = $this->createCanteenOrderRaw($school, $student, $outlet);
        $item = $this->createCanteenItem($school);
        $this->setSchool($school->id);

        DB::connection('pgsql')->table('canteen_order_lines')->insert([
            'id' => (string) new UuidV7,
            'school_id' => $school->id,
            'order_id' => $order->id,
            'canteen_item_id' => $item->id,
            'quantity' => 3,
            'unit_price' => '10.50',
            'line_total' => '31.50',
            'currency' => 'INR',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertDatabaseHas('canteen_order_lines', ['order_id' => $order->id, 'line_total' => '31.50']);
    }
}
