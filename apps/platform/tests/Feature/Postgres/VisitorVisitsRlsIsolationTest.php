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
 * Phase 10C (mandatory per root CLAUDE.md rule 28). Also proves the
 * three composite-FK cross-School denials (checkpoint brief section
 * 19): VisitorVisit -> Visitor, -> Campus, -> Employee host.
 */
class VisitorVisitsRlsIsolationTest extends TestCase
{
    use CreatesTenancyFixtures;

    private function setSchool(string $schoolId): void
    {
        DB::connection('pgsql')->select('select set_config(?, ?, false)', [TenantRls::SESSION_VAR, $schoolId]);
    }

    private function insertVisit(string $schoolId, string $visitorId, string $campusId, ?string $hostEmployeeId = null): void
    {
        DB::connection('pgsql_admin')->table('visitor_visits')->insert([
            'id' => (string) new UuidV7,
            'school_id' => $schoolId,
            'visitor_id' => $visitorId,
            'campus_id' => $campusId,
            'host_employee_id' => $hostEmployeeId,
            'purpose' => 'Cross-FK Visit',
            'status' => 'checked_in',
            'checked_in_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    #[Test]
    public function the_table_has_rls_enabled_and_forced(): void
    {
        $row = DB::connection('pgsql_admin')->selectOne(
            'select relrowsecurity, relforcerowsecurity from pg_class '.
            'where relname = ? and relnamespace = ?::regnamespace',
            ['visitor_visits', 'public'],
        );

        $this->assertNotNull($row);
        $this->assertTrue($row->relrowsecurity);
        $this->assertTrue($row->relforcerowsecurity);
    }

    #[Test]
    public function no_school_context_sees_zero_rows(): void
    {
        $school = $this->createSchool();
        $campus = $this->createCampus($school);
        $visitor = $this->createVisitor($school);
        $this->createVisitorVisit($visitor, $campus);

        DB::connection('pgsql')->statement('RESET '.TenantRls::SESSION_VAR);

        $count = DB::connection('pgsql')->selectOne('select count(*) as c from visitor_visits')->c;
        $this->assertSame(0, (int) $count);
    }

    #[Test]
    public function school_a_cannot_select_school_bs_visit(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $campusB = $this->createCampus($schoolB);
        $visitorB = $this->createVisitor($schoolB);
        $visitB = $this->createVisitorVisit($visitorB, $campusB);

        $this->setSchool($schoolA->id);

        $rows = DB::connection('pgsql')->select('select id from visitor_visits where id = ?', [$visitB->id]);
        $this->assertCount(0, $rows);
    }

    #[Test]
    public function school_a_cannot_update_school_bs_visit(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $campusB = $this->createCampus($schoolB);
        $visitorB = $this->createVisitor($schoolB);
        $visitB = $this->createVisitorVisit($visitorB, $campusB);

        $this->setSchool($schoolA->id);

        $updated = DB::connection('pgsql')->update(
            'update visitor_visits set status = ? where id = ?',
            ['checked_out', $visitB->id],
        );

        $this->assertSame(0, $updated);
    }

    #[Test]
    public function a_visit_cannot_reference_a_visitor_from_a_different_school_at_the_database_level(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $campusA = $this->createCampus($schoolA);
        $visitorB = $this->createVisitor($schoolB);

        $rejected = false;

        try {
            $this->insertVisit($schoolA->id, $visitorB->id, $campusA->id);
        } catch (QueryException) {
            $rejected = true;
        }

        $this->assertTrue($rejected, 'The composite FK (visitor_id, school_id) -> visitors(id, school_id) must reject a cross-School Visitor reference at the database level.');
    }

    #[Test]
    public function a_visit_cannot_reference_a_campus_from_a_different_school_at_the_database_level(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $visitorA = $this->createVisitor($schoolA);
        $campusB = $this->createCampus($schoolB);

        $rejected = false;

        try {
            $this->insertVisit($schoolA->id, $visitorA->id, $campusB->id);
        } catch (QueryException) {
            $rejected = true;
        }

        $this->assertTrue($rejected, 'The composite FK (campus_id, school_id) -> campuses(id, school_id) must reject a cross-School Campus reference at the database level.');
    }

    #[Test]
    public function a_visit_cannot_reference_a_host_employee_from_a_different_school_at_the_database_level(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $visitorA = $this->createVisitor($schoolA);
        $campusA = $this->createCampus($schoolA);
        $employeeB = $this->createEmployee($schoolB);

        $rejected = false;

        try {
            $this->insertVisit($schoolA->id, $visitorA->id, $campusA->id, $employeeB->id);
        } catch (QueryException) {
            $rejected = true;
        }

        $this->assertTrue($rejected, 'The composite FK (host_employee_id, school_id) -> employees(id, school_id) must reject a cross-School Employee reference at the database level.');
    }

    /**
     * Raw-SQL proof that the partial unique index itself exists and is
     * enforced -- complements (does not replace) the real two-process
     * concurrency proof in VisitorVisitCheckInConcurrencyTest. Uses the
     * SAME `pgsql` connection/session as the fixture write above
     * (never `pgsql_admin`) -- mirrors
     * TransportStudentAssignmentsRlsIsolationTest::the_database_rejects_a_second_active_assignment_for_the_same_student
     * exactly: a session can always see its own uncommitted writes, so
     * the violation is raised synchronously. Using a SEPARATE
     * connection here would instead make the second insert's unique-
     * index check block waiting for the first (still-open,
     * `DatabaseTransactions`-wrapped) transaction to resolve -- which
     * never happens until this very test method returns, deadlocking
     * the whole test.
     */
    #[Test]
    public function the_database_rejects_a_second_active_visit_for_the_same_visitor(): void
    {
        $school = $this->createSchool();
        $campus = $this->createCampus($school);
        $visitor = $this->createVisitor($school);
        $this->createVisitorVisit($visitor, $campus);

        $this->setSchool($school->id);

        $rejected = false;

        try {
            DB::connection('pgsql')->table('visitor_visits')->insert([
                'id' => (string) new UuidV7,
                'school_id' => $school->id,
                'visitor_id' => $visitor->id,
                'campus_id' => $campus->id,
                'purpose' => 'Second concurrent visit',
                'status' => 'checked_in',
                'checked_in_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            $rejected = true;
        }

        $this->assertTrue($rejected, 'The partial unique index visitor_visits_one_active_per_visitor must reject a second checked_in row for the same Visitor.');
    }
}
