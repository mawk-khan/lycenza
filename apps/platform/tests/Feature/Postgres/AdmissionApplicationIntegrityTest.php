<?php

namespace Tests\Feature\Postgres;

use App\Domain\AcademicStructure\Infrastructure\AcademicYear;
use App\Domain\AcademicStructure\Infrastructure\GradeLevel;
use App\Domain\AcademicStructure\Infrastructure\Section;
use App\Domain\Admissions\Infrastructure\Applicant;
use App\Domain\Students\Infrastructure\Student;
use App\Domain\Students\Infrastructure\StudentEnrollment;
use App\Models\Campus;
use App\Models\School;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantRls;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 1D.1 (mandatory, root CLAUDE.md rule 28): `applicants` and
 * `admission_applications` proven at the raw-SQL level against real
 * PostgreSQL, under the unprivileged `school_os_app` runtime role,
 * independent of Eloquent -- mirrors StudentSubjectEnrollmentIntegrityTest's
 * pattern exactly (docs/modules/ADMISSIONS.md §12).
 *
 * Two separate fixture-builder families, deliberately never mixed
 * within one test: `buildContext()`/`buildConvertedTarget()` create
 * rows via the plain `pgsql` (RLS-enforced, `Tests\TestCase`'s
 * `DatabaseTransactions`-wrapped) connection, for tests that exercise
 * that SAME connection; `buildAdminContext()`/`buildAdminConvertedTarget()`
 * create rows via `pgsql_admin` instead, for tests whose real
 * assertion also runs on `pgsql_admin` (FK/CHECK/uniqueness isolation
 * tests, which deliberately bypass RLS to isolate a non-RLS failure).
 * `DatabaseTransactions` wraps each connection in its own per-test,
 * uncommitted transaction -- a row inserted on one connection is
 * invisible to a DIFFERENT connection's session until that row's
 * transaction commits (ordinary PostgreSQL MVCC), so a test whose
 * decisive statement runs on `pgsql_admin` but whose supporting
 * fixture rows were created via `pgsql` would hang forever: the
 * `pgsql_admin` statement's FK/uniqueness check has to wait to learn
 * whether the still-open `pgsql` transaction that created the
 * referenced row will commit or abort, and that transaction only ends
 * when ITS test method returns -- a lock a single test method can
 * never resolve on its own.
 */
class AdmissionApplicationIntegrityTest extends TestCase
{
    use CreatesTenancyFixtures;

    private function setSchool(string $schoolId): void
    {
        DB::connection('pgsql')->select('select set_config(?, ?, false)', [TenantRls::SESSION_VAR, $schoolId]);
    }

    /**
     * @return array{school: School, applicant: Applicant, year: AcademicYear, campus: Campus, grade: GradeLevel}
     */
    private function buildContext(): array
    {
        $school = $this->createSchool();
        $applicant = $this->createApplicant($school);
        $year = $this->createAcademicYear($school);
        $campus = $this->createCampus($school);
        $grade = $this->createGradeLevel($school);

        return compact('school', 'applicant', 'year', 'campus', 'grade');
    }

    /**
     * A real, same-School Student + StudentEnrollment -- used to build
     * valid conversion provenance tuples.
     *
     * @return array{student: Student, enrollment: StudentEnrollment}
     */
    private function buildConvertedTarget(School $school, AcademicYear $year, Campus $campus, GradeLevel $grade): array
    {
        $section = $this->createSection($year, $campus, $grade);
        $student = $this->createStudent($school, ['student_number' => 'S-'.Str::random(8)]);
        $enrollment = $this->createStudentEnrollment($student, $section, ['status' => 'active']);

        return compact('student', 'enrollment');
    }

    /**
     * Same shape as buildContext(), but every row (including the
     * School itself, needed for admission_applications.school_id's
     * plain FK) is created via the `pgsql_admin` connection -- see the
     * class docblock.
     *
     * @return array{school: School, applicant: Applicant, year: AcademicYear, campus: Campus, grade: GradeLevel}
     */
    private function buildAdminContext(): array
    {
        $school = School::factory()->connection('pgsql_admin')->create();

        return app(TenantContext::class)->withSchool($school, function () use ($school): array {
            $applicant = Applicant::factory()->connection('pgsql_admin')->for($school, 'school')->create();
            $year = AcademicYear::factory()->connection('pgsql_admin')->for($school, 'school')->create();
            $campus = Campus::factory()->connection('pgsql_admin')->for($school)->create();
            $grade = GradeLevel::factory()->connection('pgsql_admin')->for($school, 'school')->create();

            return compact('school', 'applicant', 'year', 'campus', 'grade');
        });
    }

