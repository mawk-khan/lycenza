<?php

namespace Tests\Feature\StudentEnrollment;

use App\Domain\AcademicStructure\Infrastructure\Section;
use App\Domain\Students\Infrastructure\Student;
use App\Domain\Students\Infrastructure\StudentEnrollment;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 1B.1: core schema and domain-model correctness for
 * StudentEnrollment -- deliberately model-layer only (no controllers/
 * service exist yet; App\Domain\Students\Application\StudentEnrollmentService
 * lands in Phase 1B.4). Tenant/RLS/composite-FK integrity is proven
 * separately in tests/Feature/Postgres/StudentEnrollmentIntegrityTest.php.
 */
class StudentEnrollmentTest extends TestCase
{
    use CreatesTenancyFixtures;

    /**
     * Unlike CreatesTenancyFixtures::createStudentEnrollment(), this
     * wraps the write in its own DB::transaction() (a nested SAVEPOINT)
     * -- required for any call site that EXPECTS the write to fail:
     * without it, the failed INSERT leaves the connection in Postgres's
     * "current transaction is aborted" state, and
     * TenantContext::withSchool()'s own finally-block RESET statement
     * (unconditional, not itself wrapped) then throws a second,
     * unrelated QueryException before the real one can be asserted on
     * cleanly. Every real write path in this codebase (e.g.
     * StudentService::create()) already wraps its mutation in
     * DB::transaction() for exactly this reason -- this helper mirrors
     * that production shape rather than working around the symptom.
     */
    private function attemptStudentEnrollment(Student $student, Section $section, array $attributes = []): void
    {
        app(TenantContext::class)->withSchool($student->school, function () use ($student, $section, $attributes): void {
            DB::transaction(function () use ($student, $section, $attributes): void {
                StudentEnrollment::factory()->create(array_merge([
                    'school_id' => $student->school_id,
                    'student_id' => $student->id,
                    'academic_year_id' => $section->academic_year_id,
                    'campus_id' => $section->campus_id,
                    'grade_level_id' => $section->grade_level_id,
                    'section_id' => $section->id,
                ], $attributes));
            });
        });
    }

    #[Test]
    public function a_same_school_student_can_be_enrolled_with_placement_values_persisted(): void
    {
        $school = $this->createSchool();
        $campus = $this->createCampus($school);
        $year = $this->createAcademicYear($school);
        $grade = $this->createGradeLevel($school);
        $section = $this->createSection($year, $campus, $grade);
        $student = $this->createStudent($school, ['student_number' => 'S-1001']);

        $enrollment = $this->createStudentEnrollment($student, $section, ['roll_number' => '12']);

        $this->assertSame($school->id, $enrollment->school_id);
        $this->assertSame($student->id, $enrollment->student_id);
        $this->assertSame($year->id, $enrollment->academic_year_id);
        $this->assertSame($campus->id, $enrollment->campus_id);
        $this->assertSame($grade->id, $enrollment->grade_level_id);
        $this->assertSame($section->id, $enrollment->section_id);
        $this->assertSame('12', $enrollment->roll_number);
        $this->assertSame('active', $enrollment->status);
        $this->assertTrue($enrollment->isActive());
    }

    #[Test]
    public function a_student_retains_prior_enrollment_history_when_a_new_enrollment_is_created(): void
    {
        $school = $this->createSchool();
        $campus = $this->createCampus($school);
        $gradeFive = $this->createGradeLevel($school, ['name' => 'Grade 5', 'code' => 'G5', 'sequence' => 5]);
        $gradeSix = $this->createGradeLevel($school, ['name' => 'Grade 6', 'code' => 'G6', 'sequence' => 6]);
        $yearOne = $this->createAcademicYear($school, ['code' => '2026-27', 'starts_on' => '2026-06-01', 'ends_on' => '2027-04-30']);
        $yearTwo = $this->createAcademicYear($school, ['code' => '2027-28', 'starts_on' => '2027-06-01', 'ends_on' => '2028-04-30']);
        $sectionFiveA = $this->createSection($yearOne, $campus, $gradeFive, ['name' => '5A', 'code' => '5A']);
        $sectionSixB = $this->createSection($yearTwo, $campus, $gradeSix, ['name' => '6B', 'code' => '6B']);
        $student = $this->createStudent($school, ['student_number' => 'S-1001']);

        $lastYear = $this->createStudentEnrollment($student, $sectionFiveA, [
            'roll_number' => '12', 'status' => 'completed', 'starts_on' => '2026-06-01', 'ends_on' => '2027-04-30',
        ]);
        $thisYear = $this->createStudentEnrollment($student, $sectionSixB, [
            'roll_number' => '07', 'status' => 'active', 'starts_on' => '2027-06-01',
        ]);

        $history = app(TenantContext::class)->withSchool($school, fn () => $student->enrollments()->orderBy('starts_on')->get());

        $this->assertCount(2, $history, 'Both historical and current Enrollment rows must remain queryable.');
        $this->assertSame($lastYear->id, $history->first()->id);
        $this->assertSame($thisYear->id, $history->last()->id);
        $this->assertSame('completed', $history->first()->status);
        $this->assertSame('active', $history->last()->status);
    }

