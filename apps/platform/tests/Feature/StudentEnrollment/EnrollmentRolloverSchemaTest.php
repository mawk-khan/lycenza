<?php

namespace Tests\Feature\StudentEnrollment;

use App\Domain\AcademicStructure\Infrastructure\AcademicYear;
use App\Domain\AcademicStructure\Infrastructure\GradeLevel;
use App\Domain\AcademicStructure\Infrastructure\Section;
use App\Domain\Students\Application\EnrollmentRolloverPlanService;
use App\Domain\Students\Application\Exceptions\CrossSchoolRolloverPlanException;
use App\Domain\Students\Application\Exceptions\InvalidRolloverPlanYearsException;
use App\Domain\Students\Application\Exceptions\OpenRolloverPlanConflictException;
use App\Domain\Students\Infrastructure\EnrollmentRolloverMapping;
use App\Domain\Students\Infrastructure\EnrollmentRolloverPlan;
use App\Domain\Students\Infrastructure\Student;
use App\Domain\Students\Infrastructure\StudentEnrollment;
use App\Models\Campus;
use App\Models\School;
use App\Models\SchoolAuditEvent;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 1B.7A: the durable rollover plan/mapping/item schema and
 * domain model -- proves the schema can REPRESENT every shape the
 * accepted architecture (docs/modules/STUDENT-ENROLLMENT.md,
 * "Academic-Year Rollover & Promotion — Architecture Decision (Phase
 * 1B.7)") calls for, without any dry-run/eligibility/execution engine
 * existing yet (Phase 1B.7B/1B.7C). No test here calls
 * StudentEnrollmentService::enroll()/complete() or mutates a
 * StudentEnrollment's lifecycle -- rollover schema/service code in
 * this checkpoint touches ONLY the three new tables.
 */
class EnrollmentRolloverSchemaTest extends TestCase
{
    use CreatesTenancyFixtures;

    private function service(): EnrollmentRolloverPlanService
    {
        return app(EnrollmentRolloverPlanService::class);
    }

    // ==================================================================
    // EnrollmentRolloverPlanService::createDraft()
    // ==================================================================

    #[Test]
    public function create_draft_creates_a_plan_in_draft_status_at_configuration_version_one(): void
    {
        $school = $this->createSchool();
        $sourceYear = $this->createAcademicYear($school, ['code' => 'SRC']);
        $targetYear = $this->createAcademicYear($school, ['code' => 'TGT']);

        $plan = $this->service()->createDraft($school, $sourceYear, $targetYear);

        $this->assertSame('draft', $plan->status);
        $this->assertSame(1, $plan->configuration_version);
        $this->assertNull($plan->validated_configuration_version);
        $this->assertTrue($plan->isValidatedForCurrentConfiguration() === false);
        $this->assertSame($sourceYear->id, $plan->source_academic_year_id);
        $this->assertSame($targetYear->id, $plan->target_academic_year_id);
    }

    #[Test]
    public function create_draft_rejects_identical_source_and_target_year(): void
    {
        $school = $this->createSchool();
        $year = $this->createAcademicYear($school);

        $this->expectException(InvalidRolloverPlanYearsException::class);
        $this->service()->createDraft($school, $year, $year);
    }

    #[Test]
    public function create_draft_rejects_a_foreign_school_source_year(): void
    {
        $school = $this->createSchool();
        $targetYear = $this->createAcademicYear($school, ['code' => 'TGT']);
        $otherSchool = $this->createSchool();
        $foreignSourceYear = $this->createAcademicYear($otherSchool);

        $this->expectException(CrossSchoolRolloverPlanException::class);
        $this->service()->createDraft($school, $foreignSourceYear, $targetYear);
    }

    #[Test]
    public function create_draft_rejects_a_foreign_school_target_year(): void
    {
        $school = $this->createSchool();
        $sourceYear = $this->createAcademicYear($school, ['code' => 'SRC']);
        $otherSchool = $this->createSchool();
        $foreignTargetYear = $this->createAcademicYear($otherSchool);

        $this->expectException(CrossSchoolRolloverPlanException::class);
        $this->service()->createDraft($school, $sourceYear, $foreignTargetYear);
    }

    #[Test]
    public function a_second_open_plan_for_the_same_year_pair_is_a_clean_conflict(): void
    {
        $school = $this->createSchool();
        $sourceYear = $this->createAcademicYear($school, ['code' => 'SRC']);
        $targetYear = $this->createAcademicYear($school, ['code' => 'TGT']);
        $this->service()->createDraft($school, $sourceYear, $targetYear);

        $this->expectException(OpenRolloverPlanConflictException::class);
        $this->service()->createDraft($school, $sourceYear, $targetYear);
    }

