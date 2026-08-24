<?php

namespace Tests\Feature\StudentEnrollment;

use App\Domain\AcademicStructure\Infrastructure\Section;
use App\Domain\Students\Application\Exceptions\CrossAcademicYearTransferException;
use App\Domain\Students\Application\Exceptions\CrossSchoolEnrollmentException;
use App\Domain\Students\Application\Exceptions\DuplicateEnrollmentRollNumberException;
use App\Domain\Students\Application\Exceptions\IntraYearGradeChangeException;
use App\Domain\Students\Application\Exceptions\InvalidEnrollmentTransitionException;
use App\Domain\Students\Application\StudentEnrollmentService;
use App\Domain\Students\Infrastructure\Student;
use App\Domain\Students\Infrastructure\StudentEnrollment;
use App\Models\School;
use App\Models\SchoolAuditEvent;
use App\Support\Tenancy\TenantContext;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 1B.3: StudentEnrollmentService's terminal lifecycle transitions
 * (complete/withdraw/cancel) and the atomic same-AcademicYear transfer.
 * Distinct from StudentEnrollmentServiceTest.php (Phase 1B.2 creation
 * only) -- these tests never touch academic_year_id/campus_id/
 * grade_level_id/section_id on an EXISTING row via anything other than
 * the sanctioned service.
 */
class StudentEnrollmentLifecycleTest extends TestCase
{
    use CreatesTenancyFixtures;

    private function service(): StudentEnrollmentService
    {
        return app(StudentEnrollmentService::class);
    }

    /**
     * @return array{school: School, student: Student, section: Section}
     */
    private function buildActiveEnrollmentContext(): array
    {
        $school = $this->createSchool();
        $campus = $this->createCampus($school);
        $year = $this->createAcademicYear($school);
        $grade = $this->createGradeLevel($school);
        $section = $this->createSection($year, $campus, $grade);
        $student = $this->createStudent($school, ['student_number' => 'S-1001']);
        $this->service()->enroll($student, $section, '12', '2026-06-01');

        return compact('school', 'student', 'section');
    }

    private function auditCount(School $school, string $eventType): int
    {
        return app(TenantContext::class)->withSchool(
            $school,
            fn () => SchoolAuditEvent::query()->where('event_type', $eventType)->count(),
        );
    }

    private function freshEnrollment(School $school, string $id): StudentEnrollment
    {
        return app(TenantContext::class)->withSchool($school, fn () => StudentEnrollment::query()->findOrFail($id));
    }

    // ==================================================================
    // Completion
    // ==================================================================

    #[Test]
    public function active_to_completed_succeeds_and_preserves_placement_and_student(): void
    {
        ['school' => $school, 'student' => $student, 'section' => $section] = $this->buildActiveEnrollmentContext();
        $enrollment = $this->freshEnrollment($school, $this->findEnrollmentId($school, $student));

        $completed = $this->service()->complete($enrollment, '2027-04-30');

        $this->assertSame('completed', $completed->status);
        $this->assertSame('2027-04-30', $completed->ends_on->toDateString());
        $this->assertSame($section->id, $completed->section_id);
        $this->assertSame($section->academic_year_id, $completed->academic_year_id);
        $this->assertSame($section->campus_id, $completed->campus_id);
        $this->assertSame($section->grade_level_id, $completed->grade_level_id);

        $fresh = app(TenantContext::class)->withSchool($school, fn () => $student->fresh());
        $this->assertSame('S-1001', $fresh->student_number);
        $this->assertSame(1, $this->auditCount($school, 'student_enrollment.completed'));
    }

    #[Test]
    public function a_completed_enrollment_cannot_be_completed_again(): void
    {
        ['school' => $school, 'student' => $student] = $this->buildActiveEnrollmentContext();
        $enrollment = $this->freshEnrollment($school, $this->findEnrollmentId($school, $student));
        $completed = $this->service()->complete($enrollment, '2027-04-30');

        $this->expectException(InvalidEnrollmentTransitionException::class);
        $this->service()->complete($completed, '2027-05-01');
    }

    #[Test]
    public function a_completed_enrollment_cannot_be_withdrawn(): void
    {
        ['school' => $school, 'student' => $student] = $this->buildActiveEnrollmentContext();
        $enrollment = $this->freshEnrollment($school, $this->findEnrollmentId($school, $student));
        $completed = $this->service()->complete($enrollment, '2027-04-30');

        $this->expectException(InvalidEnrollmentTransitionException::class);
        $this->service()->withdraw($completed, '2027-05-01');
    }

