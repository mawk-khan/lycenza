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
 * Phase 10B (mandatory per root CLAUDE.md rule 28).
 */
class TransportRouteAssignmentsRlsIsolationTest extends TestCase
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
            ['transport_route_assignments', 'public'],
        );

        $this->assertNotNull($row);
        $this->assertTrue($row->relrowsecurity);
        $this->assertTrue($row->relforcerowsecurity);
    }

    #[Test]
    public function no_school_context_sees_zero_rows(): void
    {
        $school = $this->createSchool();
        $route = $this->createTransportRoute($school);
        $vehicle = $this->createTransportVehicle($school);
        $driver = $this->createEmployee($school);
        $this->createTransportRouteAssignment($route, $vehicle, $driver);

        DB::connection('pgsql')->statement('RESET '.TenantRls::SESSION_VAR);

        $count = DB::connection('pgsql')->selectOne('select count(*) as c from transport_route_assignments')->c;
        $this->assertSame(0, (int) $count);
    }

    #[Test]
    public function school_a_cannot_select_school_bs_assignment(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $routeB = $this->createTransportRoute($schoolB);
        $vehicleB = $this->createTransportVehicle($schoolB);
        $driverB = $this->createEmployee($schoolB);
        $assignmentB = $this->createTransportRouteAssignment($routeB, $vehicleB, $driverB);

        $this->setSchool($schoolA->id);

        $rows = DB::connection('pgsql')->select('select id from transport_route_assignments where id = ?', [$assignmentB->id]);
        $this->assertCount(0, $rows);
    }

    #[Test]
    public function school_a_cannot_update_school_bs_assignment(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $routeB = $this->createTransportRoute($schoolB);
        $vehicleB = $this->createTransportVehicle($schoolB);
        $driverB = $this->createEmployee($schoolB);
        $assignmentB = $this->createTransportRouteAssignment($routeB, $vehicleB, $driverB);

        $this->setSchool($schoolA->id);

        $updated = DB::connection('pgsql')->update(
            "update transport_route_assignments set status = 'ended', ends_on = now() where id = ?",
            [$assignmentB->id],
        );

        $this->assertSame(0, $updated);
    }

    #[Test]
    public function an_assignment_cannot_reference_a_route_from_a_different_school_at_the_database_level(): void
    {
        $schoolA = $this->createSchool();
        $vehicleA = $this->createTransportVehicle($schoolA);
        $driverA = $this->createEmployee($schoolA);

        $schoolB = $this->createSchool();
        $routeB = $this->createTransportRoute($schoolB);

        $rejected = false;

        try {
            DB::connection('pgsql_admin')->table('transport_route_assignments')->insert([
                'id' => (string) new UuidV7,
                'school_id' => $schoolA->id,
                'route_id' => $routeB->id,
                'vehicle_id' => $vehicleA->id,
                'driver_employee_id' => $driverA->id,
                'status' => 'active',
                'starts_on' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (QueryException) {
            $rejected = true;
        }

        $this->assertTrue($rejected, 'The composite FK (route_id, school_id) -> transport_routes(id, school_id) must reject a cross-School Route reference at the database level.');
    }

    #[Test]
    public function an_assignment_cannot_reference_a_vehicle_from_a_different_school_at_the_database_level(): void
    {
        $schoolA = $this->createSchool();
        $routeA = $this->createTransportRoute($schoolA);
        $driverA = $this->createEmployee($schoolA);

        $schoolB = $this->createSchool();
        $vehicleB = $this->createTransportVehicle($schoolB);

        $rejected = false;

        try {
            DB::connection('pgsql_admin')->table('transport_route_assignments')->insert([
                'id' => (string) new UuidV7,
                'school_id' => $schoolA->id,
                'route_id' => $routeA->id,
                'vehicle_id' => $vehicleB->id,
                'driver_employee_id' => $driverA->id,
                'status' => 'active',
                'starts_on' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (QueryException) {
            $rejected = true;
        }

        $this->assertTrue($rejected, 'The composite FK (vehicle_id, school_id) -> transport_vehicles(id, school_id) must reject a cross-School Vehicle reference at the database level.');
    }

    #[Test]
    public function an_assignment_cannot_reference_a_driver_employee_from_a_different_school_at_the_database_level(): void
    {
        $schoolA = $this->createSchool();
        $routeA = $this->createTransportRoute($schoolA);
        $vehicleA = $this->createTransportVehicle($schoolA);

        $schoolB = $this->createSchool();
        $driverB = $this->createEmployee($schoolB);

        $rejected = false;

        try {
            DB::connection('pgsql_admin')->table('transport_route_assignments')->insert([
                'id' => (string) new UuidV7,
                'school_id' => $schoolA->id,
                'route_id' => $routeA->id,
                'vehicle_id' => $vehicleA->id,
                'driver_employee_id' => $driverB->id,
                'status' => 'active',
                'starts_on' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (QueryException) {
            $rejected = true;
        }

        $this->assertTrue($rejected, 'The composite FK (driver_employee_id, school_id) -> employees(id, school_id) must reject a cross-School Employee reference at the database level.');
    }

    /**
     * Raw-SQL proof that the partial unique index itself exists and is
     * enforced -- complements (does not replace) a real two-process
     * concurrency test of the same invariant.
     */
    #[Test]
    public function the_database_rejects_a_second_active_assignment_for_the_same_route(): void
    {
        $school = $this->createSchool();
        $route = $this->createTransportRoute($school);
        $vehicleOne = $this->createTransportVehicle($school);
        $vehicleTwo = $this->createTransportVehicle($school);
        $driverOne = $this->createEmployee($school);
        $driverTwo = $this->createEmployee($school);
        $this->createTransportRouteAssignment($route, $vehicleOne, $driverOne);

        $this->setSchool($school->id);

        $rejected = false;

        try {
            DB::connection('pgsql')->table('transport_route_assignments')->insert([
                'id' => (string) new UuidV7,
                'school_id' => $school->id,
                'route_id' => $route->id,
                'vehicle_id' => $vehicleTwo->id,
                'driver_employee_id' => $driverTwo->id,
                'status' => 'active',
                'starts_on' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            $rejected = true;
        }

        $this->assertTrue($rejected, 'transport_route_assignments_one_active_per_route must reject a second simultaneous active assignment for the same Route.');
    }
}