    /**
     * Admin-connection equivalent of buildConvertedTarget() -- see
     * buildAdminContext().
     *
     * @return array{student: Student, enrollment: StudentEnrollment}
     */
    private function buildAdminConvertedTarget(School $school, AcademicYear $year, Campus $campus, GradeLevel $grade): array
    {
        return app(TenantContext::class)->withSchool($school, function () use ($school, $year, $campus, $grade): array {
            $section = Section::factory()->connection('pgsql_admin')->create([
                'school_id' => $school->id,
                'academic_year_id' => $year->id,
                'campus_id' => $campus->id,
                'grade_level_id' => $grade->id,
            ]);
            $student = Student::factory()->connection('pgsql_admin')->for($school, 'school')->create([
                'student_number' => 'S-'.Str::random(8),
            ]);
            $enrollment = StudentEnrollment::factory()->connection('pgsql_admin')->create([
                'school_id' => $school->id,
                'student_id' => $student->id,
                'academic_year_id' => $year->id,
                'campus_id' => $campus->id,
                'grade_level_id' => $grade->id,
                'section_id' => $section->id,
                'status' => 'active',
            ]);

            return compact('student', 'enrollment');
        });
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function insertApplication(array $overrides, ?string $connection = 'pgsql'): void
    {
        DB::connection($connection)->insert(
            'insert into admission_applications '.
            '(id, school_id, applicant_id, academic_year_id, campus_id, grade_level_id, status, '.
            'converted_student_id, converted_student_enrollment_id, converted_at, created_at, updated_at) '.
            'values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, now(), now())',
            [
                $overrides['id'] ?? (string) Str::orderedUuid(),
                $overrides['school_id'],
                $overrides['applicant_id'],
                $overrides['academic_year_id'],
                $overrides['campus_id'],
                $overrides['grade_level_id'],
                $overrides['status'] ?? 'draft',
                $overrides['converted_student_id'] ?? null,
                $overrides['converted_student_enrollment_id'] ?? null,
                $overrides['converted_at'] ?? null,
            ],
        );
    }

    // --- RLS -----------------------------------------------------------------

    #[Test]
    public function both_tables_have_rls_enabled_and_forced(): void
    {
        foreach (['applicants', 'admission_applications'] as $table) {
            $row = DB::connection('pgsql_admin')->selectOne(
                'select relrowsecurity, relforcerowsecurity from pg_class '.
                'where relname = ? and relnamespace = ?::regnamespace',
                [$table, 'public'],
            );

            $this->assertNotNull($row, "{$table} must exist");
            $this->assertTrue($row->relrowsecurity, "{$table} must have RLS enabled");
            $this->assertTrue($row->relforcerowsecurity, "{$table} must FORCE RLS");
        }
    }

    #[Test]
    public function no_school_context_sees_zero_rows(): void
    {
        ['applicant' => $applicant, 'year' => $year, 'campus' => $campus, 'grade' => $grade] = $this->buildContext();
        $this->createAdmissionApplication($applicant, $year, $campus, $grade);

        DB::connection('pgsql')->statement('RESET '.TenantRls::SESSION_VAR);

        $applicantCount = DB::connection('pgsql')->selectOne('select count(*) as c from applicants')->c;
        $applicationCount = DB::connection('pgsql')->selectOne('select count(*) as c from admission_applications')->c;
        $this->assertSame(0, (int) $applicantCount, 'applicants must be invisible with no TenantContext');
        $this->assertSame(0, (int) $applicationCount, 'admission_applications must be invisible with no TenantContext');
    }

    #[Test]
    public function school_a_cannot_read_school_bs_applicant_or_application(): void
    {
        $schoolA = $this->createSchool();
        ['applicant' => $applicantB, 'year' => $yearB, 'campus' => $campusB, 'grade' => $gradeB] = $this->buildContext();
        $applicationB = $this->createAdmissionApplication($applicantB, $yearB, $campusB, $gradeB);

        $this->setSchool($schoolA->id);

        $applicantRows = DB::connection('pgsql')->select('select id from applicants where id = ?', [$applicantB->id]);
        $applicationRows = DB::connection('pgsql')->select('select id from admission_applications where id = ?', [$applicationB->id]);
        $this->assertCount(0, $applicantRows, "School A must not see School B's Applicant");
        $this->assertCount(0, $applicationRows, "School A must not see School B's AdmissionApplication");
    }

    #[Test]
    public function cross_school_writes_affect_zero_rows(): void
    {
        $schoolA = $this->createSchool();
        ['applicant' => $applicantB, 'year' => $yearB, 'campus' => $campusB, 'grade' => $gradeB] = $this->buildContext();
        $applicationB = $this->createAdmissionApplication($applicantB, $yearB, $campusB, $gradeB);

        $this->setSchool($schoolA->id);

        $this->assertSame(0, DB::connection('pgsql')->update(
            "update applicants set first_name = 'Renamed' where id = ?",
            [$applicantB->id],
        ));
        $this->assertSame(0, DB::connection('pgsql')->update(
            "update admission_applications set status = 'withdrawn' where id = ?",
            [$applicationB->id],
        ));
        $this->assertSame(0, DB::connection('pgsql')->delete(
            'delete from admission_applications where id = ?',
            [$applicationB->id],
        ));
    }

    #[Test]
    public function school_a_cannot_create_an_applicant_assigned_to_school_b(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();

        $this->setSchool($schoolA->id);

        $this->expectException(QueryException::class);

        DB::connection('pgsql')->insert(
            'insert into applicants (id, school_id, first_name, date_of_birth, created_at, updated_at) '.
            'values (?, ?, ?, ?, now(), now())',
            [(string) Str::orderedUuid(), $schoolB->id, 'Forged', '2015-01-01'],
        );
    }

    #[Test]
    public function school_a_cannot_create_an_application_assigned_to_school_b(): void
    {
        $schoolA = $this->createSchool();
        ['school' => $schoolB, 'applicant' => $applicantB, 'year' => $yearB, 'campus' => $campusB, 'grade' => $gradeB] = $this->buildContext();

        $this->setSchool($schoolA->id);

        $this->expectException(QueryException::class);

        $this->insertApplication([
            'school_id' => $schoolB->id,
            'applicant_id' => $applicantB->id,
            'academic_year_id' => $yearB->id,
            'campus_id' => $campusB->id,
            'grade_level_id' => $gradeB->id,
        ]);
    }

    // --- Cross-School composite FKs (isolated via pgsql_admin, bypassing --
    // --- RLS's own WITH CHECK so the failure source is purely the FK) ------

    #[Test]
    public function an_application_cannot_reference_another_schools_applicant(): void
    {
        ['school' => $schoolA, 'year' => $yearA, 'campus' => $campusA, 'grade' => $gradeA] = $this->buildAdminContext();
        ['applicant' => $applicantB] = $this->buildAdminContext();

        $this->expectException(QueryException::class);

        $this->insertApplication([
            'school_id' => $schoolA->id,
            'applicant_id' => $applicantB->id,
            'academic_year_id' => $yearA->id,
            'campus_id' => $campusA->id,
            'grade_level_id' => $gradeA->id,
        ], 'pgsql_admin');
    }

    #[Test]
    public function an_application_cannot_reference_another_schools_academic_year(): void
    {
        ['school' => $schoolA, 'applicant' => $applicantA, 'campus' => $campusA, 'grade' => $gradeA] = $this->buildAdminContext();
        ['year' => $yearB] = $this->buildAdminContext();

        $this->expectException(QueryException::class);

        $this->insertApplication([
            'school_id' => $schoolA->id,
            'applicant_id' => $applicantA->id,
            'academic_year_id' => $yearB->id,
            'campus_id' => $campusA->id,
            'grade_level_id' => $gradeA->id,
        ], 'pgsql_admin');
    }

    #[Test]
    public function an_application_cannot_reference_another_schools_campus(): void
    {
        ['school' => $schoolA, 'applicant' => $applicantA, 'year' => $yearA, 'grade' => $gradeA] = $this->buildAdminContext();
        ['campus' => $campusB] = $this->buildAdminContext();

        $this->expectException(QueryException::class);

        $this->insertApplication([
            'school_id' => $schoolA->id,
            'applicant_id' => $applicantA->id,
            'academic_year_id' => $yearA->id,
            'campus_id' => $campusB->id,
            'grade_level_id' => $gradeA->id,
        ], 'pgsql_admin');
    }

    #[Test]
    public function an_application_cannot_reference_another_schools_grade_level(): void
    {
        ['school' => $schoolA, 'applicant' => $applicantA, 'year' => $yearA, 'campus' => $campusA] = $this->buildAdminContext();
        ['grade' => $gradeB] = $this->buildAdminContext();

        $this->expectException(QueryException::class);

        $this->insertApplication([
            'school_id' => $schoolA->id,
            'applicant_id' => $applicantA->id,
            'academic_year_id' => $yearA->id,
            'campus_id' => $campusA->id,
            'grade_level_id' => $gradeB->id,
        ], 'pgsql_admin');
    }

    #[Test]
    public function an_application_cannot_reference_another_schools_converted_student(): void
    {
        ['school' => $schoolA, 'applicant' => $applicantA, 'year' => $yearA, 'campus' => $campusA, 'grade' => $gradeA] = $this->buildAdminContext();
        ['enrollment' => $enrollmentA] = $this->buildAdminConvertedTarget($schoolA, $yearA, $campusA, $gradeA);
        ['school' => $schoolB, 'year' => $yearB, 'campus' => $campusB, 'grade' => $gradeB] = $this->buildAdminContext();
        ['student' => $studentB] = $this->buildAdminConvertedTarget($schoolB, $yearB, $campusB, $gradeB);

        $this->expectException(QueryException::class);

        $this->insertApplication([
            'school_id' => $schoolA->id,
            'applicant_id' => $applicantA->id,
            'academic_year_id' => $yearA->id,
            'campus_id' => $campusA->id,
            'grade_level_id' => $gradeA->id,
            'status' => 'converted',
            'converted_student_id' => $studentB->id,
            'converted_student_enrollment_id' => $enrollmentA->id,
            'converted_at' => Carbon::now()->toDateTimeString(),
        ], 'pgsql_admin');
    }

    #[Test]
    public function an_application_cannot_reference_another_schools_converted_student_enrollment(): void
    {
        ['school' => $schoolA, 'applicant' => $applicantA, 'year' => $yearA, 'campus' => $campusA, 'grade' => $gradeA] = $this->buildAdminContext();
        ['student' => $studentA] = $this->buildAdminConvertedTarget($schoolA, $yearA, $campusA, $gradeA);
        ['school' => $schoolB, 'year' => $yearB, 'campus' => $campusB, 'grade' => $gradeB] = $this->buildAdminContext();
        ['enrollment' => $enrollmentB] = $this->buildAdminConvertedTarget($schoolB, $yearB, $campusB, $gradeB);

        $this->expectException(QueryException::class);

        $this->insertApplication([
            'school_id' => $schoolA->id,
            'applicant_id' => $applicantA->id,
            'academic_year_id' => $yearA->id,
            'campus_id' => $campusA->id,
            'grade_level_id' => $gradeA->id,
            'status' => 'converted',
            'converted_student_id' => $studentA->id,
            'converted_student_enrollment_id' => $enrollmentB->id,
            'converted_at' => Carbon::now()->toDateTimeString(),
        ], 'pgsql_admin');
    }

    // --- Conversion provenance CHECK ------------------------------------------

    #[Test]
    public function converted_with_complete_provenance_is_allowed(): void
    {
        ['school' => $school, 'applicant' => $applicant, 'year' => $year, 'campus' => $campus, 'grade' => $grade] = $this->buildAdminContext();
        ['student' => $student, 'enrollment' => $enrollment] = $this->buildAdminConvertedTarget($school, $year, $campus, $grade);

        $this->insertApplication([
            'school_id' => $school->id,
            'applicant_id' => $applicant->id,
            'academic_year_id' => $year->id,
            'campus_id' => $campus->id,
            'grade_level_id' => $grade->id,
            'status' => 'converted',
            'converted_student_id' => $student->id,
            'converted_student_enrollment_id' => $enrollment->id,
            'converted_at' => Carbon::now()->toDateTimeString(),
        ], 'pgsql_admin');

        $count = DB::connection('pgsql_admin')->selectOne(
            "select count(*) as c from admission_applications where status = 'converted' and converted_student_id = ?",
            [$student->id],
        )->c;
        $this->assertSame(1, (int) $count);
    }

    #[Test]
    public function converted_with_missing_student_is_blocked(): void
    {
        ['school' => $school, 'applicant' => $applicant, 'year' => $year, 'campus' => $campus, 'grade' => $grade] = $this->buildAdminContext();
        ['enrollment' => $enrollment] = $this->buildAdminConvertedTarget($school, $year, $campus, $grade);

        $this->expectException(QueryException::class);

        $this->insertApplication([
            'school_id' => $school->id,
            'applicant_id' => $applicant->id,
            'academic_year_id' => $year->id,
            'campus_id' => $campus->id,
            'grade_level_id' => $grade->id,
            'status' => 'converted',
            'converted_student_id' => null,
            'converted_student_enrollment_id' => $enrollment->id,
            'converted_at' => Carbon::now()->toDateTimeString(),
        ], 'pgsql_admin');
    }

    #[Test]
    public function converted_with_missing_enrollment_is_blocked(): void
    {
        ['school' => $school, 'applicant' => $applicant, 'year' => $year, 'campus' => $campus, 'grade' => $grade] = $this->buildAdminContext();
        ['student' => $student] = $this->buildAdminConvertedTarget($school, $year, $campus, $grade);

        $this->expectException(QueryException::class);

        $this->insertApplication([
            'school_id' => $school->id,
            'applicant_id' => $applicant->id,
            'academic_year_id' => $year->id,
            'campus_id' => $campus->id,
            'grade_level_id' => $grade->id,
            'status' => 'converted',
            'converted_student_id' => $student->id,
            'converted_student_enrollment_id' => null,
            'converted_at' => Carbon::now()->toDateTimeString(),
        ], 'pgsql_admin');
    }

    #[Test]
    public function converted_with_missing_converted_at_is_blocked(): void
    {
        ['school' => $school, 'applicant' => $applicant, 'year' => $year, 'campus' => $campus, 'grade' => $grade] = $this->buildAdminContext();
        ['student' => $student, 'enrollment' => $enrollment] = $this->buildAdminConvertedTarget($school, $year, $campus, $grade);

        $this->expectException(QueryException::class);

        $this->insertApplication([
            'school_id' => $school->id,
            'applicant_id' => $applicant->id,
            'academic_year_id' => $year->id,
            'campus_id' => $campus->id,
            'grade_level_id' => $grade->id,
            'status' => 'converted',
            'converted_student_id' => $student->id,
            'converted_student_enrollment_id' => $enrollment->id,
            'converted_at' => null,
        ], 'pgsql_admin');
    }

    #[Test]
    public function a_non_converted_status_cannot_carry_conversion_provenance(): void
    {
        ['school' => $school, 'applicant' => $applicant, 'year' => $year, 'campus' => $campus, 'grade' => $grade] = $this->buildAdminContext();
        ['student' => $student, 'enrollment' => $enrollment] = $this->buildAdminConvertedTarget($school, $year, $campus, $grade);

        $this->expectException(QueryException::class);

        $this->insertApplication([
            'school_id' => $school->id,
            'applicant_id' => $applicant->id,
            'academic_year_id' => $year->id,
            'campus_id' => $campus->id,
            'grade_level_id' => $grade->id,
            'status' => 'accepted',
            'converted_student_id' => $student->id,
            'converted_student_enrollment_id' => $enrollment->id,
            'converted_at' => Carbon::now()->toDateTimeString(),
        ], 'pgsql_admin');
    }

    // --- Open-application uniqueness -----------------------------------------

    #[Test]
    public function a_second_simultaneously_open_application_in_the_same_context_is_rejected(): void
    {
        ['school' => $school, 'applicant' => $applicant, 'year' => $year, 'campus' => $campus, 'grade' => $grade] = $this->buildAdminContext();
        $this->insertApplication([
            'school_id' => $school->id,
            'applicant_id' => $applicant->id,
            'academic_year_id' => $year->id,
            'campus_id' => $campus->id,
            'grade_level_id' => $grade->id,
            'status' => 'submitted',
        ], 'pgsql_admin');

        $this->expectException(QueryException::class);

        $this->insertApplication([
            'school_id' => $school->id,
            'applicant_id' => $applicant->id,
            'academic_year_id' => $year->id,
            'campus_id' => $campus->id,
            'grade_level_id' => $grade->id,
            'status' => 'draft',
        ], 'pgsql_admin');
    }

    #[Test]
    public function reapplication_after_rejected_is_allowed(): void
    {
        ['school' => $school, 'applicant' => $applicant, 'year' => $year, 'campus' => $campus, 'grade' => $grade] = $this->buildAdminContext();
        $this->insertApplication([
            'school_id' => $school->id,
            'applicant_id' => $applicant->id,
            'academic_year_id' => $year->id,
            'campus_id' => $campus->id,
            'grade_level_id' => $grade->id,
            'status' => 'rejected',
        ], 'pgsql_admin');

        $this->insertApplication([
            'school_id' => $school->id,
            'applicant_id' => $applicant->id,
            'academic_year_id' => $year->id,
            'campus_id' => $campus->id,
            'grade_level_id' => $grade->id,
            'status' => 'draft',
        ], 'pgsql_admin');

        $count = DB::connection('pgsql_admin')->selectOne(
            'select count(*) as c from admission_applications where applicant_id = ?',
            [$applicant->id],
        )->c;
        $this->assertSame(2, (int) $count);
    }

    #[Test]
    public function reapplication_after_withdrawn_is_allowed(): void
    {
        ['school' => $school, 'applicant' => $applicant, 'year' => $year, 'campus' => $campus, 'grade' => $grade] = $this->buildAdminContext();
        $this->insertApplication([
            'school_id' => $school->id,
            'applicant_id' => $applicant->id,
            'academic_year_id' => $year->id,
            'campus_id' => $campus->id,
            'grade_level_id' => $grade->id,
            'status' => 'withdrawn',
        ], 'pgsql_admin');

        $this->insertApplication([
            'school_id' => $school->id,
            'applicant_id' => $applicant->id,
            'academic_year_id' => $year->id,
            'campus_id' => $campus->id,
            'grade_level_id' => $grade->id,
            'status' => 'submitted',
        ], 'pgsql_admin');

        $count = DB::connection('pgsql_admin')->selectOne(
            'select count(*) as c from admission_applications where applicant_id = ?',
            [$applicant->id],
        )->c;
        $this->assertSame(2, (int) $count);
    }

    #[Test]
    public function reapplication_after_converted_is_allowed(): void
    {
        ['school' => $school, 'applicant' => $applicant, 'year' => $year, 'campus' => $campus, 'grade' => $grade] = $this->buildAdminContext();
        ['student' => $student, 'enrollment' => $enrollment] = $this->buildAdminConvertedTarget($school, $year, $campus, $grade);
        $this->insertApplication([
            'school_id' => $school->id,
            'applicant_id' => $applicant->id,
            'academic_year_id' => $year->id,
            'campus_id' => $campus->id,
            'grade_level_id' => $grade->id,
            'status' => 'converted',
            'converted_student_id' => $student->id,
            'converted_student_enrollment_id' => $enrollment->id,
            'converted_at' => Carbon::now()->toDateTimeString(),
        ], 'pgsql_admin');

        $this->insertApplication([
            'school_id' => $school->id,
            'applicant_id' => $applicant->id,
            'academic_year_id' => $year->id,
            'campus_id' => $campus->id,
            'grade_level_id' => $grade->id,
            'status' => 'draft',
        ], 'pgsql_admin');

        $count = DB::connection('pgsql_admin')->selectOne(
            'select count(*) as c from admission_applications where applicant_id = ?',
            [$applicant->id],
        )->c;
        $this->assertSame(2, (int) $count);
    }

    #[Test]
    public function an_open_application_in_a_different_academic_year_is_allowed_alongside_an_existing_open_one(): void
    {
        ['school' => $school, 'applicant' => $applicant, 'campus' => $campus, 'grade' => $grade] = $this->buildAdminContext();
        $yearA = app(TenantContext::class)->withSchool(
            $school,
            fn () => AcademicYear::factory()->connection('pgsql_admin')->for($school, 'school')->create(['name' => 'Year A', 'code' => 'YR-A']),
        );
        $yearB = app(TenantContext::class)->withSchool(
            $school,
            fn () => AcademicYear::factory()->connection('pgsql_admin')->for($school, 'school')->create(['name' => 'Year B', 'code' => 'YR-B']),
        );
        $this->insertApplication([
            'school_id' => $school->id,
            'applicant_id' => $applicant->id,
            'academic_year_id' => $yearA->id,
            'campus_id' => $campus->id,
            'grade_level_id' => $grade->id,
            'status' => 'submitted',
        ], 'pgsql_admin');

        $this->insertApplication([
            'school_id' => $school->id,
            'applicant_id' => $applicant->id,
            'academic_year_id' => $yearB->id,
            'campus_id' => $campus->id,
            'grade_level_id' => $grade->id,
            'status' => 'submitted',
        ], 'pgsql_admin');

        $count = DB::connection('pgsql_admin')->selectOne(
            'select count(*) as c from admission_applications where applicant_id = ?',
            [$applicant->id],
        )->c;
        $this->assertSame(2, (int) $count);
    }
}
