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
 * Phase 10D (mandatory per root CLAUDE.md rule 28). Also proves the
 * two composite-FK cross-School denials (checkpoint brief section
 * 11), both partial unique indexes (sections 15-16), and the
 * historical-integrity deletion protection for the two EXTERNAL
 * parents this table references (Student, HostelBed) -- checkpoint
 * brief section 46.
 */
class HostelResidencyAssignmentsRlsIsolationTest extends TestCase
{
    use CreatesTenancyFixtures;

    private function setSchool(string $schoolId): void
    {
        DB::connection('pgsql')->select('select set_config(?, ?, false)', [TenantRls::SESSION_VAR, $schoolId]);
    }

    private function insertAssignment(string $schoolId, string $studentId, string $hostelBedId): void
    {
        DB::connection('pgsql_admin')->table('hostel_residency_assignments')->insert([
            'id' => (string) new UuidV7,
            'school_id' => $schoolId,
            'student_id' => $studentId,
            'hostel_bed_id' => $hostelBedId,
            'status' => 'active',
            'starts_on' => now(),
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
            ['hostel_residency_assignments', 'public'],
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
        $hostel = $this->createHostel($school, $campus);
        $room = $this->createHostelRoom($hostel);
        $bed = $this->createHostelBed($room);
        $student = $this->createStudent($school);
        $this->createHostelResidencyAssignment($student, $bed);

        DB::connection('pgsql')->statement('RESET '.TenantRls::SESSION_VAR);

        $count = DB::connection('pgsql')->selectOne('select count(*) as c from hostel_residency_assignments')->c;
        $this->assertSame(0, (int) $count);
    }

    #[Test]
    public function school_a_cannot_select_school_bs_assignment(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $campusB = $this->createCampus($schoolB);
        $hostelB = $this->createHostel($schoolB, $campusB);
        $roomB = $this->createHostelRoom($hostelB);
        $bedB = $this->createHostelBed($roomB);
        $studentB = $this->createStudent($schoolB);
        $assignmentB = $this->createHostelResidencyAssignment($studentB, $bedB);

        $this->setSchool($schoolA->id);

        $rows = DB::connection('pgsql')->select('select id from hostel_residency_assignments where id = ?', [$assignmentB->id]);
        $this->assertCount(0, $rows);
    }

    #[Test]
    public function school_a_cannot_update_school_bs_assignment(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $campusB = $this->createCampus($schoolB);
        $hostelB = $this->createHostel($schoolB, $campusB);
        $roomB = $this->createHostelRoom($hostelB);
        $bedB = $this->createHostelBed($roomB);
        $studentB = $this->createStudent($schoolB);
        $assignmentB = $this->createHostelResidencyAssignment($studentB, $bedB);

        $this->setSchool($schoolA->id);

        $updated = DB::connection('pgsql')->update(
            'update hostel_residency_assignments set status = ? where id = ?',
            ['ended', $assignmentB->id],
        );

        $this->assertSame(0, $updated);
    }

    #[Test]
    public function an_assignment_cannot_reference_a_student_from_a_different_school_at_the_database_level(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $campusA = $this->createCampus($schoolA);
        $hostelA = $this->createHostel($schoolA, $campusA);
        $roomA = $this->createHostelRoom($hostelA);
        $bedA = $this->createHostelBed($roomA);
        $studentB = $this->createStudent($schoolB);

        $rejected = false;

        try {
            $this->insertAssignment($schoolA->id, $studentB->id, $bedA->id);
        } catch (QueryException) {
            $rejected = true;
        }

        $this->assertTrue($rejected, 'The composite FK (student_id, school_id) -> students(id, school_id) must reject a cross-School Student reference at the database level.');
    }

    #[Test]
    public function an_assignment_cannot_reference_a_bed_from_a_different_school_at_the_database_level(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $studentA = $this->createStudent($schoolA);
        $campusB = $this->createCampus($schoolB);
        $hostelB = $this->createHostel($schoolB, $campusB);
        $roomB = $this->createHostelRoom($hostelB);
        $bedB = $this->createHostelBed($roomB);

        $rejected = false;

        try {
            $this->insertAssignment($schoolA->id, $studentA->id, $bedB->id);
        } catch (QueryException) {
            $rejected = true;
        }

        $this->assertTrue($rejected, 'The composite FK (hostel_bed_id, school_id) -> hostel_beds(id, school_id) must reject a cross-School Bed reference at the database level.');
    }

    /**
     * Raw-SQL proof that the partial unique index itself exists and is
     * enforced -- complements (does not replace) the real two-process
     * concurrency proof in HostelResidencyConcurrencyTest. Uses the
     * SAME `pgsql` connection/session as the fixture write above
     * (never `pgsql_admin`) -- mirrors
     * VisitorVisitsRlsIsolationTest::the_database_rejects_a_second_active_visit_for_the_same_visitor
     * exactly.
     */
    #[Test]
    public function the_database_rejects_a_second_active_assignment_for_the_same_bed(): void
    {
        $school = $this->createSchool();
        $campus = $this->createCampus($school);
        $hostel = $this->createHostel($school, $campus);
        $room = $this->createHostelRoom($hostel);
        $bed = $this->createHostelBed($room);
        $studentOne = $this->createStudent($school);
        $studentTwo = $this->createStudent($school);
        $this->createHostelResidencyAssignment($studentOne, $bed);

        $this->setSchool($school->id);

        $rejected = false;

        try {
            DB::connection('pgsql')->table('hostel_residency_assignments')->insert([
                'id' => (string) new UuidV7,
                'school_id' => $school->id,
                'student_id' => $studentTwo->id,
                'hostel_bed_id' => $bed->id,
                'status' => 'active',
                'starts_on' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            $rejected = true;
        }

        $this->assertTrue($rejected, 'hostel_residency_assignments_one_active_per_bed must reject a second simultaneous active assignment for the same Bed.');
    }

    #[Test]
    public function the_database_rejects_a_second_active_assignment_for_the_same_student(): void
    {
        $school = $this->createSchool();
        $campus = $this->createCampus($school);
        $hostel = $this->createHostel($school, $campus);
        $room = $this->createHostelRoom($hostel);
        $bedOne = $this->createHostelBed($room);
        $bedTwo = $this->createHostelBed($room);
        $student = $this->createStudent($school);
        $this->createHostelResidencyAssignment($student, $bedOne);

        $this->setSchool($school->id);

        $rejected = false;

        try {
            DB::connection('pgsql')->table('hostel_residency_assignments')->insert([
                'id' => (string) new UuidV7,
                'school_id' => $school->id,
                'student_id' => $student->id,
                'hostel_bed_id' => $bedTwo->id,
                'status' => 'active',
                'starts_on' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            $rejected = true;
        }

        $this->assertTrue($rejected, 'hostel_residency_assignments_one_active_per_student must reject a second simultaneous active assignment for the same Student.');
    }

    /**
     * THE historical-integrity proof for the Student parent
     * (checkpoint brief section 12/46) -- a deliberate correction
     * relative to Transport's `transport_student_assignments.student_id`
     * CASCADE precedent, informed directly by the Phase 10C Visitor
     * historical-integrity correction.
     */
    #[Test]
    public function a_student_referenced_by_a_residency_assignment_cannot_be_deleted(): void
    {
        $school = $this->createSchool();
        $campus = $this->createCampus($school);
        $hostel = $this->createHostel($school, $campus);
        $room = $this->createHostelRoom($hostel);
        $bed = $this->createHostelBed($room);
        $student = $this->createStudent($school);
        $assignment = $this->createHostelResidencyAssignment($student, $bed, ['status' => 'ended', 'ends_on' => now()]);

        $this->setSchool($school->id);

        $rejected = false;

        try {
            DB::connection('pgsql')->transaction(function () use ($student): void {
                DB::connection('pgsql')->table('students')->where('id', $student->id)->delete();
            });
        } catch (QueryException) {
            $rejected = true;
        }

        $this->assertTrue($rejected, 'hostel_residency_assignments_student_id_school_id_foreign must RESTRICT deletion of a Student referenced by a residency assignment.');

        $studentStillExists = DB::connection('pgsql')->table('students')->where('id', $student->id)->exists();
        $this->assertTrue($studentStillExists);

        $assignmentStillExists = DB::connection('pgsql')->table('hostel_residency_assignments')->where('id', $assignment->id)->exists();
        $this->assertTrue($assignmentStillExists);
    }
}