    #[Test]
    public function a_new_plan_is_allowed_for_the_same_year_pair_once_the_prior_plan_is_cancelled(): void
    {
        $school = $this->createSchool();
        $sourceYear = $this->createAcademicYear($school, ['code' => 'SRC']);
        $targetYear = $this->createAcademicYear($school, ['code' => 'TGT']);
        $first = $this->service()->createDraft($school, $sourceYear, $targetYear);
        app(TenantContext::class)->withSchool($school, fn () => $first->update(['status' => 'cancelled', 'cancelled_at' => now()]));

        $second = $this->service()->createDraft($school, $sourceYear, $targetYear);

        $this->assertNotSame($first->id, $second->id);
    }

    #[Test]
    public function create_draft_audits_plan_creation(): void
    {
        $school = $this->createSchool();
        $sourceYear = $this->createAcademicYear($school, ['code' => 'SRC']);
        $targetYear = $this->createAcademicYear($school, ['code' => 'TGT']);

        $plan = $this->service()->createDraft($school, $sourceYear, $targetYear);

        $count = app(TenantContext::class)->withSchool(
            $school,
            fn () => SchoolAuditEvent::query()
                ->where('event_type', 'enrollment_rollover_plan.created')
                ->where('subject_id', $plan->id)
                ->count(),
        );
        $this->assertSame(1, $count);
    }

    // ==================================================================
    // Database-level CHECK/partial-unique enforcement (bypassing the
    // service, to prove the database itself -- not just the service --
    // is the authoritative guarantee)
    // ==================================================================

    #[Test]
    public function the_database_rejects_identical_source_and_target_year_even_via_direct_create(): void
    {
        $school = $this->createSchool();
        $year = $this->createAcademicYear($school);

        $this->expectException(QueryException::class);
        app(TenantContext::class)->withSchool($school, fn () => EnrollmentRolloverPlan::query()->create([
            'school_id' => $school->id,
            'source_academic_year_id' => $year->id,
            'target_academic_year_id' => $year->id,
            'status' => 'draft',
        ]));
    }

    // ==================================================================
    // Mapping representation (sections 55, 56, 57 of the brief)
    // ==================================================================

    #[Test]
    public function a_grade_level_default_mapping_has_a_null_source_section(): void
    {
        $school = $this->createSchool();
        $sourceYear = $this->createAcademicYear($school, ['code' => 'SRC']);
        $targetYear = $this->createAcademicYear($school, ['code' => 'TGT']);
        $plan = $this->createEnrollmentRolloverPlan($sourceYear, $targetYear);
        $sourceGrade = $this->createGradeLevel($school);
        $targetGrade = $this->createGradeLevel($school);

        $mapping = $this->createEnrollmentRolloverMapping($plan, $sourceGrade, $targetGrade);

        $this->assertNull($mapping->source_section_id);
        $this->assertFalse($mapping->isRepeat());
    }

    #[Test]
    public function repeat_retention_is_represented_by_identical_source_and_target_grade_with_no_new_status(): void
    {
        $school = $this->createSchool();
        $sourceYear = $this->createAcademicYear($school, ['code' => 'SRC']);
        $targetYear = $this->createAcademicYear($school, ['code' => 'TGT']);
        $plan = $this->createEnrollmentRolloverPlan($sourceYear, $targetYear);
        $grade = $this->createGradeLevel($school);

        $mapping = $this->createEnrollmentRolloverMapping($plan, $grade, $grade);

        $this->assertTrue($mapping->isRepeat());
        $this->assertSame($mapping->source_grade_level_id, $mapping->target_grade_level_id);
    }

    #[Test]
    public function promotion_representation_supports_an_explicit_target_section(): void
    {
        ['school' => $school, 'campus' => $campus, 'year' => $sourceYear, 'grade' => $sourceGrade, 'section' => $sourceSection] = $this->buildFullPlacementContext();
        $targetYear = $this->createAcademicYear($school, ['code' => 'TGT']);
        $targetGrade = $this->createGradeLevel($school);
        $targetSection = $this->createSection($targetYear, $campus, $targetGrade);
        $plan = $this->createEnrollmentRolloverPlan($sourceYear, $targetYear);

        $mapping = $this->createEnrollmentRolloverMapping($plan, $sourceGrade, $targetGrade, [
            'source_section_id' => $sourceSection->id,
            'target_section_id' => $targetSection->id,
        ]);

        $this->assertFalse($mapping->isRepeat());
        $this->assertSame($sourceSection->id, $mapping->source_section_id);
        $this->assertSame($targetSection->id, $mapping->target_section_id);
    }

