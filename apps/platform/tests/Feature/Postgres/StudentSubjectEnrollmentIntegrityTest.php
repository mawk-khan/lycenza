<?php

namespace Tests\Feature\Postgres;

use App\Domain\AcademicStructure\Infrastructure\SubjectOffering;
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
 * Phase 1C.1 (mandatory): `student_subject_enrollments` proven at the
 * raw-SQL level against real PostgreSQL, under the unprivileged
 * `school_os_app` runtime role, independent of Eloquent -- mirrors
 * StudentEnrollmentIntegrityTest's pattern exactly.
 */
class StudentSubjectEnrollmentIntegrityTest extends TestCase
{
    use CreatesTenancyFixtures;

    private function setSchool(string $schoolId): void
    {
        DB::connection('pgsql')->select('select set_config(?, ?, false)', [TenantRls::SESSION_VAR, $schoolId]);
    }

    /**
     * @return array{school: School, student: Student, offering: SubjectOffering}
     */
    private function buildFullContext(bool $required = false): array
    {
        $school = $this->createSchool();
        $campus = $this->createCampus($school);
        $year = $this->createAcademicYear($school);
        $grade = $this->createGradeLevel($school);
        $subject = $this->createSubject($school);
        $offering = $this->createSubjectOffering($year, $campus, $grade, $subject, ['is_required' => $required]);
        $student = $this->createStudent($school, ['student_number' => 'S-0001']);

        return compact('school', 'student', 'offering');
    }

    private function insertSubjectEnrollment(
        string $schoolId,
        string $studentId,
        string $subjectOfferingId,
        string $academicYearId,
        ?string $connection = 'pgsql',
    ): void {
        DB::connection($connection)->insert(
            'insert into student_subject_enrollments '.
            '(id, school_id, student_id, subject_offering_id, academic_year_id, starts_on, created_at, updated_at) '.
            'values (?, ?, ?, ?, current_date, now(), now())',
            [(string) Str::orderedUuid(), $schoolId, $studentId, $subjectOfferingId, $academicYearId],
        );
    }

    #[Test]
    public function the_table_has_rls_enabled_and_forced(): void
    {
        $row = DB::connection('pgsql_admin')->selectOne(
            'select relrowsecurity, relforcerowsecurity from pg_class '.
            'where relname = ? and relnamespace = ?::regnamespace',
            ['student_subject_enrollments', 'public'],
        );

        $this->assertNotNull($row, 'student_subject_enrollments must exist');
        $this->assertTrue($row->relrowsecurity, 'student_subject_enrollments must have RLS enabled');
        $this->assertTrue($row->relforcerowsecurity, 'student_subject_enrollments must FORCE RLS');
    }

    #[Test]
    public function no_school_context_sees_zero_rows(): void
    {
        ['student' => $student, 'offering' => $offering] = $this->buildFullContext();
        $this->createStudentSubjectEnrollment($student, $offering);

        DB::connection('pgsql')->statement('RESET '.TenantRls::SESSION_VAR);

        $count = DB::connection('pgsql')->selectOne('select count(*) as c from student_subject_enrollments')->c;
        $this->assertSame(0, (int) $count, 'student_subject_enrollments must be invisible with no TenantContext');
    }

    #[Test]
    public function school_a_cannot_read_school_bs_subject_enrollment_row(): void
    {
        $schoolA = $this->createSchool();
        ['student' => $studentB, 'offering' => $offeringB] = $this->buildFullContext();
        $enrollmentB = $this->createStudentSubjectEnrollment($studentB, $offeringB);

        $this->setSchool($schoolA->id);

        $rows = DB::connection('pgsql')->select('select id from student_subject_enrollments where id = ?', [$enrollmentB->id]);
        $this->assertCount(0, $rows, "School A must not see School B's subject enrollment row");
    }

    #[Test]
    public function cross_school_writes_affect_zero_rows(): void
    {
        $schoolA = $this->createSchool();
        ['student' => $studentB, 'offering' => $offeringB] = $this->buildFullContext();
        $enrollmentB = $this->createStudentSubjectEnrollment($studentB, $offeringB);

        $this->setSchool($schoolA->id);

        $this->assertSame(0, DB::connection('pgsql')->update(
            "update student_subject_enrollments set status = 'withdrawn' where id = ?",
            [$enrollmentB->id],
        ));
        $this->assertSame(0, DB::connection('pgsql')->delete(
            'delete from student_subject_enrollments where id = ?',
            [$enrollmentB->id],
        ));
    }

    #[Test]
    public function school_a_cannot_create_a_subject_enrollment_assigned_to_school_b(): void
    {
        $schoolA = $this->createSchool();
        ['school' => $schoolB, 'student' => $studentB, 'offering' => $offeringB] = $this->buildFullContext();

        $this->setSchool($schoolA->id);

        $this->expectException(QueryException::class);

        DB::connection('pgsql')->transaction(function () use ($schoolB, $studentB, $offeringB): void {
            $this->insertSubjectEnrollment($schoolB->id, $studentB->id, $offeringB->id, $offeringB->academic_year_id);
        });
    }

    #[Test]
    public function a_subject_enrollment_cannot_reference_another_schools_student(): void
    {
        $schoolA = $this->createSchool();
        $campusA = $this->createCampus($schoolA);
        $yearA = $this->createAcademicYear($schoolA);
        $gradeA = $this->createGradeLevel($schoolA);
        $subjectA = $this->createSubject($schoolA);
        $offeringA = $this->createSubjectOffering($yearA, $campusA, $gradeA, $subjectA);
        ['student' => $studentB] = $this->buildFullContext();

        $this->expectException(QueryException::class);

        // Via pgsql_admin (bypasses RLS's own WITH CHECK, isolating that
        // the failure is purely the composite FK).
        $this->insertSubjectEnrollment($schoolA->id, $studentB->id, $offeringA->id, $offeringA->academic_year_id, 'pgsql_admin');
    }

    #[Test]
    public function a_subject_enrollment_cannot_reference_another_schools_subject_offering(): void
    {
        $schoolA = $this->createSchool();
        $studentA = $this->createStudent($schoolA, ['student_number' => 'S-0001']);
        $yearA = $this->createAcademicYear($schoolA);
        ['offering' => $offeringB] = $this->buildFullContext();

        $this->expectException(QueryException::class);

        $this->insertSubjectEnrollment($schoolA->id, $studentA->id, $offeringB->id, $yearA->id, 'pgsql_admin');
    }

    #[Test]
    public function a_subject_enrollment_cannot_reference_another_schools_academic_year(): void
    {
        $schoolA = $this->createSchool();
        $studentA = $this->createStudent($schoolA, ['student_number' => 'S-0001']);
        $campusA = $this->createCampus($schoolA);
        $gradeA = $this->createGradeLevel($schoolA);
        $subjectA = $this->createSubject($schoolA);
        $offeringA = $this->createSubjectOffering($this->createAcademicYear($schoolA), $campusA, $gradeA, $subjectA);
        ['offering' => $offeringB] = $this->buildFullContext();

        $this->expectException(QueryException::class);

        $this->insertSubjectEnrollment($schoolA->id, $studentA->id, $offeringA->id, $offeringB->academic_year_id, 'pgsql_admin');
    }
}