    // ==================================================================
    // Withdrawal
    // ==================================================================

    #[Test]
    public function active_to_withdrawn_succeeds_and_preserves_placement_and_student(): void
    {
        ['school' => $school, 'student' => $student, 'section' => $section] = $this->buildActiveEnrollmentContext();
        $enrollment = $this->freshEnrollment($school, $this->findEnrollmentId($school, $student));

        $withdrawn = $this->service()->withdraw($enrollment, '2026-09-15');

        $this->assertSame('withdrawn', $withdrawn->status);
        $this->assertSame('2026-09-15', $withdrawn->ends_on->toDateString());
        $this->assertSame($section->id, $withdrawn->section_id);

        $fresh = app(TenantContext::class)->withSchool($school, fn () => $student->fresh());
        $this->assertSame('S-1001', $fresh->student_number);
        $this->assertSame('active', $fresh->status, 'Student identity status must never be touched by Enrollment withdrawal.');
        $this->assertSame(1, $this->auditCount($school, 'student_enrollment.withdrawn'));

        // The row is retained, not deleted.
        $stillThere = app(TenantContext::class)->withSchool($school, fn () => StudentEnrollment::query()->find($withdrawn->id));
        $this->assertNotNull($stillThere);
    }

    #[Test]
    public function a_withdrawn_enrollment_cannot_transition_again(): void
    {
        ['school' => $school, 'student' => $student] = $this->buildActiveEnrollmentContext();
        $enrollment = $this->freshEnrollment($school, $this->findEnrollmentId($school, $student));
        $withdrawn = $this->service()->withdraw($enrollment, '2026-09-15');

        $this->expectException(InvalidEnrollmentTransitionException::class);
        $this->service()->complete($withdrawn, '2026-10-01');
    }

    // ==================================================================
    // Cancellation
    // ==================================================================

    #[Test]
    public function active_to_cancelled_succeeds_and_retains_the_row(): void
    {
        ['school' => $school, 'student' => $student, 'section' => $section] = $this->buildActiveEnrollmentContext();
        $enrollment = $this->freshEnrollment($school, $this->findEnrollmentId($school, $student));

        $cancelled = $this->service()->cancel($enrollment, '2026-06-05');

        $this->assertSame('cancelled', $cancelled->status);
        $this->assertSame($section->id, $cancelled->section_id, 'Placement must be retained on a cancelled row.');

        $fresh = app(TenantContext::class)->withSchool($school, fn () => $student->fresh());
        $this->assertSame('S-1001', $fresh->student_number);
        $this->assertSame(1, $this->auditCount($school, 'student_enrollment.cancelled'));

        $stillThere = app(TenantContext::class)->withSchool($school, fn () => StudentEnrollment::query()->find($cancelled->id));
        $this->assertNotNull($stillThere, 'A cancelled Enrollment row must never be deleted.');
    }

    #[Test]
    public function a_cancelled_enrollment_cannot_transition_again(): void
    {
        ['school' => $school, 'student' => $student] = $this->buildActiveEnrollmentContext();
        $enrollment = $this->freshEnrollment($school, $this->findEnrollmentId($school, $student));
        $cancelled = $this->service()->cancel($enrollment, '2026-06-05');

        $this->expectException(InvalidEnrollmentTransitionException::class);
        $this->service()->complete($cancelled, '2026-07-01');
    }

    // ==================================================================
    // Transfer -- successful same-year placement move
    // ==================================================================

