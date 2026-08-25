<?php

namespace Tests\Feature\StudentSubjectEnrollment;

use App\Domain\AcademicStructure\Infrastructure\SubjectOffering;
use App\Domain\Students\Application\Exceptions\ActiveSubjectEnrollmentConflictException;
use App\Domain\Students\Application\Exceptions\CrossSchoolSubjectEnrollmentException;
use App\Domain\Students\Application\Exceptions\IncompatibleSubjectOfferingException;
use App\Domain\Students\Application\Exceptions\InvalidSubjectEnrollmentTransitionException;
use App\Domain\Students\Application\Exceptions\RequiredSubjectOfferingEnrollmentException;
use App\Domain\Students\Application\StudentSubjectEnrollmentService;
use App\Domain\Students\Infrastructure\Student;
use App\Models\School;
use App\Support\Tenancy\TenantContext;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 1C.1: the sanctioned StudentSubjectEnrollmentService write path
 * -- distinct from StudentSubjectEnrollmentIntegrityTest.php (raw-SQL
 * RLS/composite-FK proof) and SubjectOfferingRosterReadServiceTest.php
 * (the read-side roster contract). Covers brief sections 35-39.
 */
class StudentSubjectEnrollmentServiceTest extends TestCase
{
    use CreatesTenancyFixtures;

    private function service(): StudentSubjectEnrollmentService
    {
        return app(StudentSubjectEnrollmentService::class);
    }

    /**
     * @return array{school: School, student: Student, offering: SubjectOffering}
     */
    private function compatibleContext(bool $required = false): array
    {
        $school = $this->createSchool();
        $campus = $this->createCampus($school);
        $year = $this->createAcademicYear($school);
        $grade = $this->createGradeLevel($school);
        $section = $this->createSection($year, $campus, $grade);
        $subject = $this->createSubject($school);
        $offering = $this->createSubjectOffering($year, $campus, $grade, $subject, ['is_required' => $required]);
        $student = $this->createStudent($school, ['student_number' => 'S-1001']);
        $this->createStudentEnrollment($student, $section);

        return compact('school', 'student', 'offering');
    }

    // --- A. Valid enrollment (brief section 35) --------------------------

    #[Test]
    public function a_compatible_student_can_be_enrolled_into_an_elective_offering(): void
    {
        ['student' => $student, 'offering' => $offering] = $this->compatibleContext();

        $enrollment = $this->service()->enroll($student, $offering, '2026-06-01');

        $this->assertSame('active', $enrollment->status);
        $this->assertSame($offering->id, $enrollment->subject_offering_id);
        $this->assertSame($offering->academic_year_id, $enrollment->academic_year_id);
    }

    #[Test]
    public function enrolling_into_a_required_offering_is_rejected(): void
    {
        ['student' => $student, 'offering' => $offering] = $this->compatibleContext(required: true);

        $this->expectException(RequiredSubjectOfferingEnrollmentException::class);

        $this->service()->enroll($student, $offering, '2026-06-01');
    }

    // --- B. Invalid academic match (brief section 36) ---------------------

    #[Test]
    public function a_student_with_no_compatible_enrollment_is_rejected(): void
    {
        $school = $this->createSchool();
        $campus = $this->createCampus($school);
        $year = $this->createAcademicYear($school);
        $grade = $this->createGradeLevel($school);
        $subject = $this->createSubject($school);
        $offering = $this->createSubjectOffering($year, $campus, $grade, $subject, ['is_required' => false]);
        $student = $this->createStudent($school, ['student_number' => 'S-2001']); // no StudentEnrollment at all

        $this->expectException(IncompatibleSubjectOfferingException::class);

        $this->service()->enroll($student, $offering, '2026-06-01');
    }

    #[Test]
    public function a_student_enrolled_in_a_different_grade_level_is_rejected(): void
    {
        $school = $this->createSchool();
        $campus = $this->createCampus($school);
        $year = $this->createAcademicYear($school);
        $gradeStudent = $this->createGradeLevel($school, ['code' => 'G7']);
        $gradeOffering = $this->createGradeLevel($school, ['code' => 'G8']);
        $section = $this->createSection($year, $campus, $gradeStudent);
        $subject = $this->createSubject($school);
        $offering = $this->createSubjectOffering($year, $campus, $gradeOffering, $subject, ['is_required' => false]);
        $student = $this->createStudent($school, ['student_number' => 'S-2002']);
        $this->createStudentEnrollment($student, $section);

        $this->expectException(IncompatibleSubjectOfferingException::class);

        $this->service()->enroll($student, $offering, '2026-06-01');
    }

    #[Test]
    public function a_student_enrolled_in_a_different_academic_year_is_rejected(): void
    {
        $school = $this->createSchool();
        $campus = $this->createCampus($school);
        $studentYear = $this->createAcademicYear($school, ['name' => '2025-26', 'code' => 'AY2526DIFF', 'status' => 'closed']);
        $offeringYear = $this->createAcademicYear($school, ['name' => '2026-27', 'code' => 'AY2627DIFF']);
        $grade = $this->createGradeLevel($school);
        $section = $this->createSection($studentYear, $campus, $grade);
        $subject = $this->createSubject($school);
        $offering = $this->createSubjectOffering($offeringYear, $campus, $grade, $subject, ['is_required' => false]);
        $student = $this->createStudent($school, ['student_number' => 'S-2003']);
        $this->createStudentEnrollment($student, $section);

        $this->expectException(IncompatibleSubjectOfferingException::class);

        $this->service()->enroll($student, $offering, '2026-06-01');
    }

