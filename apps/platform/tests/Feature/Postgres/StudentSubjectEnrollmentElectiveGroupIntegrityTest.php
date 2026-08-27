<?php

namespace Tests\Feature\Postgres;

use App\Domain\AcademicStructure\Infrastructure\AcademicYear;
use App\Domain\AcademicStructure\Infrastructure\ElectiveGroup;
use App\Domain\AcademicStructure\Infrastructure\GradeLevel;
use App\Domain\AcademicStructure\Infrastructure\SubjectOffering;
use App\Domain\Students\Infrastructure\Student;
use App\Domain\Students\Infrastructure\StudentEnrollment;
use App\Models\Campus;
use App\Models\School;
use App\Support\Tenancy\TenantRls;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 1F.1 (mandatory, architecture doc §11A/§11B/§4 -- resolving
 * checkpoint brief §41/§42): the central Phase 1F.0B correction, proven
 * at the raw-SQL level against real PostgreSQL, independent of Eloquent
 * or App\Domain\Students\Application\StudentSubjectEnrollmentService
 * (which does not yet populate these columns -- Phase 1F.2).
 *
 * Deliberately uses the `pgsql` connection (the real, unprivileged
 * `school_os_app` runtime role) with an explicit tenant context set,
 * NOT `pgsql_admin` -- `pgsql_admin` is a genuinely separate PostgreSQL
 * session, and this test's fixtures are created inside the SAME
 * wrapping transaction PHPUnit's `DatabaseTransactions` opens on the
 * default (`pgsql`) connection only; a row that transaction hasn't
 * committed is invisible to a different session, which would make
 * every raw INSERT below observe a just-created SubjectOffering's
 * `elective_group_id` as NULL regardless of its real value. Using
 * `pgsql` with the correct School context keeps every check under
 * test (the trigger's own SELECT, the composite FKs, the partial
 * unique index) operating in the same session that created the data --
 * exactly like StudentSubjectEnrollmentIntegrityTest's `setSchool()` +
 * `pgsql`-connection positive-path tests. This is also the MORE
 * faithful test, since Phase 1F.2's real write path will use this same
 * runtime role, never `pgsql_admin`.
 */
class StudentSubjectEnrollmentElectiveGroupIntegrityTest extends TestCase
{
    use CreatesTenancyFixtures;

    private function setSchool(string $schoolId): void
    {
        DB::connection('pgsql')->select('select set_config(?, ?, false)', [TenantRls::SESSION_VAR, $schoolId]);
    }

    /**
     * @return array{
     *     school: School, campus: Campus, year: AcademicYear, grade: GradeLevel,
     *     group: ElectiveGroup, offeringA: SubjectOffering, offeringB: SubjectOffering,
     *     ungroupedOffering: SubjectOffering, student: Student, enrollment: StudentEnrollment,
     * }
     */
    private function buildGroupedContext(): array
    {
        $school = $this->createSchool();
        $campus = $this->createCampus($school);
        $year = $this->createAcademicYear($school);
        $grade = $this->createGradeLevel($school);
        $group = $this->createElectiveGroup($year, $campus, $grade);

        $subjectA = $this->createSubject($school);
        $offeringA = $this->createSubjectOffering($year, $campus, $grade, $subjectA, [
            'is_required' => false, 'elective_group_id' => $group->id,
        ]);

        $subjectB = $this->createSubject($school);
        $offeringB = $this->createSubjectOffering($year, $campus, $grade, $subjectB, [
            'is_required' => false, 'elective_group_id' => $group->id,
        ]);

        $subjectC = $this->createSubject($school);
        $ungroupedOffering = $this->createSubjectOffering($year, $campus, $grade, $subjectC, [
            'is_required' => false, 'elective_group_id' => null,
        ]);

        $section = $this->createSection($year, $campus, $grade);
        $student = $this->createStudent($school, ['student_number' => 'S-0001']);
        $enrollment = $this->createStudentEnrollment($student, $section);

        return compact('school', 'campus', 'year', 'grade', 'group', 'offeringA', 'offeringB', 'ungroupedOffering', 'student', 'enrollment');
    }

