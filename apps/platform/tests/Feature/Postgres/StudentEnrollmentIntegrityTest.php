<?php

namespace Tests\Feature\Postgres;

use App\Domain\AcademicStructure\Infrastructure\Section;
use App\Domain\Students\Infrastructure\Student;
use App\Models\School;
use App\Support\Tenancy\TenantRls;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 1B.1 (mandatory): `student_enrollments` proven at the raw-SQL
 * level against real PostgreSQL, under the unprivileged `school_os_app`
 * runtime role, independent of Eloquent -- mirrors
 * StudentGuardianRelationshipIntegrityTest's pattern exactly. Covers
 * RLS isolation (this table's own tenant boundary) and every
 * composite-FK cross-School integrity guarantee (Student, AcademicYear,
 * Campus, GradeLevel, Section) that makes a cross-School Enrollment
 * structurally impossible, not just application-validated.
 */
class StudentEnrollmentIntegrityTest extends TestCase
{
    use CreatesTenancyFixtures;

    private function setSchool(string $schoolId): void
    {
        DB::connection('pgsql')->select('select set_config(?, ?, false)', [TenantRls::SESSION_VAR, $schoolId]);
    }

    /**
     * @return array{school: School, student: Student, section: Section}
     */
    private function buildFullContext(): array
    {
        $school = $this->createSchool();
        $campus = $this->createCampus($school);
        $year = $this->createAcademicYear($school);
        $grade = $this->createGradeLevel($school);
        $section = $this->createSection($year, $campus, $grade);
        $student = $this->createStudent($school, ['student_number' => 'S-0001']);

        return compact('school', 'student', 'section');
    }

    private function insertEnrollment(
        string $schoolId,
        string $studentId,
        string $academicYearId,
        string $campusId,
        string $gradeLevelId,
        string $sectionId,
        ?string $connection = 'pgsql',
    ): void {
        DB::connection($connection)->insert(
            'insert into student_enrollments '.
            '(id, school_id, student_id, academic_year_id, campus_id, grade_level_id, section_id, roll_number, starts_on, created_at, updated_at) '.
            "values (?, ?, ?, ?, ?, ?, ?, '1', current_date, now(), now())",
            [(string) Str::orderedUuid(), $schoolId, $studentId, $academicYearId, $campusId, $gradeLevelId, $sectionId],
        );
    }

    #[Test]
    public function the_table_has_rls_enabled_and_forced(): void
    {
        $row = DB::connection('pgsql_admin')->selectOne(
            'select relrowsecurity, relforcerowsecurity from pg_class '.
            'where relname = ? and relnamespace = ?::regnamespace',
            ['student_enrollments', 'public'],
        );

        $this->assertNotNull($row, 'student_enrollments must exist');
        $this->assertTrue($row->relrowsecurity, 'student_enrollments must have RLS enabled');
        $this->assertTrue($row->relforcerowsecurity, 'student_enrollments must FORCE RLS');
    }

    #[Test]
    public function no_school_context_sees_zero_rows(): void
    {
        ['student' => $student, 'section' => $section] = $this->buildFullContext();
        $this->createStudentEnrollment($student, $section);

        DB::connection('pgsql')->statement('RESET '.TenantRls::SESSION_VAR);

        $count = DB::connection('pgsql')->selectOne('select count(*) as c from student_enrollments')->c;
        $this->assertSame(0, (int) $count, 'student_enrollments must be invisible with no TenantContext');
    }

    #[Test]
    public function school_a_cannot_read_school_bs_enrollment_row(): void
    {
        $schoolA = $this->createSchool();
        ['student' => $studentB, 'section' => $sectionB] = $this->buildFullContext();
        $enrollmentB = $this->createStudentEnrollment($studentB, $sectionB);

        $this->setSchool($schoolA->id);

        $rows = DB::connection('pgsql')->select('select id from student_enrollments where id = ?', [$enrollmentB->id]);
        $this->assertCount(0, $rows, "School A must not see School B's enrollment row");
    }

    #[Test]
    public function cross_school_writes_affect_zero_rows(): void
    {
        $schoolA = $this->createSchool();
        ['student' => $studentB, 'section' => $sectionB] = $this->buildFullContext();
        $enrollmentB = $this->createStudentEnrollment($studentB, $sectionB);

        $this->setSchool($schoolA->id);

        $this->assertSame(0, DB::connection('pgsql')->update(
            "update student_enrollments set status = 'withdrawn' where id = ?",
            [$enrollmentB->id],
        ));
        $this->assertSame(0, DB::connection('pgsql')->delete(
            'delete from student_enrollments where id = ?',
            [$enrollmentB->id],
        ));
    }

    #[Test]
    public function school_a_cannot_create_an_enrollment_assigned_to_school_b(): void
    {
        $schoolA = $this->createSchool();
        ['school' => $schoolB, 'student' => $studentB, 'section' => $sectionB] = $this->buildFullContext();

        $this->setSchool($schoolA->id);

        $this->expectException(QueryException::class);

        // Wrapped in its own transaction (a nested SAVEPOINT) so the
        // expected RLS WITH CHECK failure rolls back cleanly instead of
        // leaving the `pgsql` connection in Postgres's "current
        // transaction is aborted" state for tearDown()'s RESET
        // statement -- see StudentGuardianRelationshipIntegrityTest's
        // identical pattern.
        DB::connection('pgsql')->transaction(function () use ($schoolB, $studentB, $sectionB): void {
            $this->insertEnrollment($schoolB->id, $studentB->id, $sectionB->academic_year_id, $sectionB->campus_id, $sectionB->grade_level_id, $sectionB->id);
        });
    }

    #[Test]
    public function an_enrollment_cannot_reference_another_schools_student(): void
    {
        $schoolA = $this->createSchool();
        $campusA = $this->createCampus($schoolA);
        $yearA = $this->createAcademicYear($schoolA);
        $gradeA = $this->createGradeLevel($schoolA);
        $sectionA = $this->createSection($yearA, $campusA, $gradeA);
        ['student' => $studentB] = $this->buildFullContext();

        $this->expectException(QueryException::class);

        // Via pgsql_admin (bypasses RLS's own WITH CHECK, isolating that
        // the failure is purely the composite FK).
        $this->insertEnrollment($schoolA->id, $studentB->id, $sectionA->academic_year_id, $sectionA->campus_id, $sectionA->grade_level_id, $sectionA->id, 'pgsql_admin');
    }

    #[Test]
    public function an_enrollment_cannot_reference_another_schools_section(): void
    {
        $schoolA = $this->createSchool();
        $studentA = $this->createStudent($schoolA, ['student_number' => 'S-0001']);
        $campusA = $this->createCampus($schoolA);
        $yearA = $this->createAcademicYear($schoolA);
        $gradeA = $this->createGradeLevel($schoolA);
        ['section' => $sectionB] = $this->buildFullContext();

        $this->expectException(QueryException::class);

        $this->insertEnrollment($schoolA->id, $studentA->id, $yearA->id, $campusA->id, $gradeA->id, $sectionB->id, 'pgsql_admin');
    }

    #[Test]
    public function an_enrollment_cannot_reference_another_schools_academic_year(): void
    {
        $schoolA = $this->createSchool();
        $studentA = $this->createStudent($schoolA, ['student_number' => 'S-0001']);
        $campusA = $this->createCampus($schoolA);
        $gradeA = $this->createGradeLevel($schoolA);
        $sectionA = $this->createSection($this->createAcademicYear($schoolA), $campusA, $gradeA);
        ['section' => $sectionB] = $this->buildFullContext();

        $this->expectException(QueryException::class);

        $this->insertEnrollment($schoolA->id, $studentA->id, $sectionB->academic_year_id, $sectionA->campus_id, $sectionA->grade_level_id, $sectionA->id, 'pgsql_admin');
    }
}