    #[Test]
    public function a_student_enrolled_at_a_different_campus_is_rejected(): void
    {
        $school = $this->createSchool();
        $studentCampus = $this->createCampus($school, ['code' => 'MAIN']);
        $offeringCampus = $this->createCampus($school, ['code' => 'NORTH']);
        $year = $this->createAcademicYear($school);
        $grade = $this->createGradeLevel($school);
        $section = $this->createSection($year, $studentCampus, $grade);
        $subject = $this->createSubject($school);
        $offering = $this->createSubjectOffering($year, $offeringCampus, $grade, $subject, ['is_required' => false]);
        $student = $this->createStudent($school, ['student_number' => 'S-2004']);
        $this->createStudentEnrollment($student, $section);

        $this->expectException(IncompatibleSubjectOfferingException::class);

        $this->service()->enroll($student, $offering, '2026-06-01');
    }

    #[Test]
    public function a_student_and_offering_from_different_schools_is_rejected(): void
    {
        $schoolA = $this->createSchool();
        $studentA = $this->createStudent($schoolA, ['student_number' => 'S-3001']);
        ['offering' => $offeringB] = $this->compatibleContext();

        $this->expectException(CrossSchoolSubjectEnrollmentException::class);

        $this->service()->enroll($studentA, $offeringB, '2026-06-01');
    }

    // --- C. Duplicate active membership (brief section 37) ----------------

    #[Test]
    public function a_duplicate_active_enrollment_in_the_same_offering_is_rejected(): void
    {
        ['student' => $student, 'offering' => $offering] = $this->compatibleContext();
        $this->service()->enroll($student, $offering, '2026-06-01');

        $this->expectException(ActiveSubjectEnrollmentConflictException::class);

        $this->service()->enroll($student, $offering, '2026-06-02');
    }

    // --- D. Withdraw / cancel preserve history (brief section 38) ---------

    #[Test]
    public function withdrawing_preserves_the_historical_row_and_updates_status(): void
    {
        ['student' => $student, 'offering' => $offering] = $this->compatibleContext();
        $enrollment = $this->service()->enroll($student, $offering, '2026-06-01');

        $withdrawn = $this->service()->withdraw($enrollment, '2026-08-01');

        $this->assertSame('withdrawn', $withdrawn->status);
        $this->assertSame('2026-08-01', $withdrawn->ends_on->toDateString());
        $this->assertSame($enrollment->id, $withdrawn->id, 'the same historical row is updated, never deleted');
    }

    #[Test]
    public function cancelling_preserves_the_historical_row(): void
    {
        ['student' => $student, 'offering' => $offering] = $this->compatibleContext();
        $enrollment = $this->service()->enroll($student, $offering, '2026-06-01');

        $cancelled = $this->service()->cancel($enrollment, '2026-06-05');

        $this->assertSame('cancelled', $cancelled->status);
    }

    #[Test]
    public function a_terminal_status_cannot_transition_again(): void
    {
        ['student' => $student, 'offering' => $offering] = $this->compatibleContext();
        $enrollment = $this->service()->enroll($student, $offering, '2026-06-01');
        $this->service()->withdraw($enrollment, '2026-08-01');

        $this->expectException(InvalidSubjectEnrollmentTransitionException::class);

        $this->service()->cancel($enrollment, '2026-08-05');
    }

    // --- E. Elective switch (brief section 39) -----------------------------

    #[Test]
    public function switching_electives_preserves_the_source_and_activates_the_target(): void
    {
        $school = $this->createSchool();
        $campus = $this->createCampus($school);
        $year = $this->createAcademicYear($school);
        $grade = $this->createGradeLevel($school);
        $section = $this->createSection($year, $campus, $grade);
        $french = $this->createSubjectOffering($year, $campus, $grade, $this->createSubject($school, ['code' => 'FR']), ['is_required' => false]);
        $spanish = $this->createSubjectOffering($year, $campus, $grade, $this->createSubject($school, ['code' => 'ES']), ['is_required' => false]);
        $student = $this->createStudent($school, ['student_number' => 'S-4001']);
        $this->createStudentEnrollment($student, $section);

        $source = $this->service()->enroll($student, $french, '2026-06-01');

        $target = $this->service()->transfer($source, $spanish, '2026-09-01');

        $refreshedSource = app(TenantContext::class)->withSchool($school, fn () => $source->fresh());
        $this->assertSame('transferred', $refreshedSource->status);
        $this->assertSame('2026-08-31', $refreshedSource->ends_on->toDateString());
        $this->assertSame('active', $target->status);
        $this->assertSame($spanish->id, $target->subject_offering_id);
        $this->assertNotSame($source->id, $target->id, 'no ambiguous duplicate current assignment -- two distinct rows');
    }
}