    #[Test]
    public function terminal_grade_is_represented_by_the_absence_of_a_mapping_row(): void
    {
        $school = $this->createSchool();
        $sourceYear = $this->createAcademicYear($school, ['code' => 'SRC']);
        $targetYear = $this->createAcademicYear($school, ['code' => 'TGT']);
        $plan = $this->createEnrollmentRolloverPlan($sourceYear, $targetYear);
        $terminalGrade = $this->createGradeLevel($school);

        $exists = app(TenantContext::class)->withSchool(
            $school,
            fn () => EnrollmentRolloverMapping::query()
                ->where('plan_id', $plan->id)
                ->where('source_grade_level_id', $terminalGrade->id)
                ->exists(),
        );

        $this->assertFalse($exists, 'a terminal Grade has no mapping row at all, never a row with a null target');
    }

    #[Test]
    public function only_one_grade_level_default_mapping_is_allowed_per_source_grade(): void
    {
        $school = $this->createSchool();
        $sourceYear = $this->createAcademicYear($school, ['code' => 'SRC']);
        $targetYear = $this->createAcademicYear($school, ['code' => 'TGT']);
        $plan = $this->createEnrollmentRolloverPlan($sourceYear, $targetYear);
        $sourceGrade = $this->createGradeLevel($school);
        $targetGradeOne = $this->createGradeLevel($school);
        $targetGradeTwo = $this->createGradeLevel($school);
        $this->createEnrollmentRolloverMapping($plan, $sourceGrade, $targetGradeOne);

        $this->expectException(QueryException::class);
        $this->createEnrollmentRolloverMapping($plan, $sourceGrade, $targetGradeTwo);
    }

    #[Test]
    public function only_one_section_specific_override_is_allowed_per_source_section(): void
    {
        ['school' => $school, 'campus' => $campus, 'year' => $sourceYear, 'grade' => $sourceGrade, 'section' => $sourceSection] = $this->buildFullPlacementContext();
        $targetYear = $this->createAcademicYear($school, ['code' => 'TGT']);
        $targetGradeOne = $this->createGradeLevel($school);
        $targetGradeTwo = $this->createGradeLevel($school);
        $plan = $this->createEnrollmentRolloverPlan($sourceYear, $targetYear);
        $this->createEnrollmentRolloverMapping($plan, $sourceGrade, $targetGradeOne, ['source_section_id' => $sourceSection->id]);

        $this->expectException(QueryException::class);
        $this->createEnrollmentRolloverMapping($plan, $sourceGrade, $targetGradeTwo, ['source_section_id' => $sourceSection->id]);
    }

    #[Test]
    public function two_different_source_sections_may_map_to_two_different_target_sections_in_the_same_plan(): void
    {
        ['school' => $school, 'campus' => $campus, 'year' => $sourceYear, 'grade' => $sourceGrade, 'section' => $sourceSectionA] = $this->buildFullPlacementContext();
        $sourceSectionB = $this->createSection($sourceYear, $campus, $sourceGrade, ['name' => 'B', 'code' => 'B']);
        $targetYear = $this->createAcademicYear($school, ['code' => 'TGT']);
        $targetGrade = $this->createGradeLevel($school);
        $targetSectionB = $this->createSection($targetYear, $campus, $targetGrade, ['name' => 'B', 'code' => 'B']);
        $targetSectionC = $this->createSection($targetYear, $campus, $targetGrade, ['name' => 'C', 'code' => 'C']);
        $plan = $this->createEnrollmentRolloverPlan($sourceYear, $targetYear);

        $mappingA = $this->createEnrollmentRolloverMapping($plan, $sourceGrade, $targetGrade, [
            'source_section_id' => $sourceSectionA->id, 'target_section_id' => $targetSectionB->id,
        ]);
        $mappingB = $this->createEnrollmentRolloverMapping($plan, $sourceGrade, $targetGrade, [
            'source_section_id' => $sourceSectionB->id, 'target_section_id' => $targetSectionC->id,
        ]);

        $this->assertNotSame($mappingA->target_section_id, $mappingB->target_section_id);
    }

