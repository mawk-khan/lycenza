<?php

namespace Tests\Feature\StudentEnrollment;

use App\Domain\Students\Application\Exceptions\ActiveEnrollmentConflictException;
use App\Domain\Students\Application\Exceptions\CrossSchoolEnrollmentException;
use App\Domain\Students\Application\Exceptions\DuplicateEnrollmentRollNumberException;
use App\Domain\Students\Application\Exceptions\InvalidEnrollmentRollNumberException;
use App\Domain\Students\Application\StudentEnrollmentService;
use App\Support\Tenancy\TenantContext;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 1B.2: the sanctioned StudentEnrollmentService write path --
 * distinct from StudentEnrollmentTest.php (raw model/factory-level
 * schema correctness) and StudentEnrollmentIntegrityTest.php (raw-SQL
 * RLS/composite-FK proof under school_os_app). These tests exercise
 * the real service exactly as a future controller will call it.
 */
class StudentEnrollmentServiceTest extends TestCase
{
    use CreatesTenancyFixtures;

    private function service(): StudentEnrollmentService
    {
        return app(StudentEnrollmentService::class);
    }

    // --- A. Basic enrollment ---------------------------------------------

    #[Test]
    public function a_same_school_student_can_be_enrolled_via_the_service(): void
    {
        $school = $this->createSchool();
        $campus = $this->createCampus($school);
        $year = $this->createAcademicYear($school);
        $grade = $this->createGradeLevel($school);
        $section = $this->createSection($year, $campus, $grade);
        $student = $this->createStudent($school, ['student_number' => 'S-1001']);

        $enrollment = $this->service()->enroll($student, $section, '12', '2026-06-01');

        $this->assertNotNull($enrollment->id);
        $this->assertSame('active', $enrollment->status);
        $this->assertSame('2026-06-01', $enrollment->starts_on->toDateString());
    }

    // --- B. Placement derivation -------------------------------------------

    #[Test]
    public function the_service_derives_academic_year_campus_and_grade_level_from_the_section_alone(): void
    {
        $school = $this->createSchool();
        $campus = $this->createCampus($school);
        $year = $this->createAcademicYear($school);
        $grade = $this->createGradeLevel($school);
        $section = $this->createSection($year, $campus, $grade);
        $student = $this->createStudent($school, ['student_number' => 'S-1001']);

        $enrollment = $this->service()->enroll($student, $section, '12', '2026-06-01');

        // enroll()'s signature has no parameter through which a caller
        // could independently supply a different academic_year_id/
        // campus_id/grade_level_id -- these values are structurally
        // unreachable from outside the service, not merely unused.
        $this->assertSame($year->id, $enrollment->academic_year_id);
        $this->assertSame($campus->id, $enrollment->campus_id);
        $this->assertSame($grade->id, $enrollment->grade_level_id);
        $this->assertSame($section->id, $enrollment->section_id);
    }

    // --- C. Cross-School Student/Section -----------------------------------

    #[Test]
    public function a_cross_school_student_and_section_is_rejected_by_a_clean_domain_exception(): void
    {
        $schoolA = $this->createSchool();
        $studentA = $this->createStudent($schoolA, ['student_number' => 'S-1001']);

        $schoolB = $this->createSchool();
        $campusB = $this->createCampus($schoolB);
        $yearB = $this->createAcademicYear($schoolB);
        $gradeB = $this->createGradeLevel($schoolB);
        $sectionB = $this->createSection($yearB, $campusB, $gradeB);

        $this->expectException(CrossSchoolEnrollmentException::class);
        $this->service()->enroll($studentA, $sectionB, '12', '2026-06-01');
    }

    // --- D. Student identity unchanged --------------------------------------

    #[Test]
    public function enrolling_a_student_never_changes_their_permanent_student_number(): void
    {
        $school = $this->createSchool();
        $campus = $this->createCampus($school);
        $year = $this->createAcademicYear($school);
        $grade = $this->createGradeLevel($school);
        $section = $this->createSection($year, $campus, $grade);
        $student = $this->createStudent($school, ['student_number' => 'S-1001']);

        $this->service()->enroll($student, $section, '12', '2026-06-01');

        $fresh = app(TenantContext::class)->withSchool($school, fn () => $student->fresh());
        $this->assertSame('S-1001', $fresh->student_number);
        $this->assertSame('active', $fresh->status, 'Enrollment must never change Student.status.');
    }

    // --- E. Historical preservation ------------------------------------------

    #[Test]
    public function a_second_academic_year_enrollment_preserves_the_first_years_row(): void
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

        $first = $this->service()->enroll($student, $sectionFiveA, '12', '2026-06-01');
        $second = $this->service()->enroll($student, $sectionSixB, '07', '2027-06-01');