    #[Test]
    public function a_successful_same_year_transfer_closes_the_old_row_and_creates_a_new_active_row(): void
    {
        $school = $this->createSchool();
        $campus = $this->createCampus($school);
        $year = $this->createAcademicYear($school);
        $grade = $this->createGradeLevel($school);
        $sectionA = $this->createSection($year, $campus, $grade, ['name' => 'A', 'code' => 'A']);
        $sectionB = $this->createSection($year, $campus, $grade, ['name' => 'B', 'code' => 'B']);
        $student = $this->createStudent($school, ['student_number' => 'S-1001']);
        $original = $this->service()->enroll($student, $sectionA, '12', '2026-06-01');

        $new = $this->service()->transferPlacement($original, $sectionB, '07', '2026-09-15');

        $old = $this->freshEnrollment($school, $original->id);
        $this->assertSame('transferred', $old->status);
        $this->assertSame('2026-09-14', $old->ends_on->toDateString());
        $this->assertSame($sectionA->id, $old->section_id, "The old row's original placement must remain unchanged.");
        $this->assertSame($campus->id, $old->campus_id);
        $this->assertSame($grade->id, $old->grade_level_id);
        $this->assertSame($year->id, $old->academic_year_id);

        $this->assertSame('active', $new->status);
        $this->assertSame($sectionB->id, $new->section_id);
        $this->assertSame($campus->id, $new->campus_id);
        $this->assertSame($grade->id, $new->grade_level_id);
        $this->assertSame($year->id, $new->academic_year_id);
        $this->assertSame('07', $new->roll_number);
        $this->assertSame('2026-09-15', $new->starts_on->toDateString());

        $fresh = app(TenantContext::class)->withSchool($school, fn () => $student->fresh());
        $this->assertSame('S-1001', $fresh->student_number);

        $history = app(TenantContext::class)->withSchool($school, fn () => $student->enrollments()->orderBy('starts_on')->get());
        $this->assertCount(2, $history, 'Both the old (transferred) and new (active) Enrollment rows must remain queryable.');

        $this->assertSame(1, $this->auditCount($school, 'student_enrollment.transferred'));
        $this->assertSame(2, $this->auditCount($school, 'student_enrollment.created'), 'One for the original enroll(), one for the transfer target.');
    }

    // ==================================================================
    // Transfer -- rollback on target conflict (merge-blocking)
    // ==================================================================

    #[Test]
    public function a_transfer_rolls_back_entirely_when_the_target_roll_number_conflicts(): void
    {
        $school = $this->createSchool();
        $campus = $this->createCampus($school);
        $year = $this->createAcademicYear($school);
        $grade = $this->createGradeLevel($school);
        $sectionA = $this->createSection($year, $campus, $grade, ['name' => 'A', 'code' => 'A']);
        $sectionB = $this->createSection($year, $campus, $grade, ['name' => 'B', 'code' => 'B']);
        $student = $this->createStudent($school, ['student_number' => 'S-1001']);
        $otherStudent = $this->createStudent($school, ['student_number' => 'S-1002']);

        $original = $this->service()->enroll($student, $sectionA, '12', '2026-06-01');
        // Someone already occupies roll number '07' in the target Section.
        $this->service()->enroll($otherStudent, $sectionB, '07', '2026-06-01');

        $this->expectException(DuplicateEnrollmentRollNumberException::class);
        try {
            $this->service()->transferPlacement($original, $sectionB, '07', '2026-09-15');
        } finally {
            $old = $this->freshEnrollment($school, $original->id);
            $this->assertSame('active', $old->status, 'The source Enrollment must remain ACTIVE after a rolled-back transfer.');
            $this->assertNull($old->ends_on, "The source Enrollment's ends_on must be unchanged (still null) after rollback.");

            $newRowCount = app(TenantContext::class)->withSchool(
                $school,
                fn () => StudentEnrollment::query()->where('section_id', $sectionB->id)->where('roll_number', '07')->count(),
            );
            $this->assertSame(1, $newRowCount, 'No new Enrollment row for the target Section/roll number beyond the pre-existing one.');

            $this->assertSame(0, $this->auditCount($school, 'student_enrollment.transferred'), 'No false transfer audit record may survive a rolled-back transfer.');

            // TenantContext exits cleanly -- the connection must remain
            // fully usable for a subsequent unrelated operation.
            $another = $this->service()->enroll($this->createStudent($school, ['student_number' => 'S-1003']), $sectionA, '13', '2026-06-01');
            $this->assertSame('active', $another->status);
        }
    }

    // ==================================================================
    // Transfer -- cross-year, cross-school, cross-grade rejections
    // ==================================================================

    #[Test]
    public function a_transfer_to_a_different_academic_year_is_rejected_and_changes_nothing(): void
    {
        $school = $this->createSchool();
        $campus = $this->createCampus($school);
        $yearA = $this->createAcademicYear($school, ['code' => '2026-27', 'starts_on' => '2026-06-01', 'ends_on' => '2027-04-30']);
        $yearB = $this->createAcademicYear($school, ['code' => '2027-28', 'starts_on' => '2027-06-01', 'ends_on' => '2028-04-30']);
        $grade = $this->createGradeLevel($school);
        $sectionA = $this->createSection($yearA, $campus, $grade, ['name' => 'A', 'code' => 'A']);
        $sectionB = $this->createSection($yearB, $campus, $grade, ['name' => 'B', 'code' => 'B']);
        $student = $this->createStudent($school, ['student_number' => 'S-1001']);
        $original = $this->service()->enroll($student, $sectionA, '12', '2026-06-01');

        $this->expectException(CrossAcademicYearTransferException::class);
        try {
            $this->service()->transferPlacement($original, $sectionB, '07', '2027-06-01');
        } finally {
            $old = $this->freshEnrollment($school, $original->id);
            $this->assertSame('active', $old->status);
            $this->assertNull($old->ends_on);
            $this->assertSame(0, $this->auditCount($school, 'student_enrollment.transferred'));
        }
    }