    // ==================================================================
    // Item representation (sections 54, 55, 56, 57 of the brief)
    // ==================================================================

    #[Test]
    public function an_item_anchors_to_the_authoritative_source_enrollment_not_a_superseded_transferred_row(): void
    {
        ['school' => $school, 'campus' => $campus, 'year' => $sourceYear, 'grade' => $grade, 'section' => $sectionA, 'student' => $student] = $this->buildFullPlacementContext();
        $sectionB = $this->createSection($sourceYear, $campus, $grade, ['name' => 'B', 'code' => 'B']);
        $transferredEnrollment = $this->createStudentEnrollment($student, $sectionA, ['status' => 'transferred', 'roll_number' => '01', 'ends_on' => '2026-06-30']);
        $activeEnrollment = $this->createStudentEnrollment($student, $sectionB, ['status' => 'active', 'roll_number' => '02', 'starts_on' => '2026-07-01']);
        $targetYear = $this->createAcademicYear($school, ['code' => 'TGT']);
        $plan = $this->createEnrollmentRolloverPlan($sourceYear, $targetYear);

        $item = $this->createEnrollmentRolloverItem($plan, $student, $activeEnrollment);

        $this->assertSame($activeEnrollment->id, $item->source_enrollment_id);
        $this->assertNotSame($transferredEnrollment->id, $item->source_enrollment_id);
        // Neither historical row was touched by constructing the item.
        $freshTransferred = app(TenantContext::class)->withSchool($school, fn () => $transferredEnrollment->fresh());
        $freshActive = app(TenantContext::class)->withSchool($school, fn () => $activeEnrollment->fresh());
        $this->assertSame('transferred', $freshTransferred->status);
        $this->assertSame('active', $freshActive->status);
    }

    #[Test]
    public function the_database_rejects_a_source_enrollment_belonging_to_a_different_student_than_the_item(): void
    {
        ['school' => $school, 'campus' => $campus, 'year' => $sourceYear, 'grade' => $grade, 'section' => $section, 'student' => $studentA] = $this->buildFullPlacementContext();
        $studentB = $this->createStudent($school, ['student_number' => 'S-1002']);
        $enrollmentForStudentA = $this->createStudentEnrollment($studentA, $section);
        $targetYear = $this->createAcademicYear($school, ['code' => 'TGT']);
        $plan = $this->createEnrollmentRolloverPlan($sourceYear, $targetYear);

        $this->expectException(QueryException::class);
        // student_id = Student B, source_enrollment_id = Student A's Enrollment.
        $this->createEnrollmentRolloverItem($plan, $studentB, $enrollmentForStudentA);
    }

    #[Test]
    public function only_one_item_is_allowed_per_student_per_plan(): void
    {
        ['school' => $school, 'year' => $sourceYear, 'section' => $section, 'student' => $student] = $this->buildFullPlacementContext();
        $enrollment = $this->createStudentEnrollment($student, $section);
        $targetYear = $this->createAcademicYear($school, ['code' => 'TGT']);
        $plan = $this->createEnrollmentRolloverPlan($sourceYear, $targetYear);
        $this->createEnrollmentRolloverItem($plan, $student, $enrollment);

        $this->expectException(QueryException::class);
        $this->createEnrollmentRolloverItem($plan, $student, $enrollment);
    }

    #[Test]
    public function roll_number_is_stored_as_text_and_preserves_leading_zeros(): void
    {
        ['school' => $school, 'year' => $sourceYear, 'section' => $section, 'student' => $student] = $this->buildFullPlacementContext();
        $enrollment = $this->createStudentEnrollment($student, $section);
        $targetYear = $this->createAcademicYear($school, ['code' => 'TGT']);
        $plan = $this->createEnrollmentRolloverPlan($sourceYear, $targetYear);

        $item = $this->createEnrollmentRolloverItem($plan, $student, $enrollment, [
            'target_roll_number' => '007',
            'roll_number_strategy' => 'explicit',
        ]);

        $fresh = app(TenantContext::class)->withSchool($school, fn () => $item->fresh());
        $this->assertSame('007', $fresh->target_roll_number);
        $this->assertIsString($fresh->target_roll_number);
    }

