<?php

namespace Tests\Feature\StudentSubjectEnrollment;

use App\Domain\Students\Application\StudentSubjectEnrollmentService;
use App\Domain\Students\Application\SubjectOfferingRosterReadService;
use App\Domain\Students\Infrastructure\StudentEnrollment;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 1C.1: the literal future Phase 5C.1 dependency contract --
 * "SubjectOffering -> current eligible Student roster" (brief section
 * 40). Covers required-offering implicit roster, elective-offering
 * explicit roster, dynamic exclusion on incompatible StudentEnrollment
 * change (brief section 41), deduplication, and query-count scale
 * (brief section 44).
 */
class SubjectOfferingRosterReadServiceTest extends TestCase
{
    use CreatesTenancyFixtures;

    private function reads(): SubjectOfferingRosterReadService
    {
        return app(SubjectOfferingRosterReadService::class);
    }

    private function writes(): StudentSubjectEnrollmentService
    {
        return app(StudentSubjectEnrollmentService::class);
    }

    // --- A. Required offering: implicit roster -----------------------------

    #[Test]
    public function a_required_offerings_roster_is_every_compatible_actively_enrolled_student(): void
    {
        $school = $this->createSchool();
        $campus = $this->createCampus($school);
        $year = $this->createAcademicYear($school);
        $grade = $this->createGradeLevel($school);
        $section = $this->createSection($year, $campus, $grade);
        $offering = $this->createSubjectOffering($year, $campus, $grade, $this->createSubject($school), ['is_required' => true]);

        $inGrade = $this->createStudent($school, ['student_number' => 'S-1']);
        $this->createStudentEnrollment($inGrade, $section);

        $otherGrade = $this->createGradeLevel($school, ['code' => 'OTHER']);
        $otherSection = $this->createSection($year, $campus, $otherGrade);
        $outsideGrade = $this->createStudent($school, ['student_number' => 'S-2']);
        $this->createStudentEnrollment($outsideGrade, $otherSection);

        $withdrawn = $this->createStudent($school, ['student_number' => 'S-3']);
        $this->createStudentEnrollment($withdrawn, $section, ['status' => 'withdrawn', 'starts_on' => '2026-01-01', 'ends_on' => '2026-07-01']);

        $roster = $this->reads()->currentRosterStudentIds($offering);

        $this->assertSame([$inGrade->id], $roster);
        $this->assertSame(1, $this->reads()->currentRosterCount($offering));
    }

    #[Test]
    public function a_required_offering_never_consults_student_subject_enrollments(): void
    {
        $school = $this->createSchool();
        $campus = $this->createCampus($school);
        $year = $this->createAcademicYear($school);
        $grade = $this->createGradeLevel($school);
        $section = $this->createSection($year, $campus, $grade);
        $offering = $this->createSubjectOffering($year, $campus, $grade, $this->createSubject($school), ['is_required' => true]);
        $student = $this->createStudent($school, ['student_number' => 'S-1']);
        $this->createStudentEnrollment($student, $section);

        $this->assertSame(0, DB::table('student_subject_enrollments')->count(), 'no explicit row should ever exist for a required offering');
        $this->assertSame([$student->id], $this->reads()->currentRosterStudentIds($offering));
    }

    // --- B. Elective offering: explicit roster ------------------------------

    #[Test]
    public function an_elective_offerings_roster_is_only_students_with_an_active_explicit_row(): void
    {
        $school = $this->createSchool();
        $campus = $this->createCampus($school);
        $year = $this->createAcademicYear($school);
        $grade = $this->createGradeLevel($school);
        $section = $this->createSection($year, $campus, $grade);
        $offering = $this->createSubjectOffering($year, $campus, $grade, $this->createSubject($school), ['is_required' => false]);

        $enrolled = $this->createStudent($school, ['student_number' => 'S-1']);
        $this->createStudentEnrollment($enrolled, $section);
        $this->writes()->enroll($enrolled, $offering, '2026-06-01');

        $notEnrolled = $this->createStudent($school, ['student_number' => 'S-2']);
        $this->createStudentEnrollment($notEnrolled, $section);
        // Never explicitly enrolled in this elective -- must be excluded.

        $this->assertSame([$enrolled->id], $this->reads()->currentRosterStudentIds($offering));
    }

    #[Test]
    public function a_withdrawn_elective_membership_is_excluded_from_the_current_roster(): void
    {
        $school = $this->createSchool();
        $campus = $this->createCampus($school);
        $year = $this->createAcademicYear($school);
        $grade = $this->createGradeLevel($school);
        $section = $this->createSection($year, $campus, $grade);
        $offering = $this->createSubjectOffering($year, $campus, $grade, $this->createSubject($school), ['is_required' => false]);
        $student = $this->createStudent($school, ['student_number' => 'S-1']);
        $this->createStudentEnrollment($student, $section);
        $enrollment = $this->writes()->enroll($student, $offering, '2026-06-01');

        $this->writes()->withdraw($enrollment, '2026-08-01');

        $this->assertSame([], $this->reads()->currentRosterStudentIds($offering));
    }

    // --- C. Dynamic exclusion on incompatible move (brief section 41) -----