    #[Test]
    public function a_transfer_to_a_different_schools_section_is_rejected_and_changes_nothing(): void
    {
        $schoolA = $this->createSchool();
        $campusA = $this->createCampus($schoolA);
        $yearA = $this->createAcademicYear($schoolA);
        $gradeA = $this->createGradeLevel($schoolA);
        $sectionA = $this->createSection($yearA, $campusA, $gradeA);
        $studentA = $this->createStudent($schoolA, ['student_number' => 'S-1001']);
        $original = $this->service()->enroll($studentA, $sectionA, '12', '2026-06-01');

        $schoolB = $this->createSchool();
        $campusB = $this->createCampus($schoolB);
        $yearB = $this->createAcademicYear($schoolB);
        $gradeB = $this->createGradeLevel($schoolB);
        $sectionB = $this->createSection($yearB, $campusB, $gradeB);

        $this->expectException(CrossSchoolEnrollmentException::class);
        try {
            $this->service()->transferPlacement($original, $sectionB, '07', '2026-09-15');
        } finally {
            $old = $this->freshEnrollment($schoolA, $original->id);
            $this->assertSame('active', $old->status);
            $this->assertNull($old->ends_on);

            $targetRowCount = app(TenantContext::class)->withSchool(
                $schoolB,
                fn () => StudentEnrollment::query()->where('section_id', $sectionB->id)->count(),
            );
            $this->assertSame(0, $targetRowCount, 'No Enrollment row may be created in School B.');
        }
    }

    #[Test]
    public function a_transfer_that_would_change_grade_level_is_rejected_and_changes_nothing(): void
    {
        $school = $this->createSchool();
        $campus = $this->createCampus($school);
        $year = $this->createAcademicYear($school);
        $gradeFive = $this->createGradeLevel($school, ['name' => 'Grade 5', 'code' => 'G5', 'sequence' => 5]);
        $gradeSix = $this->createGradeLevel($school, ['name' => 'Grade 6', 'code' => 'G6', 'sequence' => 6]);
        $sectionFive = $this->createSection($year, $campus, $gradeFive, ['name' => '5A', 'code' => '5A']);
        $sectionSix = $this->createSection($year, $campus, $gradeSix, ['name' => '6A', 'code' => '6A']);
        $student = $this->createStudent($school, ['student_number' => 'S-1001']);
        $original = $this->service()->enroll($student, $sectionFive, '12', '2026-06-01');

        $this->expectException(IntraYearGradeChangeException::class);
        try {
            $this->service()->transferPlacement($original, $sectionSix, '07', '2026-09-15');
        } finally {
            $old = $this->freshEnrollment($school, $original->id);
            $this->assertSame('active', $old->status);
            $this->assertSame($gradeFive->id, $old->grade_level_id);
        }
    }

    // ==================================================================
    // Stale-model / lock protection
    // ==================================================================

    #[Test]
    public function the_service_reloads_the_authoritative_row_rather_than_trusting_a_stale_in_memory_status(): void
    {
        ['school' => $school, 'student' => $student] = $this->buildActiveEnrollmentContext();
        $enrollmentId = $this->findEnrollmentId($school, $student);
        $staleEnrollment = $this->freshEnrollment($school, $enrollmentId);

        // Simulate a concurrent transition having already completed the
        // row via a SEPARATE call to the sanctioned service, after
        // $staleEnrollment was read -- $staleEnrollment's in-memory
        // ->status still reads 'active'.
        $this->service()->complete($this->freshEnrollment($school, $enrollmentId), '2027-04-30');
        $this->assertSame('active', $staleEnrollment->status, 'Sanity check: the in-memory model must still be stale.');

        // If the service trusted $staleEnrollment->status instead of
        // reloading+locking the authoritative row, this would succeed
        // and silently double-process the transition.
        $this->expectException(InvalidEnrollmentTransitionException::class);
        $this->service()->withdraw($staleEnrollment, '2027-05-01');
    }

    private function findEnrollmentId(School $school, Student $student): string
    {
        return app(TenantContext::class)->withSchool($school, fn () => $student->enrollments()->firstOrFail())->id;
    }
}
