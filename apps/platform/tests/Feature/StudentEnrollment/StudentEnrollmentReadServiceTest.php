<?php

namespace Tests\Feature\StudentEnrollment;

use App\Domain\Students\Application\StudentEnrollmentReadService;
use App\Domain\Students\Application\StudentEnrollmentService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 1B.4: StudentEnrollmentReadService -- the canonical read layer.
 * Distinct from StudentEnrollmentTest/ServiceTest/LifecycleTest (write
 * paths); these tests never mutate state, and every read runs inside a
 * real TenantContext (matching how a real request's middleware would
 * have already established one before a future controller ran).
 */
class StudentEnrollmentReadServiceTest extends TestCase
{
    use CreatesTenancyFixtures;

    private function readService(): StudentEnrollmentReadService
    {
        return app(StudentEnrollmentReadService::class);
    }

    private function writeService(): StudentEnrollmentService
    {
        return app(StudentEnrollmentService::class);
    }

    // ==================================================================
    // Current Enrollment
    // ==================================================================

    #[Test]
    public function current_for_returns_the_active_enrollment_in_the_given_academic_year(): void
    {
        $school = $this->createSchool();
        $campus = $this->createCampus($school);
        $year = $this->createAcademicYear($school, ['status' => 'active']);
        $grade = $this->createGradeLevel($school);
        $section = $this->createSection($year, $campus, $grade);
        $student = $this->createStudent($school, ['student_number' => 'S-1001']);
        $enrollment = $this->writeService()->enroll($student, $section, '12', '2026-06-01');

        $current = app(TenantContext::class)->withSchool($school, fn () => $this->readService()->currentFor($student, $year));

        $this->assertNotNull($current);
        $this->assertSame($enrollment->id, $current->id);
        $this->assertTrue($current->relationLoaded('academicYear'));
        $this->assertTrue($current->relationLoaded('campus'));
        $this->assertTrue($current->relationLoaded('gradeLevel'));
        $this->assertTrue($current->relationLoaded('section'));
    }

    #[Test]
    public function current_for_never_substitutes_a_historical_row_when_no_active_enrollment_exists_for_the_year(): void
    {
        $school = $this->createSchool();
        $campus = $this->createCampus($school);
        $year = $this->createAcademicYear($school, ['status' => 'active']);
        $grade = $this->createGradeLevel($school);
        $section = $this->createSection($year, $campus, $grade);
        $student = $this->createStudent($school, ['student_number' => 'S-1001']);
        $enrollment = $this->writeService()->enroll($student, $section, '12', '2026-06-01');
        $this->writeService()->complete($enrollment, '2027-04-30');

        $current = app(TenantContext::class)->withSchool($school, fn () => $this->readService()->currentFor($student, $year));

        $this->assertNull($current, 'A completed Enrollment must never be returned as "current".');
    }

    #[Test]
    public function current_for_resolves_the_active_academic_year_when_none_is_supplied(): void
    {
        $school = $this->createSchool();
        $campus = $this->createCampus($school);
        $year = $this->createAcademicYear($school, ['status' => 'active']);
        $grade = $this->createGradeLevel($school);
        $section = $this->createSection($year, $campus, $grade);
        $student = $this->createStudent($school, ['student_number' => 'S-1001']);
        $enrollment = $this->writeService()->enroll($student, $section, '12', '2026-06-01');

        $current = app(TenantContext::class)->withSchool($school, fn () => $this->readService()->currentFor($student));

        $this->assertNotNull($current);
        $this->assertSame($enrollment->id, $current->id);
    }

    #[Test]
    public function current_for_returns_null_when_the_school_has_no_active_academic_year_and_none_is_supplied(): void
    {
        $school = $this->createSchool();
        $campus = $this->createCampus($school);
        $year = $this->createAcademicYear($school, ['status' => 'draft']); // never activated
        $grade = $this->createGradeLevel($school);
        $section = $this->createSection($year, $campus, $grade);
        $student = $this->createStudent($school, ['student_number' => 'S-1001']);

        $current = app(TenantContext::class)->withSchool($school, fn () => $this->readService()->currentFor($student));

        $this->assertNull($current, 'Must never silently guess at a historical row when there is no active Academic Year.');
    }

    // ==================================================================
    // History
    // ==================================================================