    #[Test]
    public function a_student_moved_to_an_incompatible_grade_drops_out_of_the_required_offerings_roster(): void
    {
        $school = $this->createSchool();
        $campus = $this->createCampus($school);
        $year = $this->createAcademicYear($school);
        $gradeA = $this->createGradeLevel($school, ['code' => 'GA']);
        $gradeB = $this->createGradeLevel($school, ['code' => 'GB']);
        $sectionA = $this->createSection($year, $campus, $gradeA);
        $sectionB = $this->createSection($year, $campus, $gradeB);
        $offering = $this->createSubjectOffering($year, $campus, $gradeA, $this->createSubject($school), ['is_required' => true]);
        $student = $this->createStudent($school, ['student_number' => 'S-1']);
        $original = $this->createStudentEnrollment($student, $sectionA);

        $this->assertSame([$student->id], $this->reads()->currentRosterStudentIds($offering));

        // Student is promoted/transferred out of Grade A -- via the same
        // TenantContext discipline every other write in this test suite
        // uses, mirroring what StudentEnrollmentService::transferPlacement()
        // would do (a new active row, the old one closed).
        app(TenantContext::class)->withSchool($school, function () use ($school, $original, $student, $sectionB) {
            $original->update(['status' => 'transferred', 'ends_on' => '2026-08-31']);
            StudentEnrollment::factory()->create([
                'school_id' => $school->id,
                'student_id' => $student->id,
                'academic_year_id' => $sectionB->academic_year_id,
                'campus_id' => $sectionB->campus_id,
                'grade_level_id' => $sectionB->grade_level_id,
                'section_id' => $sectionB->id,
                'starts_on' => '2026-09-01',
            ]);
        });

        $this->assertSame([], $this->reads()->currentRosterStudentIds($offering), 'moved-out Student must drop out of the roster automatically, with zero changes to StudentSubjectEnrollmentService');
    }

    #[Test]
    public function a_student_moved_to_an_incompatible_grade_drops_out_of_an_elective_roster_without_the_row_being_touched(): void
    {
        $school = $this->createSchool();
        $campus = $this->createCampus($school);
        $year = $this->createAcademicYear($school);
        $gradeA = $this->createGradeLevel($school, ['code' => 'GA']);
        $gradeB = $this->createGradeLevel($school, ['code' => 'GB']);
        $sectionA = $this->createSection($year, $campus, $gradeA);
        $sectionB = $this->createSection($year, $campus, $gradeB);
        $offering = $this->createSubjectOffering($year, $campus, $gradeA, $this->createSubject($school), ['is_required' => false]);
        $student = $this->createStudent($school, ['student_number' => 'S-1']);
        $original = $this->createStudentEnrollment($student, $sectionA);
        $membership = $this->writes()->enroll($student, $offering, '2026-06-01');

        app(TenantContext::class)->withSchool($school, function () use ($school, $original, $student, $sectionB) {
            $original->update(['status' => 'transferred', 'ends_on' => '2026-08-31']);
            StudentEnrollment::factory()->create([
                'school_id' => $school->id,
                'student_id' => $student->id,
                'academic_year_id' => $sectionB->academic_year_id,
                'campus_id' => $sectionB->campus_id,
                'grade_level_id' => $sectionB->grade_level_id,
                'section_id' => $sectionB->id,
                'starts_on' => '2026-09-01',
            ]);
        });

        $this->assertSame([], $this->reads()->currentRosterStudentIds($offering));
        $refreshedMembership = app(TenantContext::class)->withSchool($school, fn () => $membership->fresh());
        $this->assertSame('active', $refreshedMembership->status, 'the StudentSubjectEnrollment row itself is never mutated by the StudentEnrollment change -- exclusion happens purely at read time');
    }

    // --- D. Deduplication (brief section 40's "no Student appears twice") -

    #[Test]
    public function each_student_appears_at_most_once_in_a_required_offerings_roster(): void
    {
        $school = $this->createSchool();
        $campus = $this->createCampus($school);
        $year = $this->createAcademicYear($school);
        $grade = $this->createGradeLevel($school);
        $section = $this->createSection($year, $campus, $grade);
        $offering = $this->createSubjectOffering($year, $campus, $grade, $this->createSubject($school), ['is_required' => true]);
        $student = $this->createStudent($school, ['student_number' => 'S-1']);
        $this->createStudentEnrollment($student, $section);

        $roster = $this->reads()->currentRosterStudentIds($offering);

        $this->assertCount(1, $roster);
        $this->assertCount(1, array_unique($roster));
    }

    // --- E. Scale / query-count bound (brief section 44) --------------------

    #[Test]
    public function roster_resolution_stays_bounded_for_a_realistic_offering_size(): void
    {
        $school = $this->createSchool();
        $campus = $this->createCampus($school);
        $year = $this->createAcademicYear($school);
        $grade = $this->createGradeLevel($school);
        $section = $this->createSection($year, $campus, $grade);
        $offering = $this->createSubjectOffering($year, $campus, $grade, $this->createSubject($school), ['is_required' => false]);

        for ($i = 0; $i < 60; $i++) {
            $student = $this->createStudent($school, ['student_number' => "SCALE-{$i}"]);
            $this->createStudentEnrollment($student, $section);
            $this->writes()->enroll($student, $offering, '2026-06-01');
        }

        $queryCount = 0;
        DB::listen(function () use (&$queryCount) {
            $queryCount++;
        });

        $roster = $this->reads()->currentRosterStudentIds($offering);

        $this->assertCount(60, $roster);
        $this->assertLessThan(5, $queryCount, 'roster resolution must be a small, fixed number of queries regardless of roster size (no N+1)');
    }
}