    private function insertParticipation(
        string $schoolId,
        string $studentId,
        ?string $studentEnrollmentId,
        string $subjectOfferingId,
        ?string $electiveGroupId,
        string $academicYearId,
        string $status = 'active',
    ): void {
        $this->setSchool($schoolId);

        DB::connection('pgsql')->insert(
            'insert into student_subject_enrollments '.
            '(id, school_id, student_id, student_enrollment_id, subject_offering_id, elective_group_id, academic_year_id, status, starts_on, created_at, updated_at) '.
            'values (?, ?, ?, ?, ?, ?, ?, ?, current_date, now(), now())',
            [(string) Str::orderedUuid(), $schoolId, $studentId, $studentEnrollmentId, $subjectOfferingId, $electiveGroupId, $academicYearId, $status],
        );
    }

    // --- §22/§25: the snapshot-equality trigger, including the NULL-bypass fix ---

    #[Test]
    public function grouped_offering_accepts_a_matching_group_snapshot(): void
    {
        ['school' => $school, 'group' => $group, 'offeringA' => $offering, 'student' => $student, 'enrollment' => $enrollment, 'year' => $year] = $this->buildGroupedContext();

        $this->insertParticipation($school->id, $student->id, $enrollment->id, $offering->id, $group->id, $year->id);

        $this->assertSame(1, DB::connection('pgsql')->table('student_subject_enrollments')->where('subject_offering_id', $offering->id)->count());
    }

    #[Test]
    public function grouped_offering_rejects_a_null_snapshot(): void
    {
        // This is the exact empirically-proven bypass Phase 1F.0B closes
        // (architecture doc §0B): under a composite-FK-only design, this
        // exact row was ACCEPTED. The trigger must now reject it.
        ['school' => $school, 'offeringA' => $offering, 'student' => $student, 'enrollment' => $enrollment, 'year' => $year] = $this->buildGroupedContext();

        $this->expectException(QueryException::class);

        $this->insertParticipation($school->id, $student->id, $enrollment->id, $offering->id, null, $year->id);
    }

    #[Test]
    public function grouped_offering_rejects_a_wrong_group_snapshot(): void
    {
        ['school' => $school, 'campus' => $campus, 'year' => $year, 'grade' => $grade, 'offeringA' => $offering, 'student' => $student, 'enrollment' => $enrollment] = $this->buildGroupedContext();
        $otherGroup = $this->createElectiveGroup($year, $campus, $grade, ['code' => 'OTHER']);

        $this->expectException(QueryException::class);

        $this->insertParticipation($school->id, $student->id, $enrollment->id, $offering->id, $otherGroup->id, $year->id);
    }

    #[Test]
    public function ungrouped_offering_accepts_a_null_snapshot(): void
    {
        ['school' => $school, 'ungroupedOffering' => $offering, 'student' => $student, 'enrollment' => $enrollment, 'year' => $year] = $this->buildGroupedContext();

        $this->insertParticipation($school->id, $student->id, $enrollment->id, $offering->id, null, $year->id);

        $this->assertSame(1, DB::connection('pgsql')->table('student_subject_enrollments')->where('subject_offering_id', $offering->id)->count());
    }

    #[Test]
    public function ungrouped_offering_rejects_a_non_null_snapshot(): void
    {
        ['school' => $school, 'group' => $group, 'ungroupedOffering' => $offering, 'student' => $student, 'enrollment' => $enrollment, 'year' => $year] = $this->buildGroupedContext();

        $this->expectException(QueryException::class);

        $this->insertParticipation($school->id, $student->id, $enrollment->id, $offering->id, $group->id, $year->id);
    }

    // --- §18/§21: grouped-requires-anchor CHECK + legacy NULL compatibility ---