    #[Test]
    public function history_for_returns_every_status_in_deterministic_chronological_order(): void
    {
        $school = $this->createSchool();
        $campus = $this->createCampus($school);
        $gradeFive = $this->createGradeLevel($school, ['name' => 'Grade 5', 'code' => 'G5', 'sequence' => 5]);
        $gradeSix = $this->createGradeLevel($school, ['name' => 'Grade 6', 'code' => 'G6', 'sequence' => 6]);
        $yearOne = $this->createAcademicYear($school, ['code' => '2025-26', 'starts_on' => '2025-06-01', 'ends_on' => '2026-04-30']);
        $yearTwo = $this->createAcademicYear($school, ['code' => '2026-27', 'starts_on' => '2026-06-01', 'ends_on' => '2027-04-30']);
        $sectionOne = $this->createSection($yearOne, $campus, $gradeFive, ['name' => '5A', 'code' => '5A']);
        $sectionTwoA = $this->createSection($yearTwo, $campus, $gradeSix, ['name' => '6A', 'code' => '6A']);
        $sectionTwoB = $this->createSection($yearTwo, $campus, $gradeSix, ['name' => '6B', 'code' => '6B']);
        $student = $this->createStudent($school, ['student_number' => 'S-1001']);

        $completed = $this->writeService()->enroll($student, $sectionOne, '12', '2025-06-01');
        $this->writeService()->complete($completed, '2026-04-30');
        $transferredOriginal = $this->writeService()->enroll($student, $sectionTwoA, '07', '2026-06-01');
        $active = $this->writeService()->transferPlacement($transferredOriginal, $sectionTwoB, '08', '2026-09-15');

        $history = app(TenantContext::class)->withSchool($school, fn () => $this->readService()->historyFor($student));

        $this->assertCount(3, $history);
        $this->assertSame($completed->id, $history[0]->id);
        $this->assertSame('completed', $history[0]->status);
        $this->assertSame($transferredOriginal->id, $history[1]->id);
        $this->assertSame('transferred', $history[1]->status);
        $this->assertSame($active->id, $history[2]->id);
        $this->assertSame('active', $history[2]->status);
    }

    // ==================================================================
    // Detail
    // ==================================================================

    #[Test]
    public function detail_eager_loads_every_academic_and_student_relationship(): void
    {
        $school = $this->createSchool();
        $campus = $this->createCampus($school);
        $year = $this->createAcademicYear($school);
        $grade = $this->createGradeLevel($school);
        $section = $this->createSection($year, $campus, $grade);
        $student = $this->createStudent($school, ['student_number' => 'S-1001']);
        $enrollment = $this->writeService()->enroll($student, $section, '12', '2026-06-01');

        $detail = app(TenantContext::class)->withSchool($school, fn () => $this->readService()->detail($enrollment->id));

        $this->assertNotNull($detail);
        $this->assertTrue($detail->relationLoaded('student'));
        $this->assertTrue($detail->relationLoaded('academicYear'));
        $this->assertTrue($detail->relationLoaded('campus'));
        $this->assertTrue($detail->relationLoaded('gradeLevel'));
        $this->assertTrue($detail->relationLoaded('section'));
        $this->assertFalse($detail->relationLoaded('student') && $detail->student->relationLoaded('guardians'), 'detail() must never eager-load Guardian data.');
    }

    // ==================================================================
    // School isolation
    // ==================================================================

    #[Test]
    public function school_a_read_methods_never_return_school_bs_enrollment(): void
    {
        $schoolA = $this->createSchool();
        $studentA = $this->createStudent($schoolA, ['student_number' => 'S-1001']);

        $schoolB = $this->createSchool();
        $campusB = $this->createCampus($schoolB);
        $yearB = $this->createAcademicYear($schoolB);
        $gradeB = $this->createGradeLevel($schoolB);
        $sectionB = $this->createSection($yearB, $campusB, $gradeB);
        $studentB = $this->createStudent($schoolB, ['student_number' => 'B-0001']);
        $enrollmentB = $this->writeService()->enroll($studentB, $sectionB, '01', '2026-06-01');

        // detail(), given School B's real Enrollment id, while School A
        // is the active TenantContext.
        $detail = app(TenantContext::class)->withSchool($schoolA, fn () => $this->readService()->detail($enrollmentB->id));
        $this->assertNull($detail, "School A's TenantContext must never resolve School B's Enrollment by id.");

        // directory(), filtered by School B's real Section id, while
        // School A is the active TenantContext -- must match zero rows,
        // never reveal School B's row.
        $page = app(TenantContext::class)->withSchool(
            $schoolA,
            fn () => $this->readService()->directory(['section_id' => $sectionB->id]),
        );
        $this->assertSame(0, $page->total(), "A foreign-School filter value must match zero rows, never widen the query into School B's data.");
    }

    // ==================================================================
    // Directory filters
    // ==================================================================