        $history = app(TenantContext::class)->withSchool($school, fn () => $student->enrollments()->orderBy('starts_on')->get());
        $this->assertCount(2, $history, 'Both the first and second year Enrollment rows must remain queryable -- the first is never deleted/replaced.');
        $this->assertSame($first->id, $history->first()->id);
        $this->assertSame($second->id, $history->last()->id);
    }

    // --- F. Active Enrollment conflict --------------------------------------

    #[Test]
    public function a_second_active_enrollment_for_the_same_student_and_year_is_a_clean_domain_conflict(): void
    {
        $school = $this->createSchool();
        $campus = $this->createCampus($school);
        $year = $this->createAcademicYear($school);
        $grade = $this->createGradeLevel($school);
        $sectionA = $this->createSection($year, $campus, $grade, ['name' => 'A', 'code' => 'A']);
        $sectionB = $this->createSection($year, $campus, $grade, ['name' => 'B', 'code' => 'B']);
        $student = $this->createStudent($school, ['student_number' => 'S-1001']);

        $this->service()->enroll($student, $sectionA, '01', '2026-06-01');

        $this->expectException(ActiveEnrollmentConflictException::class);
        $this->service()->enroll($student, $sectionB, '02', '2026-06-01');
    }

    // --- G. Roll number conflict ---------------------------------------------

    #[Test]
    public function the_same_roll_number_in_the_same_section_and_year_is_a_clean_domain_conflict(): void
    {
        $school = $this->createSchool();
        $campus = $this->createCampus($school);
        $year = $this->createAcademicYear($school);
        $grade = $this->createGradeLevel($school);
        $section = $this->createSection($year, $campus, $grade);
        $studentA = $this->createStudent($school, ['student_number' => 'S-1001']);
        $studentB = $this->createStudent($school, ['student_number' => 'S-1002']);

        $this->service()->enroll($studentA, $section, '01', '2026-06-01');

        $this->expectException(DuplicateEnrollmentRollNumberException::class);
        $this->service()->enroll($studentB, $section, '01', '2026-06-01');
    }

    // --- H. Roll number preservation ------------------------------------------

    #[Test]
    public function a_roll_number_with_leading_zeroes_is_preserved_exactly(): void
    {
        $school = $this->createSchool();
        $campus = $this->createCampus($school);
        $year = $this->createAcademicYear($school);
        $grade = $this->createGradeLevel($school);
        $section = $this->createSection($year, $campus, $grade);
        $student = $this->createStudent($school, ['student_number' => 'S-1001']);

        $enrollment = $this->service()->enroll($student, $section, '007', '2026-06-01');

        $this->assertSame('007', $enrollment->roll_number);
    }

    #[Test]
    public function surrounding_whitespace_is_trimmed_but_the_number_itself_is_untouched(): void
    {
        $school = $this->createSchool();
        $campus = $this->createCampus($school);
        $year = $this->createAcademicYear($school);
        $grade = $this->createGradeLevel($school);
        $section = $this->createSection($year, $campus, $grade);
        $student = $this->createStudent($school, ['student_number' => 'S-1001']);

        $enrollment = $this->service()->enroll($student, $section, '  007  ', '2026-06-01');

        $this->assertSame('007', $enrollment->roll_number);
    }

    #[Test]
    public function a_blank_roll_number_is_rejected_before_touching_the_database(): void
    {
        $school = $this->createSchool();
        $campus = $this->createCampus($school);
        $year = $this->createAcademicYear($school);
        $grade = $this->createGradeLevel($school);
        $section = $this->createSection($year, $campus, $grade);
        $student = $this->createStudent($school, ['student_number' => 'S-1001']);

        $this->expectException(InvalidEnrollmentRollNumberException::class);
        $this->service()->enroll($student, $section, '   ', '2026-06-01');
    }

    // --- I. Roll number independent scope -------------------------------------

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

        $this->service()->enroll($studentA, $sectionA, '01', '2026-06-01');
        $enrollmentB = $this->service()->enroll($studentB, $sectionB, '01', '2026-06-01');

        $this->assertSame('01', $enrollmentB->roll_number);
    }

    // --- J. Transaction cleanup after an expected conflict --------------------

    #[Test]
    public function tenant_context_exits_cleanly_after_an_active_enrollment_conflict_and_the_next_operation_succeeds(): void
    {
        $school = $this->createSchool();
        $campus = $this->createCampus($school);
        $year = $this->createAcademicYear($school);
        $grade = $this->createGradeLevel($school);
        $sectionA = $this->createSection($year, $campus, $grade, ['name' => 'A', 'code' => 'A']);
        $sectionB = $this->createSection($year, $campus, $grade, ['name' => 'B', 'code' => 'B']);
        $student = $this->createStudent($school, ['student_number' => 'S-1001']);

        $this->service()->enroll($student, $sectionA, '01', '2026-06-01');

        try {
            $this->service()->enroll($student, $sectionB, '02', '2026-06-01');
            $this->fail('Expected ActiveEnrollmentConflictException was not thrown.');
        } catch (ActiveEnrollmentConflictException) {
            // expected -- the service's DB::transaction() must have
            // already rolled back to a clean savepoint before
            // TenantContext::withSchool()'s finally-block RESET ran.
        }

        // The connection must be fully usable again -- an unrelated
        // real write, in a fresh TenantContext, for a different
        // Student, must succeed without any residual "aborted
        // transaction"/stale-RESET failure (Phase 1B.1's P3 finding).
        $anotherStudent = $this->createStudent($school, ['student_number' => 'S-1002']);
        $anotherEnrollment = $this->service()->enroll($anotherStudent, $sectionA, '03', '2026-06-01');

        $this->assertNotNull($anotherEnrollment->id);
        $this->assertSame('active', $anotherEnrollment->status);
    }

    #[Test]
    public function tenant_context_exits_cleanly_after_a_roll_number_conflict_and_the_next_operation_succeeds(): void
    {
        $school = $this->createSchool();
        $campus = $this->createCampus($school);
        $year = $this->createAcademicYear($school);
        $grade = $this->createGradeLevel($school);
        $section = $this->createSection($year, $campus, $grade);
        $studentA = $this->createStudent($school, ['student_number' => 'S-1001']);
        $studentB = $this->createStudent($school, ['student_number' => 'S-1002']);

        $this->service()->enroll($studentA, $section, '01', '2026-06-01');

        try {
            $this->service()->enroll($studentB, $section, '01', '2026-06-01');
            $this->fail('Expected DuplicateEnrollmentRollNumberException was not thrown.');
        } catch (DuplicateEnrollmentRollNumberException) {
            // expected
        }

        $enrollmentB = $this->service()->enroll($studentB, $section, '02', '2026-06-01');
        $this->assertSame('02', $enrollmentB->roll_number);
    }
}