    #[Test]
    public function a_grouped_row_cannot_omit_its_placement_anchor(): void
    {
        ['school' => $school, 'group' => $group, 'offeringA' => $offering, 'student' => $student, 'year' => $year] = $this->buildGroupedContext();

        $this->expectException(QueryException::class);

        $this->insertParticipation($school->id, $student->id, null, $offering->id, $group->id, $year->id);
    }

    #[Test]
    public function a_legacy_style_ungrouped_row_with_no_anchor_is_still_accepted(): void
    {
        ['school' => $school, 'ungroupedOffering' => $offering, 'student' => $student, 'year' => $year] = $this->buildGroupedContext();

        $this->insertParticipation($school->id, $student->id, null, $offering->id, null, $year->id);

        $this->assertSame(1, DB::connection('pgsql')->table('student_subject_enrollments')->where('subject_offering_id', $offering->id)->count());
    }

    // --- §18/§13: placement-anchor composite FK ---

    #[Test]
    public function placement_anchor_accepts_the_students_own_enrollment(): void
    {
        ['school' => $school, 'ungroupedOffering' => $offering, 'student' => $student, 'enrollment' => $enrollment, 'year' => $year] = $this->buildGroupedContext();

        $this->insertParticipation($school->id, $student->id, $enrollment->id, $offering->id, null, $year->id);

        $this->assertSame(1, DB::connection('pgsql')->table('student_subject_enrollments')->where('student_enrollment_id', $enrollment->id)->count());
    }

    #[Test]
    public function placement_anchor_rejects_another_students_enrollment(): void
    {
        ['school' => $school, 'campus' => $campus, 'year' => $year, 'grade' => $grade, 'ungroupedOffering' => $offering, 'student' => $studentA] = $this->buildGroupedContext();

        $studentB = $this->createStudent($school, ['student_number' => 'S-0002']);
        $sectionB = $this->createSection($year, $campus, $grade, ['name' => 'B', 'code' => 'B']);
        $enrollmentB = $this->createStudentEnrollment($studentB, $sectionB);

        $this->expectException(QueryException::class);

        // Student A's row, but claiming Student B's StudentEnrollment as its anchor.
        $this->insertParticipation($school->id, $studentA->id, $enrollmentB->id, $offering->id, null, $year->id);
    }

    #[Test]
    public function placement_anchor_rejects_an_enrollment_from_a_foreign_school(): void
    {
        ['school' => $schoolA, 'ungroupedOffering' => $offeringA, 'student' => $studentA, 'year' => $yearA] = $this->buildGroupedContext();
        ['student' => $studentB, 'enrollment' => $enrollmentB] = $this->buildGroupedContext();

        $this->expectException(QueryException::class);

        $this->insertParticipation($schoolA->id, $studentA->id, $enrollmentB->id, $offeringA->id, null, $yearA->id);
    }

    #[Test]
    public function placement_anchor_rejects_a_mismatched_academic_year(): void
    {
        ['school' => $school, 'ungroupedOffering' => $offering, 'student' => $student, 'enrollment' => $enrollmentYear1] = $this->buildGroupedContext();

        // A second StudentEnrollment for the SAME Student, a DIFFERENT
        // AcademicYear (both may be simultaneously active -- the
        // partial unique index is scoped per-year).
        $otherYear = $this->createAcademicYear($school, ['code' => 'OTHERYEAR']);
        $otherGrade = $this->createGradeLevel($school);
        $otherCampus = $this->createCampus($school);
        $otherSection = $this->createSection($otherYear, $otherCampus, $otherGrade);
        $enrollmentYear2 = $this->createStudentEnrollment($student, $otherSection);

        $this->expectException(QueryException::class);

        // References the real enrollment id (enrollmentYear2) but
        // claims the FIRST year's academic_year_id -- the FK tuple
        // (id, school_id, student_id, academic_year_id) does not match
        // any actual student_enrollments row.
        $this->insertParticipation($school->id, $student->id, $enrollmentYear2->id, $offering->id, null, $enrollmentYear1->academic_year_id);
    }