    #[Test]
    public function repeat_decision_is_representable_without_a_new_enrollment_status(): void
    {
        ['school' => $school, 'year' => $sourceYear, 'grade' => $grade, 'section' => $section, 'student' => $student] = $this->buildFullPlacementContext();
        $enrollment = $this->createStudentEnrollment($student, $section);
        $targetYear = $this->createAcademicYear($school, ['code' => 'TGT']);
        $plan = $this->createEnrollmentRolloverPlan($sourceYear, $targetYear);

        $item = $this->createEnrollmentRolloverItem($plan, $student, $enrollment, ['decision' => 'repeat']);

        $this->assertSame('repeat', $item->decision);
        $this->assertTrue($item->isRepeat());
        $fresh = app(TenantContext::class)->withSchool($school, fn () => $enrollment->fresh());
        $this->assertSame('active', $fresh->status, 'constructing a repeat-decision item must not touch the source Enrollment');
    }

    #[Test]
    public function promote_decision_with_an_explicit_target_section_creates_no_target_enrollment_yet(): void
    {
        ['school' => $school, 'campus' => $campus, 'year' => $sourceYear, 'section' => $sourceSection, 'student' => $student] = $this->buildFullPlacementContext();
        $enrollment = $this->createStudentEnrollment($student, $sourceSection);
        $targetYear = $this->createAcademicYear($school, ['code' => 'TGT']);
        $targetGrade = $this->createGradeLevel($school);
        $targetSection = $this->createSection($targetYear, $campus, $targetGrade);
        $plan = $this->createEnrollmentRolloverPlan($sourceYear, $targetYear);

        $item = $this->createEnrollmentRolloverItem($plan, $student, $enrollment, [
            'decision' => 'promote',
            'target_section_id' => $targetSection->id,
        ]);

        $this->assertSame('promote', $item->decision);
        $this->assertSame($targetSection->id, $item->target_section_id);
        $this->assertNull($item->target_enrollment_id);
        $this->assertNull($item->execution_status);
        $this->assertFalse($item->hasExecuted());

        $studentEnrollmentCount = app(TenantContext::class)->withSchool(
            $school,
            fn () => StudentEnrollment::query()->where('student_id', $student->id)->count(),
        );
        $this->assertSame(1, $studentEnrollmentCount, 'no target Enrollment may be created by this checkpoint');
    }

    #[Test]
    public function terminal_grade_item_uses_exclude_decision_not_a_student_status_change(): void
    {
        ['school' => $school, 'year' => $sourceYear, 'section' => $section, 'student' => $student] = $this->buildFullPlacementContext();
        $enrollment = $this->createStudentEnrollment($student, $section);
        $targetYear = $this->createAcademicYear($school, ['code' => 'TGT']);
        $plan = $this->createEnrollmentRolloverPlan($sourceYear, $targetYear);

        $item = $this->createEnrollmentRolloverItem($plan, $student, $enrollment, [
            'decision' => 'exclude',
            'validation_result' => 'terminal_grade',
        ]);

        $this->assertSame('exclude', $item->decision);
        $this->assertSame('terminal_grade', $item->validation_result);
        $fresh = app(TenantContext::class)->withSchool($school, fn () => $student->fresh());
        $this->assertSame('active', $fresh->status, 'Student.status must never encode rollover/terminal-grade outcomes');
    }

    #[Test]
    public function execution_fields_are_nullable_and_unset_on_a_fresh_item(): void
    {
        ['school' => $school, 'year' => $sourceYear, 'section' => $section, 'student' => $student] = $this->buildFullPlacementContext();
        $enrollment = $this->createStudentEnrollment($student, $section);
        $targetYear = $this->createAcademicYear($school, ['code' => 'TGT']);
        $plan = $this->createEnrollmentRolloverPlan($sourceYear, $targetYear);

        $item = $this->createEnrollmentRolloverItem($plan, $student, $enrollment);

        $this->assertNull($item->execution_status);
        $this->assertNull($item->target_enrollment_id);
        $this->assertNull($item->executed_at);
        $this->assertNull($item->validation_result);
        $this->assertNull($item->source_enrollment_status_snapshot);
    }

    // ==================================================================
    // Fixtures
    // ==================================================================

    /**
     * @return array{school: School, campus: Campus, year: AcademicYear, grade: GradeLevel, section: Section, student: Student}
     */
    private function buildFullPlacementContext(): array
    {
        $school = $this->createSchool();
        $campus = $this->createCampus($school);
        $year = $this->createAcademicYear($school, ['code' => 'SRC']);
        $grade = $this->createGradeLevel($school);
        $section = $this->createSection($year, $campus, $grade);
        $student = $this->createStudent($school, ['student_number' => 'S-1001']);

        return compact('school', 'campus', 'year', 'grade', 'section', 'student');
    }
}
