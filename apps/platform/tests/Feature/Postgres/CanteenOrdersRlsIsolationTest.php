<?php

namespace Tests\Feature\Postgres;

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\UuidV7;
use Tests\Concerns\CreatesCanteenFixtures;
use Tests\Concerns\CreatesFeesFixtures;
use Tests\Concerns\CreatesFinanceFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 10F (CLAUDE.md rule 28). Also proves every composite-FK
 * cross-School denial, the three lifecycle-shape CHECK constraints
 * (pending/fulfilled/cancelled), the `charge_id` partial unique index,
 * and that DELETE is revoked from the application role
 * (`TenantRls::revokeDelete()`).
 */
class CanteenOrdersRlsIsolationTest extends TestCase
{
    use CreatesCanteenFixtures, CreatesFeesFixtures, CreatesFinanceFixtures, CreatesTenancyFixtures;

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
            ['canteen_orders', 'public'],
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
        $this->createCanteenOrderRaw($school, $student, $outlet);

        DB::connection('pgsql')->statement('RESET '.TenantRls::SESSION_VAR);

        $count = DB::connection('pgsql')->selectOne('select count(*) as c from canteen_orders')->c;
        $this->assertSame(0, (int) $count);
    }

    #[Test]
    public function school_a_cannot_select_school_bs_order(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $locationB = $this->createInventoryLocation($schoolB);
        $outletB = $this->createCanteenOutlet($schoolB, $locationB);
        $studentB = $this->createStudent($schoolB);
        $orderB = $this->createCanteenOrderRaw($schoolB, $studentB, $outletB);

        $this->setSchool($schoolA->id);

        $rows = DB::connection('pgsql')->select('select id from canteen_orders where id = ?', [$orderB->id]);
        $this->assertCount(0, $rows);
    }

    #[Test]
    public function an_order_cannot_reference_a_student_from_a_different_school_at_the_database_level(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $locationA = $this->createInventoryLocation($schoolA);
        $outletA = $this->createCanteenOutlet($schoolA, $locationA);
        $studentB = $this->createStudent($schoolB);

        $rejected = false;
        try {
            DB::connection('pgsql_admin')->table('canteen_orders')->insert([
                'id' => (string) new UuidV7,
                'school_id' => $schoolA->id,
                'student_id' => $studentB->id,
                'outlet_id' => $outletA->id,
                'inventory_location_id' => $locationA->id,
                'status' => 'pending',
                'total_amount' => '10.00',
                'currency' => 'INR',
                'charge_id' => null,
                'placed_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (QueryException) {
            $rejected = true;
        }

        $this->assertTrue($rejected, 'canteen_orders_student_fk must reject a cross-School Student reference.');
    }

    #[Test]
    public function an_order_cannot_reference_an_outlet_from_a_different_school_at_the_database_level(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $locationA = $this->createInventoryLocation($schoolA);
        $studentA = $this->createStudent($schoolA);
        $locationB = $this->createInventoryLocation($schoolB);
        $outletB = $this->createCanteenOutlet($schoolB, $locationB);

        $rejected = false;
        try {
            DB::connection('pgsql_admin')->table('canteen_orders')->insert([
                'id' => (string) new UuidV7,
                'school_id' => $schoolA->id,
                'student_id' => $studentA->id,
                'outlet_id' => $outletB->id,
                'inventory_location_id' => $locationA->id,
                'status' => 'pending',
                'total_amount' => '10.00',
                'currency' => 'INR',
                'charge_id' => null,
                'placed_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (QueryException) {
            $rejected = true;
        }

        $this->assertTrue($rejected, 'canteen_orders_outlet_fk must reject a cross-School Outlet reference.');
    }

    #[Test]
    public function an_order_cannot_reference_an_inventory_location_from_a_different_school_at_the_database_level(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $locationA = $this->createInventoryLocation($schoolA);
        $outletA = $this->createCanteenOutlet($schoolA, $locationA);
        $studentA = $this->createStudent($schoolA);
        $locationB = $this->createInventoryLocation($schoolB);

        $rejected = false;
        try {
            DB::connection('pgsql_admin')->table('canteen_orders')->insert([
                'id' => (string) new UuidV7,
                'school_id' => $schoolA->id,
                'student_id' => $studentA->id,
                'outlet_id' => $outletA->id,
                'inventory_location_id' => $locationB->id,
                'status' => 'pending',
                'total_amount' => '10.00',
                'currency' => 'INR',
                'charge_id' => null,
                'placed_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (QueryException) {
            $rejected = true;
        }

        $this->assertTrue($rejected, 'canteen_orders_location_fk must reject a cross-School InventoryLocation reference.');
    }

    #[Test]
    public function an_order_cannot_reference_a_charge_from_a_different_school_at_the_database_level(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $locationA = $this->createInventoryLocation($schoolA);
        $outletA = $this->createCanteenOutlet($schoolA, $locationA);
        $studentA = $this->createStudent($schoolA);

        $studentB = $this->createStudent($schoolB);
        $academicYearB = $this->createAcademicYear($schoolB, ['status' => 'active']);
        $receivableB = $this->createLedgerAccount($schoolB, ['type' => 'asset']);
        $revenueB = $this->createLedgerAccount($schoolB, ['type' => 'income']);
        $chargeB = $this->assessCharge($schoolB, $studentB, $academicYearB, $receivableB, $revenueB);

        $rejected = false;
        try {
            DB::connection('pgsql_admin')->table('canteen_orders')->insert([
                'id' => (string) new UuidV7,
                'school_id' => $schoolA->id,
                'student_id' => $studentA->id,
                'outlet_id' => $outletA->id,
                'inventory_location_id' => $locationA->id,
                'status' => 'fulfilled',
                'total_amount' => '10.00',
                'currency' => 'INR',
                'charge_id' => $chargeB->id,
                'placed_at' => now(),
                'fulfilled_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (QueryException) {
            $rejected = true;
        }

        $this->assertTrue($rejected, 'canteen_orders_charge_fk must reject a cross-School Charge reference.');
    }

    #[Test]
    public function the_database_rejects_a_pending_order_with_a_fulfilled_at_timestamp(): void
    {
        $school = $this->createSchool();
        $location = $this->createInventoryLocation($school);
        $outlet = $this->createCanteenOutlet($school, $location);
        $student = $this->createStudent($school);
        $this->setSchool($school->id);

        $rejected = false;
        try {
            DB::connection('pgsql')->table('canteen_orders')->insert([
                'id' => (string) new UuidV7,
                'school_id' => $school->id,
                'student_id' => $student->id,
                'outlet_id' => $outlet->id,
                'inventory_location_id' => $location->id,
                'status' => 'pending',
                'total_amount' => '10.00',
                'currency' => 'INR',
                'charge_id' => null,
                'placed_at' => now(),
                'fulfilled_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (QueryException) {
            $rejected = true;
        }

        $this->assertTrue($rejected, 'canteen_orders_pending_shape_check must reject a pending Order with a non-null fulfilled_at.');
    }

    #[Test]
    public function the_database_rejects_a_fulfilled_order_with_no_charge_id(): void
    {
        $school = $this->createSchool();
        $location = $this->createInventoryLocation($school);
        $outlet = $this->createCanteenOutlet($school, $location);
        $student = $this->createStudent($school);
        $this->setSchool($school->id);

        $rejected = false;
        try {
            DB::connection('pgsql')->table('canteen_orders')->insert([
                'id' => (string) new UuidV7,
                'school_id' => $school->id,
                'student_id' => $student->id,
                'outlet_id' => $outlet->id,
                'inventory_location_id' => $location->id,
                'status' => 'fulfilled',
                'total_amount' => '10.00',
                'currency' => 'INR',
                'charge_id' => null,
                'placed_at' => now(),
                'fulfilled_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (QueryException) {
            $rejected = true;
        }

        $this->assertTrue($rejected, 'canteen_orders_fulfilled_shape_check must reject a fulfilled Order with a null charge_id.');
    }

    #[Test]
    public function the_database_rejects_a_cancelled_order_with_a_charge_id(): void
    {
        $school = $this->createSchool();
        $location = $this->createInventoryLocation($school);
        $outlet = $this->createCanteenOutlet($school, $location);
        $student = $this->createStudent($school);
        $academicYear = $this->createAcademicYear($school, ['status' => 'active']);
        $receivable = $this->createLedgerAccount($school, ['type' => 'asset']);
        $revenue = $this->createLedgerAccount($school, ['type' => 'income']);
        $charge = $this->assessCharge($school, $student, $academicYear, $receivable, $revenue);

        $this->setSchool($school->id);

        $rejected = false;
        try {
            DB::connection('pgsql')->table('canteen_orders')->insert([
                'id' => (string) new UuidV7,
                'school_id' => $school->id,
                'student_id' => $student->id,
                'outlet_id' => $outlet->id,
                'inventory_location_id' => $location->id,
                'status' => 'cancelled',
                'total_amount' => '10.00',
                'currency' => 'INR',
                'charge_id' => $charge->id,
                'placed_at' => now(),
                'cancelled_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (QueryException) {
            $rejected = true;
        }

        $this->assertTrue($rejected, 'canteen_orders_cancelled_shape_check must reject a cancelled Order with a non-null charge_id.');
    }

    #[Test]
    public function the_database_rejects_a_second_order_referencing_the_same_charge(): void
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
            'status' => 'fulfilled',
            'charge_id' => $charge->id,
            'fulfilled_at' => now(),
        ]);

        $this->setSchool($school->id);

        $rejected = false;
        try {
            DB::connection('pgsql')->table('canteen_orders')->insert([
                'id' => (string) new UuidV7,
                'school_id' => $school->id,
                'student_id' => $student->id,
                'outlet_id' => $outlet->id,
                'inventory_location_id' => $location->id,
                'status' => 'fulfilled',
                'total_amount' => '10.00',
                'currency' => 'INR',
                'charge_id' => $charge->id,
                'placed_at' => now(),
                'fulfilled_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (QueryException) {
            $rejected = true;
        }

        $this->assertTrue($rejected, 'canteen_orders_charge_id_unique (partial) must reject a second Order referencing the same Charge.');
    }

    #[Test]
    public function the_application_role_cannot_delete_a_canteen_order(): void
    {
        $school = $this->createSchool();
        $location = $this->createInventoryLocation($school);
        $outlet = $this->createCanteenOutlet($school, $location);
        $student = $this->createStudent($school);
        $order = $this->createCanteenOrderRaw($school, $student, $outlet);

        $this->setSchool($school->id);

        $rejected = false;
        try {
            DB::connection('pgsql')->delete('delete from canteen_orders where id = ?', [$order->id]);
        } catch (QueryException) {
            $rejected = true;
        }

        $this->assertTrue($rejected, 'TenantRls::revokeDelete(\'canteen_orders\') must reject a DELETE from the application role, even for its own School\'s row.');
    }
}