    // --- §28/§30: the group partial unique index ---

    #[Test]
    public function same_placement_second_active_offering_in_the_same_group_is_rejected(): void
    {
        ['school' => $school, 'group' => $group, 'offeringA' => $offeringA, 'offeringB' => $offeringB, 'student' => $student, 'enrollment' => $enrollment, 'year' => $year] = $this->buildGroupedContext();

        $this->insertParticipation($school->id, $student->id, $enrollment->id, $offeringA->id, $group->id, $year->id);

        $this->expectException(UniqueConstraintViolationException::class);

        $this->insertParticipation($school->id, $student->id, $enrollment->id, $offeringB->id, $group->id, $year->id);
    }

    #[Test]
    public function same_placement_a_different_group_is_allowed(): void
    {
        ['school' => $school, 'campus' => $campus, 'year' => $year, 'grade' => $grade, 'group' => $groupX, 'offeringA' => $offeringA, 'student' => $student, 'enrollment' => $enrollment] = $this->buildGroupedContext();

        $groupY = $this->createElectiveGroup($year, $campus, $grade, ['code' => 'GROUPY']);
        $subjectY = $this->createSubject($school);
        $offeringY = $this->createSubjectOffering($year, $campus, $grade, $subjectY, [
            'is_required' => false, 'elective_group_id' => $groupY->id,
        ]);

        $this->insertParticipation($school->id, $student->id, $enrollment->id, $offeringA->id, $groupX->id, $year->id);
        $this->insertParticipation($school->id, $student->id, $enrollment->id, $offeringY->id, $groupY->id, $year->id);

        $this->assertSame(2, DB::connection('pgsql')->table('student_subject_enrollments')->where('student_enrollment_id', $enrollment->id)->count());
    }

    #[Test]
    public function a_different_placement_in_the_same_group_is_allowed(): void
    {
        ['school' => $school, 'campus' => $campus, 'year' => $year, 'grade' => $grade, 'group' => $group, 'offeringA' => $offeringA, 'offeringB' => $offeringB, 'student' => $studentA, 'enrollment' => $enrollmentA] = $this->buildGroupedContext();

        $studentB = $this->createStudent($school, ['student_number' => 'S-0002']);
        $sectionB = $this->createSection($year, $campus, $grade, ['name' => 'B', 'code' => 'B']);
        $enrollmentB = $this->createStudentEnrollment($studentB, $sectionB);

        $this->insertParticipation($school->id, $studentA->id, $enrollmentA->id, $offeringA->id, $group->id, $year->id);
        $this->insertParticipation($school->id, $studentB->id, $enrollmentB->id, $offeringB->id, $group->id, $year->id);

        $this->assertSame(1, DB::connection('pgsql')->table('student_subject_enrollments')->where('student_enrollment_id', $enrollmentA->id)->count());
        $this->assertSame(1, DB::connection('pgsql')->table('student_subject_enrollments')->where('student_enrollment_id', $enrollmentB->id)->count());
    }

    #[Test]
    public function a_non_active_row_releases_its_group_slot(): void
    {
        ['school' => $school, 'group' => $group, 'offeringA' => $offeringA, 'offeringB' => $offeringB, 'student' => $student, 'enrollment' => $enrollment, 'year' => $year] = $this->buildGroupedContext();

        $this->insertParticipation($school->id, $student->id, $enrollment->id, $offeringA->id, $group->id, $year->id, 'active');

        DB::connection('pgsql')->table('student_subject_enrollments')
            ->where('subject_offering_id', $offeringA->id)
            ->update(['status' => 'withdrawn', 'ends_on' => now()->toDateString()]);

        $this->insertParticipation($school->id, $student->id, $enrollment->id, $offeringB->id, $group->id, $year->id, 'active');

        $this->assertSame(1, DB::connection('pgsql')->table('student_subject_enrollments')
            ->where('student_enrollment_id', $enrollment->id)->where('status', 'active')->count());
    }
}