    #[Test]
    public function creating_an_enrollment_never_changes_the_students_permanent_student_number(): void
    {
        $school = $this->createSchool();
        $campus = $this->createCampus($school);
        $year = $this->createAcademicYear($school);
        $grade = $this->createGradeLevel($school);
        $section = $this->createSection($year, $campus, $grade);
        $student = $this->createStudent($school, ['student_number' => 'S-1001']);

        $this->createStudentEnrollment($student, $section);

        $fresh = app(TenantContext::class)->withSchool($school, fn () => $student->fresh());
        $this->assertSame('S-1001', $fresh->student_number, "Enrollment placement must never alter a Student's permanent identity.");
    }

    #[Test]
    public function roll_number_is_unique_within_the_same_academic_year_and_section(): void
    {
        $school = $this->createSchool();
        $campus = $this->createCampus($school);
        $year = $this->createAcademicYear($school);
        $grade = $this->createGradeLevel($school);
        $section = $this->createSection($year, $campus, $grade);
        $studentA = $this->createStudent($school, ['student_number' => 'S-1001']);
        $studentB = $this->createStudent($school, ['student_number' => 'S-1002']);

        $this->createStudentEnrollment($studentA, $section, ['roll_number' => '01']);

        $this->expectException(QueryException::class);
        $this->attemptStudentEnrollment($studentB, $section, ['roll_number' => '01']);
    }

    #[Test]
    public function the_same_roll_number_is_allowed_again_in_a_different_section(): void
    {
        $school = $this->createSchool();
        $campus = $this->createCampus($school);
        $year = $this->createAcademicYear($school);
        $grade = $this->createGradeLevel($school);
        $sectionA = $this->createSection($year, $campus, $grade, ['name' => 'A', 'code' => 'A']);
        $sectionB = $this->createSection($year, $campus, $grade, ['name' => 'B', 'code' => 'B']);
        $studentA = $this->createStudent($school, ['student_number' => 'S-1001']);
        $studentB = $this->createStudent($school, ['student_number' => 'S-1002']);

        $this->createStudentEnrollment($studentA, $sectionA, ['roll_number' => '01']);
        $enrollmentB = $this->createStudentEnrollment($studentB, $sectionB, ['roll_number' => '01']);

        $this->assertSame('01', $enrollmentB->roll_number);
    }

    #[Test]
    public function a_student_cannot_have_two_overlapping_active_enrollments_in_the_same_academic_year(): void
    {
        $school = $this->createSchool();
        $campus = $this->createCampus($school);
        $year = $this->createAcademicYear($school);
        $grade = $this->createGradeLevel($school);
        $sectionA = $this->createSection($year, $campus, $grade, ['name' => 'A', 'code' => 'A']);
        $sectionB = $this->createSection($year, $campus, $grade, ['name' => 'B', 'code' => 'B']);
        $student = $this->createStudent($school, ['student_number' => 'S-1001']);

        $this->createStudentEnrollment($student, $sectionA, ['roll_number' => '01', 'status' => 'active']);

        $this->expectException(QueryException::class);
        $this->attemptStudentEnrollment($student, $sectionB, ['roll_number' => '02', 'status' => 'active']);
    }

    #[Test]
    public function a_second_active_enrollment_is_allowed_once_the_first_is_no_longer_active(): void
    {
        $school = $this->createSchool();
        $campus = $this->createCampus($school);
        $year = $this->createAcademicYear($school);
        $grade = $this->createGradeLevel($school);
        $sectionA = $this->createSection($year, $campus, $grade, ['name' => 'A', 'code' => 'A']);
        $sectionB = $this->createSection($year, $campus, $grade, ['name' => 'B', 'code' => 'B']);
        $student = $this->createStudent($school, ['student_number' => 'S-1001']);

        $this->createStudentEnrollment($student, $sectionA, ['roll_number' => '01', 'status' => 'withdrawn', 'ends_on' => '2026-08-01']);
        $second = $this->createStudentEnrollment($student, $sectionB, ['roll_number' => '02', 'status' => 'active']);

        $this->assertTrue($second->isActive());
    }

    #[Test]
    public function an_end_date_before_the_start_date_is_rejected_at_the_database_level(): void
    {
        $school = $this->createSchool();
        $campus = $this->createCampus($school);
        $year = $this->createAcademicYear($school);
        $grade = $this->createGradeLevel($school);
        $section = $this->createSection($year, $campus, $grade);
        $student = $this->createStudent($school, ['student_number' => 'S-1001']);

        $this->expectException(QueryException::class);
        $this->attemptStudentEnrollment($student, $section, [
            'starts_on' => '2027-01-01', 'ends_on' => '2026-01-01',
        ]);
    }

    #[Test]
    public function the_enrollment_exposes_its_academic_relationships(): void
    {
        $school = $this->createSchool();
        $campus = $this->createCampus($school);
        $year = $this->createAcademicYear($school);
        $grade = $this->createGradeLevel($school);
        $section = $this->createSection($year, $campus, $grade);
        $student = $this->createStudent($school, ['student_number' => 'S-1001']);
        $enrollment = $this->createStudentEnrollment($student, $section);

        app(TenantContext::class)->withSchool($school, function () use ($enrollment, $student, $year, $campus, $grade, $section): void {
            /** @var StudentEnrollment $fresh */
            $fresh = StudentEnrollment::query()->findOrFail($enrollment->id);

            $this->assertSame($student->id, $fresh->student->id);
            $this->assertSame($year->id, $fresh->academicYear->id);
            $this->assertSame($campus->id, $fresh->campus->id);
            $this->assertSame($grade->id, $fresh->gradeLevel->id);
            $this->assertSame($section->id, $fresh->section->id);
        });
    }
}
