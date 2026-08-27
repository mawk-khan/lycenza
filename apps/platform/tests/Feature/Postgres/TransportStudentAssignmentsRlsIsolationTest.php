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
 * Phase 10B (mandatory per root CLAUDE.md rule 28). The most important
 * test in this file is
 * a_pickup_stop_from_a_different_route_is_rejected_at_the_database_level()
 * -- checkpoint brief section 13's "Stop integrity" invariant, proven
 * with a raw privileged INSERT that bypasses controller validation
 * entirely.
 */
class TransportStudentAssignmentsRlsIsolationTest extends TestCase
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
            ['transport_student_assignments', 'public'],
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
        $student = $this->createStudent($school);
        $this->createTransportStudentAssignment($student, $route);

        DB::connection('pgsql')->statement('RESET '.TenantRls::SESSION_VAR);

        $count = DB::connection('pgsql')->selectOne('select count(*) as c from transport_student_assignments')->c;
        $this->assertSame(0, (int) $count);
    }

    #[Test]
    public function school_a_cannot_select_school_bs_assignment(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $routeB = $this->createTransportRoute($schoolB);
        $studentB = $this->createStudent($schoolB);
        $assignmentB = $this->createTransportStudentAssignment($studentB, $routeB);

        $this->setSchool($schoolA->id);

        $rows = DB::connection('pgsql')->select('select id from transport_student_assignments where id = ?', [$assignmentB->id]);
        $this->assertCount(0, $rows);
    }

    #[Test]
    public function school_a_cannot_update_school_bs_assignment(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $routeB = $this->createTransportRoute($schoolB);
        $studentB = $this->createStudent($schoolB);
        $assignmentB = $this->createTransportStudentAssignment($studentB, $routeB);

        $this->setSchool($schoolA->id);

        $updated = DB::connection('pgsql')->update(
            "update transport_student_assignments set status = 'ended', ends_on = now() where id = ?",
            [$assignmentB->id],
        );

        $this->assertSame(0, $updated);
    }

    #[Test]
    public function an_assignment_cannot_reference_a_student_from_a_different_school_at_the_database_level(): void
    {
        $schoolA = $this->createSchool();
        $routeA = $this->createTransportRoute($schoolA);

        $schoolB = $this->createSchool();
        $studentB = $this->createStudent($schoolB);

        $rejected = false;

        try {
            DB::connection('pgsql_admin')->table('transport_student_assignments')->insert([
                'id' => (string) new UuidV7,
                'school_id' => $schoolA->id,
                'student_id' => $studentB->id,
                'route_id' => $routeA->id,
                'status' => 'active',
                'starts_on' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (QueryException) {
            $rejected = true;
        }

        $this->assertTrue($rejected, 'The composite FK (student_id, school_id) -> students(id, school_id) must reject a cross-School Student reference at the database level.');
    }

    #[Test]
    public function an_assignment_cannot_reference_a_route_from_a_different_school_at_the_database_level(): void
    {
        $schoolA = $this->createSchool();
        $studentA = $this->createStudent($schoolA);

        $schoolB = $this->createSchool();
        $routeB = $this->createTransportRoute($schoolB);

        $rejected = false;

        try {
            DB::connection('pgsql_admin')->table('transport_student_assignments')->insert([
                'id' => (string) new UuidV7,
                'school_id' => $schoolA->id,
                'student_id' => $studentA->id,
                'route_id' => $routeB->id,
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

    /**
     * THE core Stop-integrity proof (checkpoint brief section 13): a
     * pickup Stop that genuinely belongs to a DIFFERENT Route within
     * the SAME School is rejected by the 3-column composite FK
     * `(pickup_stop_id, route_id, school_id) ->
     * transport_stops(id, route_id, school_id)` -- not merely a
     * cross-School case, but a same-School, wrong-Route case that only
     * this composite FK (not a plain 2-column School-only FK) can
     * catch.
     */
    #[Test]
    public function a_pickup_stop_from_a_different_route_is_rejected_at_the_database_level(): void
    {
        $school = $this->createSchool();
        $routeA = $this->createTransportRoute($school);
        $routeB = $this->createTransportRoute($school);
        $stopOnRouteB = $this->createTransportStop($routeB);
        $student = $this->createStudent($school);

        $rejected = false;

        try {
            DB::connection('pgsql_admin')->table('transport_student_assignments')->insert([
                'id' => (string) new UuidV7,
                'school_id' => $school->id,
                'student_id' => $student->id,
                'route_id' => $routeA->id,
                'pickup_stop_id' => $stopOnRouteB->id,
                'status' => 'active',
                'starts_on' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (QueryException) {
            $rejected = true;
        }

        $this->assertTrue($rejected, 'The composite FK (pickup_stop_id, route_id, school_id) -> transport_stops(id, route_id, school_id) must reject a Stop belonging to a different Route, even within the same School.');
    }

    /**
     * The mirror case for `dropoff_stop_id`.
     */
    #[Test]
    public function a_dropoff_stop_from_a_different_route_is_rejected_at_the_database_level(): void
    {
        $school = $this->createSchool();
        $routeA = $this->createTransportRoute($school);
        $routeB = $this->createTransportRoute($school);
        $stopOnRouteB = $this->createTransportStop($routeB);
        $student = $this->createStudent($school);

        $rejected = false;

        try {
            DB::connection('pgsql_admin')->table('transport_student_assignments')->insert([
                'id' => (string) new UuidV7,
                'school_id' => $school->id,
                'student_id' => $student->id,
                'route_id' => $routeA->id,
                'dropoff_stop_id' => $stopOnRouteB->id,
                'status' => 'active',
                'starts_on' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (QueryException) {
            $rejected = true;
        }

        $this->assertTrue($rejected, 'The composite FK (dropoff_stop_id, route_id, school_id) -> transport_stops(id, route_id, school_id) must reject a Stop belonging to a different Route, even within the same School.');
    }

    /**
     * Raw-SQL proof that the partial unique index itself exists and is
     * enforced -- complements (does not replace) a real two-process
     * concurrency test of the same invariant.
     */
    #[Test]
    public function the_database_rejects_a_second_active_assignment_for_the_same_student(): void
    {
        $school = $this->createSchool();
        $routeOne = $this->createTransportRoute($school);
        $routeTwo = $this->createTransportRoute($school);
        $student = $this->createStudent($school);
        $this->createTransportStudentAssignment($student, $routeOne);

        $this->setSchool($school->id);

        $rejected = false;

        try {
            DB::connection('pgsql')->table('transport_student_assignments')->insert([
                'id' => (string) new UuidV7,
                'school_id' => $school->id,
                'student_id' => $student->id,
                'route_id' => $routeTwo->id,
                'status' => 'active',
                'starts_on' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            $rejected = true;
        }

        $this->assertTrue($rejected, 'transport_student_assignments_one_active_per_student must reject a second simultaneous active assignment for the same Student.');
    }
}