    #[Test]
    public function directory_filters_combine_correctly(): void
    {
        $school = $this->createSchool();
        $campus = $this->createCampus($school);
        $year = $this->createAcademicYear($school);
        $gradeFive = $this->createGradeLevel($school, ['name' => 'Grade 5', 'code' => 'G5', 'sequence' => 5]);
        $gradeSix = $this->createGradeLevel($school, ['name' => 'Grade 6', 'code' => 'G6', 'sequence' => 6]);
        $sectionFive = $this->createSection($year, $campus, $gradeFive, ['name' => '5A', 'code' => '5A']);
        $sectionSix = $this->createSection($year, $campus, $gradeSix, ['name' => '6A', 'code' => '6A']);
        $aarav = $this->createStudent($school, ['student_number' => 'S-1001', 'first_name' => 'Aarav', 'last_name' => 'Sharma']);
        $diya = $this->createStudent($school, ['student_number' => 'S-1002', 'first_name' => 'Diya', 'last_name' => 'Patel']);

        $this->writeService()->enroll($aarav, $sectionFive, '12', '2026-06-01');
        $enrollmentDiya = $this->writeService()->enroll($diya, $sectionSix, '01', '2026-06-01');

        $byGrade = app(TenantContext::class)->withSchool($school, fn () => $this->readService()->directory(['grade_level_id' => $gradeSix->id]));
        $this->assertSame(1, $byGrade->total());
        $this->assertSame($enrollmentDiya->id, $byGrade->items()[0]->id);

        $byStudentNumber = app(TenantContext::class)->withSchool($school, fn () => $this->readService()->directory(['student_number' => 'S-1001']));
        $this->assertSame(1, $byStudentNumber->total());

        $byStudentName = app(TenantContext::class)->withSchool($school, fn () => $this->readService()->directory(['student_name' => 'Diya']));
        $this->assertSame(1, $byStudentName->total());
        $this->assertSame($enrollmentDiya->id, $byStudentName->items()[0]->id);

        $byRollNumber = app(TenantContext::class)->withSchool($school, fn () => $this->readService()->directory(['roll_number' => '01']));
        $this->assertSame(1, $byRollNumber->total());

        $byStatus = app(TenantContext::class)->withSchool($school, fn () => $this->readService()->directory(['status' => 'active']));
        $this->assertSame(2, $byStatus->total());

        $combined = app(TenantContext::class)->withSchool(
            $school,
            fn () => $this->readService()->directory(['grade_level_id' => $gradeFive->id, 'status' => 'active']),
        );
        $this->assertSame(1, $combined->total());
    }

    #[Test]
    public function directory_respects_the_requested_page_size(): void
    {
        $school = $this->createSchool();
        $campus = $this->createCampus($school);
        $year = $this->createAcademicYear($school);
        $grade = $this->createGradeLevel($school);
        $section = $this->createSection($year, $campus, $grade);

        for ($i = 1; $i <= 5; $i++) {
            $student = $this->createStudent($school, ['student_number' => "S-100{$i}"]);
            $this->writeService()->enroll($student, $section, (string) $i, '2026-06-01');
        }

        $page = app(TenantContext::class)->withSchool($school, fn () => $this->readService()->directory([], perPage: 2));

        $this->assertSame(5, $page->total());
        $this->assertCount(2, $page->items());
        $this->assertSame(2, $page->perPage());
    }

    // ==================================================================
    // Privacy / data minimization
    // ==================================================================

    #[Test]
    public function directory_rows_never_expose_the_students_date_of_birth(): void
    {
        $school = $this->createSchool();
        $campus = $this->createCampus($school);
        $year = $this->createAcademicYear($school);
        $grade = $this->createGradeLevel($school);
        $section = $this->createSection($year, $campus, $grade);
        $student = $this->createStudent($school, ['student_number' => 'S-1001', 'date_of_birth' => '2015-04-12']);
        $this->writeService()->enroll($student, $section, '12', '2026-06-01');

        $page = app(TenantContext::class)->withSchool($school, fn () => $this->readService()->directory());

        $row = $page->items()[0];
        $this->assertTrue($row->relationLoaded('student'));
        $this->assertArrayNotHasKey('date_of_birth', $row->student->getAttributes(), "The directory's eager-loaded Student relation must never carry date_of_birth.");
    }

    // ==================================================================
    // N+1 prevention
    // ==================================================================

    #[Test]
    public function directory_does_not_issue_one_query_per_row(): void
    {
        $school = $this->createSchool();
        $campus = $this->createCampus($school);
        $year = $this->createAcademicYear($school);
        $grade = $this->createGradeLevel($school);
        $section = $this->createSection($year, $campus, $grade);

        for ($i = 1; $i <= 10; $i++) {
            $student = $this->createStudent($school, ['student_number' => "S-10{$i}"]);
            $this->writeService()->enroll($student, $section, (string) $i, '2026-06-01');
        }

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        $page = app(TenantContext::class)->withSchool($school, fn () => $this->readService()->directory());
        // Force lazy pagination/relation resolution to actually execute.
        foreach ($page->items() as $row) {
            $row->student->student_number;
            $row->academicYear->code;
            $row->campus->code;
            $row->gradeLevel->code;
            $row->section->code;
        }

        // One count query + one page query + five eager-load queries
        // (student/academicYear/campus/gradeLevel/section) -- NOT one
        // query per Enrollment row (10 rows would mean 50+ queries if
        // eager-loading were broken).
        $this->assertLessThan(15, $queryCount, 'Enrollment directory must not perform one query per row.');
    }
}
