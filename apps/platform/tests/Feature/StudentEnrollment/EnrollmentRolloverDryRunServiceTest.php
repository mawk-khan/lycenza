<?php

namespace Tests\Feature\StudentEnrollment;

use App\Domain\AcademicStructure\Infrastructure\AcademicYear;
use App\Domain\AcademicStructure\Infrastructure\GradeLevel;
use App\Domain\AcademicStructure\Infrastructure\Section;
use App\Domain\AcademicStructure\Infrastructure\Subject;
use App\Domain\AcademicStructure\Infrastructure\SubjectOffering;
use App\Domain\Students\Application\EnrollmentRolloverDryRunService;
use App\Domain\Students\Application\EnrollmentRolloverPlanService;
use App\Domain\Students\Application\Exceptions\CrossSchoolRolloverPlanException;
use App\Domain\Students\Application\Exceptions\InvalidRollNumberStrategyException;
use App\Domain\Students\Application\Exceptions\InvalidRolloverItemDecisionException;
use App\Domain\Students\Application\Exceptions\RolloverPlanNoLongerConfigurableException;
use App\Domain\Students\Application\Exceptions\StaleRolloverConfigurationException;
use App\Domain\Students\Application\StudentEnrollmentService;
use App\Domain\Students\Application\StudentSubjectEnrollmentService;
use App\Domain\Students\Infrastructure\EnrollmentRolloverItem;
use App\Domain\Students\Infrastructure\EnrollmentRolloverPlan;
use App\Domain\Students\Infrastructure\Student;
use App\Domain\Students\Infrastructure\StudentEnrollment;
use App\Domain\Students\Infrastructure\StudentSubjectEnrollment;
use App\Models\Campus;
use App\Models\School;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 1B.7B: the rollover dry-run/eligibility/conflict engine
 * (EnrollmentRolloverDryRunService) and the small configuration-
 * mutation additions to EnrollmentRolloverPlanService
 * (upsertMapping()/setItemDecision()). Every test proves the engine
 * writes ONLY rollover planning state -- never a StudentEnrollment,
 * Student, AcademicYear, Section, Campus, or GradeLevel row.
 */
class EnrollmentRolloverDryRunServiceTest extends TestCase
{
    use CreatesTenancyFixtures;

    private function planService(): EnrollmentRolloverPlanService
    {
        return app(EnrollmentRolloverPlanService::class);
    }

    private function dryRun(): EnrollmentRolloverDryRunService
    {
        return app(EnrollmentRolloverDryRunService::class);
    }

    private function enrollmentService(): StudentEnrollmentService
    {
        return app(StudentEnrollmentService::class);
    }

    private function subjectEnrollmentService(): StudentSubjectEnrollmentService
    {
        return app(StudentSubjectEnrollmentService::class);
    }

    /**
     * @return array{school: School, campus: Campus, sourceYear: AcademicYear, targetYear: AcademicYear, sourceGrade: GradeLevel, targetGrade: GradeLevel, sourceSection: Section, targetSection: Section, plan: EnrollmentRolloverPlan}
     */
    private function buildContext(): array
    {
        $school = $this->createSchool();
        $campus = $this->createCampus($school);
        $sourceYear = $this->createAcademicYear($school, ['code' => 'SRC', 'starts_on' => '2026-06-01', 'ends_on' => '2027-04-30']);
        $targetYear = $this->createAcademicYear($school, ['code' => 'TGT', 'starts_on' => '2027-06-01', 'ends_on' => '2028-04-30']);
        $sourceGrade = $this->createGradeLevel($school, ['name' => 'Grade 5', 'code' => 'G5', 'sequence' => 5]);
        $targetGrade = $this->createGradeLevel($school, ['name' => 'Grade 6', 'code' => 'G6', 'sequence' => 6]);
        $sourceSection = $this->createSection($sourceYear, $campus, $sourceGrade, ['name' => '5A', 'code' => '5A']);
        $targetSection = $this->createSection($targetYear, $campus, $targetGrade, ['name' => '6B', 'code' => '6B']);
        $plan = $this->createEnrollmentRolloverPlan($sourceYear, $targetYear);

        return compact('school', 'campus', 'sourceYear', 'targetYear', 'sourceGrade', 'targetGrade', 'sourceSection', 'targetSection', 'plan');
    }

    /**
     * Phase 1G.2: extends buildContext() with one elective Subject and
     * a matched, mutually compatible source/target SubjectOffering pair
     * (source: sourceYear/campus/sourceGrade; target: targetYear/
     * campus/targetGrade -- the SAME campus, exactly the placement
     * side's own default target context) -- the minimal fixture every
     * subject-mapping dry-run test builds on.
     *
     * @return array{school: School, campus: Campus, sourceYear: AcademicYear, targetYear: AcademicYear, sourceGrade: GradeLevel, targetGrade: GradeLevel, sourceSection: Section, targetSection: Section, plan: EnrollmentRolloverPlan, subject: Subject, sourceOffering: SubjectOffering, targetOffering: SubjectOffering}
     */
    private function buildSubjectContext(): array
    {
        $base = $this->buildContext();
        $subject = $this->createSubject($base['school']);
        $sourceOffering = $this->createSubjectOffering($base['sourceYear'], $base['campus'], $base['sourceGrade'], $subject, ['is_required' => false]);
        $targetOffering = $this->createSubjectOffering($base['targetYear'], $base['campus'], $base['targetGrade'], $subject, ['is_required' => false]);

        return $base + compact('subject', 'sourceOffering', 'targetOffering');
    }

    /**
     * Wires up the placement side (Grade mapping + promote decision +
     * explicit Roll Number) so a test can focus purely on the subject
     * layer -- every subject test needs an otherwise-unremarkable READY
     * (or, via a caller-supplied override, ALREADY_ENROLLED) placement.
     */
    private function promoteWithDefaultPlacement(EnrollmentRolloverPlan $plan, School $school, GradeLevel $sourceGrade, GradeLevel $targetGrade, Section $targetSection, Student $student, string $rollNumber = '007'): void
    {
        $this->planService()->upsertMapping($plan, $sourceGrade, null, $targetGrade, $targetSection);
        $item = $this->itemFor($plan, $student);
        app(TenantContext::class)->withSchool($school, fn () => $item->update(['decision' => 'promote', 'roll_number_strategy' => 'explicit', 'target_roll_number' => $rollNumber]));
    }

    /**
     * Items only come into existence via the dry-run engine's own
     * population step (there is no standalone "populate" method) --
     * this fixture helper triggers exactly one throwaway
     * populate-and-validate pass the FIRST time it's asked for an item
     * that doesn't exist yet (so tests can configure a decision before
     * the "real", asserted run), then simply fetches on every
     * subsequent call (so a test asserting "nothing changed after an
     * aborted run" is not itself the thing that changes it).
     */
    private function itemFor(EnrollmentRolloverPlan $plan, Student $student): EnrollmentRolloverItem
    {
        $existing = app(TenantContext::class)->withSchool(
            $plan->school,
            fn () => EnrollmentRolloverItem::query()->where('plan_id', $plan->id)->where('student_id', $student->id)->first(),
        );

        if ($existing !== null) {
            return $existing;
        }

        $this->dryRun()->run($this->freshPlan($plan));

        return app(TenantContext::class)->withSchool(
            $plan->school,
            fn () => EnrollmentRolloverItem::query()->where('plan_id', $plan->id)->where('student_id', $student->id)->firstOrFail(),
        );
    }

    private function freshPlan(EnrollmentRolloverPlan $plan): EnrollmentRolloverPlan
    {
        return app(TenantContext::class)->withSchool($plan->school, fn () => $plan->fresh());
    }

    // ==================================================================
    // Plan-level gates
    // ==================================================================

    #[Test]
    public function dry_run_rejects_a_plan_that_is_executing_or_terminal(): void
    {
        ['plan' => $plan] = $this->buildContext();
        app(TenantContext::class)->withSchool($plan->school, fn () => $plan->update(['status' => 'executing']));

        $this->expectException(RolloverPlanNoLongerConfigurableException::class);
        $this->dryRun()->run($this->freshPlan($plan));
    }

    // ==================================================================
    // Section 73/74/75/76/77 -- source selection
    // ==================================================================

    #[Test]
    public function active_source_promotion_is_ready(): void
    {
        ['school' => $school, 'sourceGrade' => $sourceGrade, 'targetGrade' => $targetGrade, 'sourceSection' => $sourceSection, 'targetSection' => $targetSection, 'plan' => $plan] = $this->buildContext();
        $student = $this->createStudent($school, ['student_number' => 'S-1001']);
        $this->enrollmentService()->enroll($student, $sourceSection, '01', '2026-06-01');
        $this->planService()->upsertMapping($plan, $sourceGrade, null, $targetGrade, null);

        $item = $this->itemFor($plan, $student);
        app(TenantContext::class)->withSchool($school, fn () => $item->update(['decision' => 'promote', 'target_section_id' => $targetSection->id, 'roll_number_strategy' => 'explicit', 'target_roll_number' => '007']));

        $summary = $this->dryRun()->run($this->freshPlan($plan));

        $this->assertSame(1, $summary['ready']);
        $this->assertTrue($summary['validated']);
        $fresh = $this->itemFor($plan, $student);
        $this->assertSame('ready', $fresh->validation_result);
        $this->assertNull($fresh->validation_reason);
        $this->assertSame('active', $fresh->source_enrollment_status_snapshot);

        $count = app(TenantContext::class)->withSchool($school, fn () => StudentEnrollment::query()->where('student_id', $student->id)->count());
        $this->assertSame(1, $count, 'no target Enrollment may be created by dry-run');
    }

    #[Test]
    public function completed_source_is_eligible(): void
    {
        ['school' => $school, 'sourceGrade' => $sourceGrade, 'targetGrade' => $targetGrade, 'sourceSection' => $sourceSection, 'targetSection' => $targetSection, 'plan' => $plan] = $this->buildContext();
        $student = $this->createStudent($school, ['student_number' => 'S-1001']);
        $enrollment = $this->enrollmentService()->enroll($student, $sourceSection, '01', '2026-06-01');
        $this->enrollmentService()->complete($enrollment, '2027-04-30');
        $this->planService()->upsertMapping($plan, $sourceGrade, null, $targetGrade, null);
        $item = $this->itemFor($plan, $student);
        app(TenantContext::class)->withSchool($school, fn () => $item->update(['decision' => 'promote', 'target_section_id' => $targetSection->id, 'roll_number_strategy' => 'preserve_source']));

        $summary = $this->dryRun()->run($this->freshPlan($plan));

        $this->assertSame(1, $summary['ready']);
        $fresh = $this->itemFor($plan, $student);
        $this->assertSame('completed', $fresh->source_enrollment_status_snapshot);
    }

    #[Test]
    public function transfer_history_resolves_only_the_active_row(): void
    {
        ['school' => $school, 'campus' => $campus, 'sourceYear' => $sourceYear, 'sourceGrade' => $sourceGrade, 'sourceSection' => $sourceSectionA, 'plan' => $plan] = $this->buildContext();
        $sourceSectionB = $this->createSection($sourceYear, $campus, $sourceGrade, ['name' => '5B', 'code' => '5B']);
        $student = $this->createStudent($school, ['student_number' => 'S-1001']);
        $original = $this->enrollmentService()->enroll($student, $sourceSectionA, '01', '2026-06-01');
        $this->enrollmentService()->transferPlacement($original, $sourceSectionB, '02', '2026-07-01');

        $this->dryRun()->run($this->freshPlan($plan));

        $itemCount = app(TenantContext::class)->withSchool($school, fn () => EnrollmentRolloverItem::query()->where('plan_id', $plan->id)->where('student_id', $student->id)->count());
        $this->assertSame(1, $itemCount, 'dry-run must not generate two items for one Student');
        $item = $this->itemFor($plan, $student);
        $activeEnrollmentId = app(TenantContext::class)->withSchool($school, fn () => StudentEnrollment::query()->where('student_id', $student->id)->where('status', 'active')->firstOrFail()->id);
        $this->assertSame($activeEnrollmentId, $item->source_enrollment_id);
    }

    #[Test]
    public function ambiguous_source_is_review(): void
    {
        ['school' => $school, 'campus' => $campus, 'sourceYear' => $sourceYear, 'sourceGrade' => $sourceGrade, 'sourceSection' => $sourceSectionA, 'plan' => $plan] = $this->buildContext();
        $sourceSectionB = $this->createSection($sourceYear, $campus, $sourceGrade, ['name' => '5B', 'code' => '5B']);
        $student = $this->createStudent($school, ['student_number' => 'S-1001']);
        $first = $this->enrollmentService()->enroll($student, $sourceSectionA, '01', '2026-06-01');
        $this->enrollmentService()->complete($first, '2026-12-01');
        $this->enrollmentService()->enroll($student, $sourceSectionB, '02', '2026-12-02'); // 2nd row, same year, now active

        $this->dryRun()->run($this->freshPlan($plan));

        $item = $this->itemFor($plan, $student);
        $this->assertSame('review', $item->validation_result);
        $this->assertSame('multiple_source_candidates', $item->validation_reason);
    }

    #[Test]
    public function withdrawn_only_student_is_never_populated_into_the_plan(): void
    {
        ['school' => $school, 'sourceSection' => $sourceSection, 'plan' => $plan] = $this->buildContext();
        $student = $this->createStudent($school, ['student_number' => 'S-1001']);
        $enrollment = $this->enrollmentService()->enroll($student, $sourceSection, '01', '2026-06-01');
        $this->enrollmentService()->withdraw($enrollment, '2026-09-01');

        $this->dryRun()->run($this->freshPlan($plan));

        $exists = app(TenantContext::class)->withSchool($school, fn () => EnrollmentRolloverItem::query()->where('plan_id', $plan->id)->where('student_id', $student->id)->exists());
        $this->assertFalse($exists);
    }

    #[Test]
    public function cancelled_only_student_is_never_populated_into_the_plan(): void
    {
        ['school' => $school, 'sourceSection' => $sourceSection, 'plan' => $plan] = $this->buildContext();
        $student = $this->createStudent($school, ['student_number' => 'S-1001']);
        $enrollment = $this->enrollmentService()->enroll($student, $sourceSection, '01', '2026-06-01');
        $this->enrollmentService()->cancel($enrollment, '2026-06-05');

        $this->dryRun()->run($this->freshPlan($plan));

        $exists = app(TenantContext::class)->withSchool($school, fn () => EnrollmentRolloverItem::query()->where('plan_id', $plan->id)->where('student_id', $student->id)->exists());
        $this->assertFalse($exists);
    }

    // ==================================================================
    // Section 78/79 -- mapping/override precedence
    // ==================================================================

    #[Test]
    public function section_specific_mapping_takes_precedence_over_grade_default(): void
    {
        ['school' => $school, 'campus' => $campus, 'sourceYear' => $sourceYear, 'targetYear' => $targetYear, 'sourceGrade' => $sourceGrade, 'targetGrade' => $targetGrade, 'sourceSection' => $sourceSectionA, 'targetSection' => $targetSectionDefault, 'plan' => $plan] = $this->buildContext();
        $sourceSectionB = $this->createSection($sourceYear, $campus, $sourceGrade, ['name' => '5B', 'code' => '5B']);
        $targetSectionOverride = $this->createSection($targetYear, $campus, $targetGrade, ['name' => '6C', 'code' => '6C']);
        $studentA = $this->createStudent($school, ['student_number' => 'S-1001']);
        $studentB = $this->createStudent($school, ['student_number' => 'S-1002']);
        $this->enrollmentService()->enroll($studentA, $sourceSectionA, '01', '2026-06-01');
        $this->enrollmentService()->enroll($studentB, $sourceSectionB, '02', '2026-06-01');

        $this->planService()->upsertMapping($plan, $sourceGrade, null, $targetGrade, $targetSectionDefault);
        $this->planService()->upsertMapping($plan, $sourceGrade, $sourceSectionB, $targetGrade, $targetSectionOverride);

        foreach ([$studentA, $studentB] as $s) {
            $item = $this->itemFor($plan, $s);
            app(TenantContext::class)->withSchool($school, fn () => $item->update(['decision' => 'promote', 'roll_number_strategy' => 'preserve_source']));
        }

        $this->dryRun()->run($this->freshPlan($plan));

        $itemA = $this->itemFor($plan, $studentA);
        $itemB = $this->itemFor($plan, $studentB);
        $this->assertSame('ready', $itemA->validation_result);
        $this->assertSame('ready', $itemB->validation_result);
    }

    #[Test]
    public function item_target_section_override_takes_precedence_over_mapping_default(): void
    {
        ['school' => $school, 'campus' => $campus, 'targetYear' => $targetYear, 'sourceGrade' => $sourceGrade, 'targetGrade' => $targetGrade, 'sourceSection' => $sourceSection, 'targetSection' => $mappingDefaultTarget, 'plan' => $plan] = $this->buildContext();
        $overrideTarget = $this->createSection($targetYear, $campus, $targetGrade, ['name' => '6C', 'code' => '6C']);
        $student = $this->createStudent($school, ['student_number' => 'S-1001']);
        $this->enrollmentService()->enroll($student, $sourceSection, '01', '2026-06-01');
        $this->planService()->upsertMapping($plan, $sourceGrade, null, $targetGrade, $mappingDefaultTarget);

        $item = $this->itemFor($plan, $student);
        $this->planService()->setItemDecision($this->freshPlan($plan), $item, 'promote', $overrideTarget, 'preserve_source');

        $this->dryRun()->run($this->freshPlan($plan));

        $fresh = $this->itemFor($plan, $student);
        $this->assertSame('ready', $fresh->validation_result);
        $this->assertSame($overrideTarget->id, $fresh->target_section_id);
    }

    // ==================================================================
    // Section 80/81/82 -- promote/repeat decision validity
    // ==================================================================

    #[Test]
    public function repeat_decision_with_matching_grade_target_is_ready(): void
    {
        ['school' => $school, 'campus' => $campus, 'targetYear' => $targetYear, 'sourceGrade' => $sourceGrade, 'sourceSection' => $sourceSection, 'plan' => $plan] = $this->buildContext();
        $repeatTargetSection = $this->createSection($targetYear, $campus, $sourceGrade, ['name' => '5A', 'code' => '5A']);
        $student = $this->createStudent($school, ['student_number' => 'S-1001']);
        $this->enrollmentService()->enroll($student, $sourceSection, '01', '2026-06-01');
        $this->planService()->upsertMapping($plan, $sourceGrade, null, $sourceGrade, $repeatTargetSection);
        $item = $this->itemFor($plan, $student);
        app(TenantContext::class)->withSchool($school, fn () => $item->update(['decision' => 'repeat', 'roll_number_strategy' => 'preserve_source']));

        $this->dryRun()->run($this->freshPlan($plan));

        $fresh = $this->itemFor($plan, $student);
        $this->assertSame('ready', $fresh->validation_result);
    }

    #[Test]
    public function invalid_repeat_when_mapping_target_grade_differs_is_blocked(): void
    {
        ['school' => $school, 'sourceGrade' => $sourceGrade, 'targetGrade' => $targetGrade, 'sourceSection' => $sourceSection, 'targetSection' => $targetSection, 'plan' => $plan] = $this->buildContext();
        $student = $this->createStudent($school, ['student_number' => 'S-1001']);
        $this->enrollmentService()->enroll($student, $sourceSection, '01', '2026-06-01');
        $this->planService()->upsertMapping($plan, $sourceGrade, null, $targetGrade, $targetSection); // promotes, not repeat
        $item = $this->itemFor($plan, $student);
        app(TenantContext::class)->withSchool($school, fn () => $item->update(['decision' => 'repeat', 'roll_number_strategy' => 'preserve_source']));

        $this->dryRun()->run($this->freshPlan($plan));

        $fresh = $this->itemFor($plan, $student);
        $this->assertSame('blocked', $fresh->validation_result);
        $this->assertSame('target_grade_mismatch', $fresh->validation_reason);
    }

    #[Test]
    public function invalid_promote_when_mapping_target_grade_equals_source_is_blocked(): void
    {
        ['school' => $school, 'campus' => $campus, 'targetYear' => $targetYear, 'sourceGrade' => $sourceGrade, 'sourceSection' => $sourceSection, 'plan' => $plan] = $this->buildContext();
        $repeatTargetSection = $this->createSection($targetYear, $campus, $sourceGrade, ['name' => '5A', 'code' => '5A']);
        $student = $this->createStudent($school, ['student_number' => 'S-1001']);
        $this->enrollmentService()->enroll($student, $sourceSection, '01', '2026-06-01');
        $this->planService()->upsertMapping($plan, $sourceGrade, null, $sourceGrade, $repeatTargetSection); // repeat mapping
        $item = $this->itemFor($plan, $student);
        app(TenantContext::class)->withSchool($school, fn () => $item->update(['decision' => 'promote', 'roll_number_strategy' => 'preserve_source']));

        $this->dryRun()->run($this->freshPlan($plan));

        $fresh = $this->itemFor($plan, $student);
        $this->assertSame('blocked', $fresh->validation_result);
        $this->assertSame('target_grade_mismatch', $fresh->validation_reason);
    }

    // ==================================================================
    // Section 83/84 -- terminal / missing mapping
    // ==================================================================

    #[Test]
    public function terminal_grade_undecided_item_is_review_with_terminal_reason(): void
    {
        ['school' => $school, 'sourceSection' => $sourceSection, 'plan' => $plan] = $this->buildContext();
        $student = $this->createStudent($school, ['student_number' => 'S-1001']);
        $this->enrollmentService()->enroll($student, $sourceSection, '01', '2026-06-01');
        // no mapping created at all for the source Grade

        $this->dryRun()->run($this->freshPlan($plan));

        $item = $this->itemFor($plan, $student);
        $this->assertSame('review', $item->validation_result);
        $this->assertSame('terminal_grade', $item->validation_reason);
    }

    #[Test]
    public function terminal_grade_excluded_item_is_non_blocking(): void
    {
        ['school' => $school, 'sourceSection' => $sourceSection, 'plan' => $plan] = $this->buildContext();
        $student = $this->createStudent($school, ['student_number' => 'S-1001']);
        $this->enrollmentService()->enroll($student, $sourceSection, '01', '2026-06-01');
        $item = $this->itemFor($plan, $student);
        $this->planService()->setItemDecision($this->freshPlan($plan), $item, 'exclude');

        $summary = $this->dryRun()->run($this->freshPlan($plan));

        $fresh = $this->itemFor($plan, $student);
        $this->assertSame('excluded', $fresh->validation_result);
        $this->assertTrue($summary['validated'], 'an excluded item must not block Plan validation');
    }

    #[Test]
    public function promote_without_any_mapping_is_blocked_missing_mapping(): void
    {
        ['school' => $school, 'sourceSection' => $sourceSection, 'plan' => $plan] = $this->buildContext();
        $student = $this->createStudent($school, ['student_number' => 'S-1001']);
        $this->enrollmentService()->enroll($student, $sourceSection, '01', '2026-06-01');
        $item = $this->itemFor($plan, $student);
        app(TenantContext::class)->withSchool($school, fn () => $item->update(['decision' => 'promote']));

        $this->dryRun()->run($this->freshPlan($plan));

        $fresh = $this->itemFor($plan, $student);
        $this->assertSame('blocked', $fresh->validation_result);
        $this->assertSame('missing_mapping', $fresh->validation_reason);
    }

    // ==================================================================
    // Section 85/86 -- wrong target year / grade mismatch via Section
    // ==================================================================

    #[Test]
    public function target_section_in_a_different_year_is_blocked_even_same_school(): void
    {
        ['school' => $school, 'campus' => $campus, 'sourceGrade' => $sourceGrade, 'targetGrade' => $targetGrade, 'sourceSection' => $sourceSection, 'plan' => $plan] = $this->buildContext();
        $wrongYear = $this->createAcademicYear($school, ['code' => 'WRONG', 'starts_on' => '2029-06-01', 'ends_on' => '2030-04-30']);
        $wrongYearSection = $this->createSection($wrongYear, $campus, $targetGrade);
        $student = $this->createStudent($school, ['student_number' => 'S-1001']);
        $this->enrollmentService()->enroll($student, $sourceSection, '01', '2026-06-01');
        $this->planService()->upsertMapping($plan, $sourceGrade, null, $targetGrade, $wrongYearSection);
        $item = $this->itemFor($plan, $student);
        app(TenantContext::class)->withSchool($school, fn () => $item->update(['decision' => 'promote', 'roll_number_strategy' => 'preserve_source']));

        $this->dryRun()->run($this->freshPlan($plan));

        $fresh = $this->itemFor($plan, $student);
        $this->assertSame('blocked', $fresh->validation_result);
        $this->assertSame('target_wrong_academic_year', $fresh->validation_reason);
    }

    #[Test]
    public function target_section_belonging_to_a_different_grade_than_the_mapping_is_blocked(): void
    {
        ['school' => $school, 'campus' => $campus, 'targetYear' => $targetYear, 'sourceGrade' => $sourceGrade, 'targetGrade' => $targetGrade, 'sourceSection' => $sourceSection, 'plan' => $plan] = $this->buildContext();
        $otherGrade = $this->createGradeLevel($school, ['name' => 'Grade 7', 'code' => 'G7', 'sequence' => 7]);
        $wrongGradeSection = $this->createSection($targetYear, $campus, $otherGrade);
        $student = $this->createStudent($school, ['student_number' => 'S-1001']);
        $this->enrollmentService()->enroll($student, $sourceSection, '01', '2026-06-01');
        // mapping SAYS target grade 6, but the item's target Section actually belongs to Grade 7
        $this->planService()->upsertMapping($plan, $sourceGrade, null, $targetGrade, null);
        $item = $this->itemFor($plan, $student);
        $this->planService()->setItemDecision($this->freshPlan($plan), $item, 'promote', $wrongGradeSection, 'preserve_source');

        $this->dryRun()->run($this->freshPlan($plan));

        $fresh = $this->itemFor($plan, $student);
        $this->assertSame('blocked', $fresh->validation_result);
        $this->assertSame('target_grade_mismatch', $fresh->validation_reason);
    }

    // ==================================================================
    // Section 87/88 -- Roll Number
    // ==================================================================

    #[Test]
    public function preserve_source_roll_number_round_trips_exactly(): void
    {
        ['school' => $school, 'sourceGrade' => $sourceGrade, 'targetGrade' => $targetGrade, 'sourceSection' => $sourceSection, 'targetSection' => $targetSection, 'plan' => $plan] = $this->buildContext();
        $student = $this->createStudent($school, ['student_number' => 'S-1001']);
        $this->enrollmentService()->enroll($student, $sourceSection, '007', '2026-06-01');
        $this->planService()->upsertMapping($plan, $sourceGrade, null, $targetGrade, $targetSection);
        $item = $this->itemFor($plan, $student);
        $this->planService()->setItemDecision($this->freshPlan($plan), $item, 'promote', null, 'preserve_source');

        $this->dryRun()->run($this->freshPlan($plan));

        $fresh = $this->itemFor($plan, $student);
        $this->assertSame('ready', $fresh->validation_result);
    }

    #[Test]
    public function explicit_roll_number_is_normalized_like_the_enrollment_service_contract(): void
    {
        ['school' => $school, 'sourceGrade' => $sourceGrade, 'targetGrade' => $targetGrade, 'sourceSection' => $sourceSection, 'targetSection' => $targetSection, 'plan' => $plan] = $this->buildContext();
        $student = $this->createStudent($school, ['student_number' => 'S-1001']);
        $this->enrollmentService()->enroll($student, $sourceSection, '01', '2026-06-01');
        $this->planService()->upsertMapping($plan, $sourceGrade, null, $targetGrade, $targetSection);
        $item = $this->itemFor($plan, $student);
        $this->planService()->setItemDecision($this->freshPlan($plan), $item, 'promote', null, 'explicit', ' 009 ');

        $this->dryRun()->run($this->freshPlan($plan));

        $fresh = $this->itemFor($plan, $student);
        $this->assertSame('ready', $fresh->validation_result);
    }

    #[Test]
    public function blank_explicit_roll_number_is_blocked_invalid(): void
    {
        ['school' => $school, 'sourceGrade' => $sourceGrade, 'targetGrade' => $targetGrade, 'sourceSection' => $sourceSection, 'targetSection' => $targetSection, 'plan' => $plan] = $this->buildContext();
        $student = $this->createStudent($school, ['student_number' => 'S-1001']);
        $this->enrollmentService()->enroll($student, $sourceSection, '01', '2026-06-01');
        $this->planService()->upsertMapping($plan, $sourceGrade, null, $targetGrade, $targetSection);
        $item = $this->itemFor($plan, $student);
        $this->planService()->setItemDecision($this->freshPlan($plan), $item, 'promote', null, 'explicit', '   ');

        $this->dryRun()->run($this->freshPlan($plan));

        $fresh = $this->itemFor($plan, $student);
        $this->assertSame('blocked', $fresh->validation_result);
        $this->assertSame('invalid_roll_number', $fresh->validation_reason);
    }

    // ==================================================================
    // Section 89/90/91 -- conflict detection
    // ==================================================================

    #[Test]
    public function in_plan_duplicate_roll_number_blocks_both_students_deterministically(): void
    {
        ['school' => $school, 'campus' => $campus, 'sourceYear' => $sourceYear, 'sourceGrade' => $sourceGrade, 'targetGrade' => $targetGrade, 'sourceSection' => $sourceSectionA, 'targetSection' => $targetSection, 'plan' => $plan] = $this->buildContext();
        $sourceSectionB = $this->createSection($sourceYear, $campus, $sourceGrade, ['name' => '5B', 'code' => '5B']);
        $studentA = $this->createStudent($school, ['student_number' => 'S-1001']);
        $studentB = $this->createStudent($school, ['student_number' => 'S-1002']);
        $this->enrollmentService()->enroll($studentA, $sourceSectionA, '01', '2026-06-01');
        $this->enrollmentService()->enroll($studentB, $sourceSectionB, '02', '2026-06-01');
        $this->planService()->upsertMapping($plan, $sourceGrade, null, $targetGrade, $targetSection);

        foreach ([$studentA, $studentB] as $s) {
            $item = $this->itemFor($plan, $s);
            $this->planService()->setItemDecision($this->freshPlan($plan), $item, 'promote', null, 'explicit', '007');
        }

        $this->dryRun()->run($this->freshPlan($plan));

        $itemA = $this->itemFor($plan, $studentA);
        $itemB = $this->itemFor($plan, $studentB);
        $this->assertSame('blocked', $itemA->validation_result);
        $this->assertSame('roll_number_conflict_in_plan', $itemA->validation_reason);
        $this->assertSame('blocked', $itemB->validation_result);
        $this->assertSame('roll_number_conflict_in_plan', $itemB->validation_reason);
    }

    #[Test]
    public function existing_other_student_roll_conflict_is_blocked(): void
    {
        ['school' => $school, 'sourceGrade' => $sourceGrade, 'targetGrade' => $targetGrade, 'sourceSection' => $sourceSection, 'targetSection' => $targetSection, 'plan' => $plan] = $this->buildContext();
        $studentX = $this->createStudent($school, ['student_number' => 'S-9999']);
        $this->enrollmentService()->enroll($studentX, $targetSection, '007', '2027-06-01'); // pre-existing, unrelated to the plan

        $studentY = $this->createStudent($school, ['student_number' => 'S-1001']);
        $this->enrollmentService()->enroll($studentY, $sourceSection, '01', '2026-06-01');
        $this->planService()->upsertMapping($plan, $sourceGrade, null, $targetGrade, $targetSection);
        $item = $this->itemFor($plan, $studentY);
        $this->planService()->setItemDecision($this->freshPlan($plan), $item, 'promote', null, 'explicit', '007');

        $this->dryRun()->run($this->freshPlan($plan));

        $fresh = $this->itemFor($plan, $studentY);
        $this->assertSame('blocked', $fresh->validation_result);
        $this->assertSame('roll_number_conflict_existing', $fresh->validation_reason);
    }

    #[Test]
    public function same_roll_number_in_different_target_sections_is_not_a_conflict(): void
    {
        ['school' => $school, 'campus' => $campus, 'sourceYear' => $sourceYear, 'targetYear' => $targetYear, 'sourceGrade' => $sourceGrade, 'targetGrade' => $targetGrade, 'sourceSection' => $sourceSectionA, 'targetSection' => $targetSectionA, 'plan' => $plan] = $this->buildContext();
        $sourceSectionB = $this->createSection($sourceYear, $campus, $sourceGrade, ['name' => '5B', 'code' => '5B']);
        $targetSectionC = $this->createSection($targetYear, $campus, $targetGrade, ['name' => '6C', 'code' => '6C']);
        $studentA = $this->createStudent($school, ['student_number' => 'S-1001']);
        $studentB = $this->createStudent($school, ['student_number' => 'S-1002']);
        $this->enrollmentService()->enroll($studentA, $sourceSectionA, '01', '2026-06-01');
        $this->enrollmentService()->enroll($studentB, $sourceSectionB, '02', '2026-06-01');
        $this->planService()->upsertMapping($plan, $sourceGrade, $sourceSectionA, $targetGrade, $targetSectionA);
        $this->planService()->upsertMapping($plan, $sourceGrade, $sourceSectionB, $targetGrade, $targetSectionC);

        foreach ([$studentA, $studentB] as $s) {
            $item = $this->itemFor($plan, $s);
            $this->planService()->setItemDecision($this->freshPlan($plan), $item, 'promote', null, 'explicit', '007');
        }

        $this->dryRun()->run($this->freshPlan($plan));

        $this->assertSame('ready', $this->itemFor($plan, $studentA)->validation_result);
        $this->assertSame('ready', $this->itemFor($plan, $studentB)->validation_result);
    }

    // ==================================================================
    // Section 92/93 -- already enrolled
    // ==================================================================

    #[Test]
    public function already_enrolled_exact_match_is_non_blocking_no_op(): void
    {
        ['school' => $school, 'sourceGrade' => $sourceGrade, 'targetGrade' => $targetGrade, 'sourceSection' => $sourceSection, 'targetSection' => $targetSection, 'plan' => $plan] = $this->buildContext();
        $student = $this->createStudent($school, ['student_number' => 'S-1001']);
        $this->enrollmentService()->enroll($student, $sourceSection, '01', '2026-06-01');
        $this->enrollmentService()->enroll($student, $targetSection, '007', '2027-06-01'); // already enrolled in target year

        $this->planService()->upsertMapping($plan, $sourceGrade, null, $targetGrade, $targetSection);
        $item = $this->itemFor($plan, $student);
        $this->planService()->setItemDecision($this->freshPlan($plan), $item, 'promote', null, 'explicit', '007');

        $summary = $this->dryRun()->run($this->freshPlan($plan));

        $fresh = $this->itemFor($plan, $student);
        $this->assertSame('already_enrolled', $fresh->validation_result);
        $this->assertSame('already_enrolled_match', $fresh->validation_reason);
        $this->assertNull($fresh->target_enrollment_id, 'dry-run never sets target_enrollment_id -- see class docblock');
        $this->assertTrue($summary['validated'], 'an already-enrolled match must not block Plan validation');
    }

    #[Test]
    public function already_enrolled_with_a_different_placement_is_blocked_conflict(): void
    {
        ['school' => $school, 'campus' => $campus, 'targetYear' => $targetYear, 'sourceGrade' => $sourceGrade, 'targetGrade' => $targetGrade, 'sourceSection' => $sourceSection, 'targetSection' => $targetSection, 'plan' => $plan] = $this->buildContext();
        $otherTargetSection = $this->createSection($targetYear, $campus, $targetGrade, ['name' => '6C', 'code' => '6C']);
        $student = $this->createStudent($school, ['student_number' => 'S-1001']);
        $this->enrollmentService()->enroll($student, $sourceSection, '01', '2026-06-01');
        $this->enrollmentService()->enroll($student, $otherTargetSection, '099', '2027-06-01'); // different Section+Roll already active

        $this->planService()->upsertMapping($plan, $sourceGrade, null, $targetGrade, $targetSection);
        $item = $this->itemFor($plan, $student);
        $this->planService()->setItemDecision($this->freshPlan($plan), $item, 'promote', null, 'explicit', '007');

        $this->dryRun()->run($this->freshPlan($plan));

        $fresh = $this->itemFor($plan, $student);
        $this->assertSame('blocked', $fresh->validation_result);
        $this->assertSame('already_enrolled_conflict', $fresh->validation_reason);
    }

    // ==================================================================
    // Section 94/95/96 -- summary and plan readiness
    // ==================================================================

    #[Test]
    public function plan_summary_counts_every_result_category_exactly_once(): void
    {
        ['school' => $school, 'campus' => $campus, 'sourceYear' => $sourceYear, 'targetYear' => $targetYear, 'sourceGrade' => $sourceGrade, 'targetGrade' => $targetGrade, 'sourceSection' => $sourceSection, 'targetSection' => $targetSection, 'plan' => $plan] = $this->buildContext();

        $readyStudent = $this->createStudent($school, ['student_number' => 'S-0001']);
        $this->enrollmentService()->enroll($readyStudent, $sourceSection, '01', '2026-06-01');

        $excludedStudent = $this->createStudent($school, ['student_number' => 'S-0002']);
        $this->enrollmentService()->enroll($excludedStudent, $sourceSection, '02', '2026-06-01');

        $alreadyEnrolledStudent = $this->createStudent($school, ['student_number' => 'S-0003']);
        $this->enrollmentService()->enroll($alreadyEnrolledStudent, $sourceSection, '03', '2026-06-01');
        $this->enrollmentService()->enroll($alreadyEnrolledStudent, $targetSection, '033', '2027-06-01');

        $reviewStudent = $this->createStudent($school, ['student_number' => 'S-0004']);
        $this->enrollmentService()->enroll($reviewStudent, $sourceSection, '04', '2026-06-01'); // stays undecided -> review

        $blockedStudent = $this->createStudent($school, ['student_number' => 'S-0005']);
        $this->enrollmentService()->enroll($blockedStudent, $sourceSection, '05', '2026-06-01');

        $this->planService()->upsertMapping($plan, $sourceGrade, null, $targetGrade, $targetSection);

        $this->planService()->setItemDecision($this->freshPlan($plan), $this->itemFor($plan, $readyStudent), 'promote', null, 'explicit', '101');
        $this->planService()->setItemDecision($this->freshPlan($plan), $this->itemFor($plan, $excludedStudent), 'exclude');
        $this->planService()->setItemDecision($this->freshPlan($plan), $this->itemFor($plan, $alreadyEnrolledStudent), 'promote', null, 'explicit', '033');
        // reviewStudent's item is left undecided deliberately.
        $this->planService()->setItemDecision($this->freshPlan($plan), $this->itemFor($plan, $blockedStudent), 'promote', null, 'explicit', '   '); // blank after trim -> blocked/invalid_roll_number

        $summary = $this->dryRun()->run($this->freshPlan($plan));

        $this->assertSame(5, $summary['total']);
        $this->assertSame(1, $summary['ready']);
        $this->assertSame(1, $summary['excluded']);
        $this->assertSame(1, $summary['already_enrolled']);
        $this->assertSame(1, $summary['review']);
        $this->assertSame(1, $summary['blocked']);
        $this->assertFalse($summary['validated']);
    }

    #[Test]
    public function plan_becomes_validated_when_every_item_is_ready_excluded_or_already_enrolled(): void
    {
        ['school' => $school, 'sourceGrade' => $sourceGrade, 'targetGrade' => $targetGrade, 'sourceSection' => $sourceSection, 'targetSection' => $targetSection, 'plan' => $plan] = $this->buildContext();
        $student = $this->createStudent($school, ['student_number' => 'S-1001']);
        $this->enrollmentService()->enroll($student, $sourceSection, '01', '2026-06-01');
        $this->planService()->upsertMapping($plan, $sourceGrade, null, $targetGrade, $targetSection);
        $this->planService()->setItemDecision($this->freshPlan($plan), $this->itemFor($plan, $student), 'promote', null, 'explicit', '007');

        $summary = $this->dryRun()->run($this->freshPlan($plan));

        $this->assertTrue($summary['validated']);
        $freshPlan = $this->freshPlan($plan);
        $this->assertSame('validated', $freshPlan->status);
        $this->assertSame($freshPlan->configuration_version, $freshPlan->validated_configuration_version);
        $this->assertNotNull($freshPlan->validated_at);
    }

    #[Test]
    public function plan_does_not_validate_while_any_item_is_blocked_or_review(): void
    {
        ['school' => $school, 'sourceSection' => $sourceSection, 'plan' => $plan] = $this->buildContext();
        $student = $this->createStudent($school, ['student_number' => 'S-1001']);
        $this->enrollmentService()->enroll($student, $sourceSection, '01', '2026-06-01');
        // no mapping, decision left undecided -> review

        $summary = $this->dryRun()->run($this->freshPlan($plan));

        $this->assertFalse($summary['validated']);
        $freshPlan = $this->freshPlan($plan);
        $this->assertSame('draft', $freshPlan->status);
        $this->assertNull($freshPlan->validated_configuration_version);
    }

    // ==================================================================
    // Section 97 -- configuration-version race
    // ==================================================================

    #[Test]
    public function a_configuration_change_during_validation_aborts_persistence(): void
    {
        ['school' => $school, 'sourceGrade' => $sourceGrade, 'targetGrade' => $targetGrade, 'sourceSection' => $sourceSection, 'targetSection' => $targetSection, 'plan' => $plan] = $this->buildContext();
        $student = $this->createStudent($school, ['student_number' => 'S-1001']);
        $this->enrollmentService()->enroll($student, $sourceSection, '01', '2026-06-01');
        $this->planService()->upsertMapping($plan, $sourceGrade, null, $targetGrade, $targetSection);
        $this->planService()->setItemDecision($this->freshPlan($plan), $this->itemFor($plan, $student), 'promote', null, 'explicit', '007');

        $freshPlan = $this->freshPlan($plan);
        $otherGrade = $this->createGradeLevel($school, ['name' => 'Grade 8', 'code' => 'G8', 'sequence' => 8]);

        $this->expectException(StaleRolloverConfigurationException::class);
        $this->dryRun()->run($freshPlan, null, function () use ($freshPlan, $sourceGrade, $otherGrade) {
            // Simulates a concurrent operator edit landing between
            // calculation and persistence.
            $this->planService()->upsertMapping($freshPlan, $sourceGrade, null, $otherGrade, null);
        });

        // Nothing from the ABORTED run's calculation was persisted --
        // the Item still reflects the throwaway populate pass from the
        // first itemFor() call above (decision was still 'undecided'
        // at that moment), never the 'ready' result the aborted run
        // would have computed for the NOW-configured 'promote' decision.
        $fresh = $this->itemFor($plan, $student);
        $this->assertSame('review', $fresh->validation_result);
        $this->assertSame('undecided', $fresh->validation_reason);
        $this->assertSame('draft', $this->freshPlan($plan)->status);
    }

    // ==================================================================
    // Section 67-72 -- idempotency, revalidation, staleness
    // ==================================================================

    #[Test]
    public function running_dry_run_twice_with_no_changes_is_idempotent(): void
    {
        ['school' => $school, 'sourceGrade' => $sourceGrade, 'targetGrade' => $targetGrade, 'sourceSection' => $sourceSection, 'targetSection' => $targetSection, 'plan' => $plan] = $this->buildContext();
        $student = $this->createStudent($school, ['student_number' => 'S-1001']);
        $this->enrollmentService()->enroll($student, $sourceSection, '01', '2026-06-01');
        $this->planService()->upsertMapping($plan, $sourceGrade, null, $targetGrade, $targetSection);
        $this->planService()->setItemDecision($this->freshPlan($plan), $this->itemFor($plan, $student), 'promote', null, 'explicit', '007');

        $first = $this->dryRun()->run($this->freshPlan($plan));
        $versionAfterFirst = $this->freshPlan($plan)->configuration_version;
        $second = $this->dryRun()->run($this->freshPlan($plan));
        $versionAfterSecond = $this->freshPlan($plan)->configuration_version;

        $this->assertEquals($first, $second);
        $this->assertSame($versionAfterFirst, $versionAfterSecond, 'validation must never increment configuration_version');

        $itemCount = app(TenantContext::class)->withSchool($school, fn () => EnrollmentRolloverItem::query()->where('plan_id', $plan->id)->count());
        $this->assertSame(1, $itemCount, 'no duplicate items from re-running dry-run');
    }

    #[Test]
    public function a_source_transfer_after_first_dry_run_is_detected_on_revalidation(): void
    {
        ['school' => $school, 'campus' => $campus, 'sourceYear' => $sourceYear, 'sourceGrade' => $sourceGrade, 'targetGrade' => $targetGrade, 'sourceSection' => $sourceSectionA, 'targetSection' => $targetSection, 'plan' => $plan] = $this->buildContext();
        $sourceSectionB = $this->createSection($sourceYear, $campus, $sourceGrade, ['name' => '5B', 'code' => '5B']);
        $student = $this->createStudent($school, ['student_number' => 'S-1001']);
        $enrollment = $this->enrollmentService()->enroll($student, $sourceSectionA, '01', '2026-06-01');
        $this->planService()->upsertMapping($plan, $sourceGrade, null, $targetGrade, $targetSection);
        $this->planService()->setItemDecision($this->freshPlan($plan), $this->itemFor($plan, $student), 'promote', null, 'explicit', '007');

        $firstSummary = $this->dryRun()->run($this->freshPlan($plan));
        $this->assertTrue($firstSummary['validated']);

        $this->enrollmentService()->transferPlacement($enrollment, $sourceSectionB, '02', '2026-07-01');

        $secondSummary = $this->dryRun()->run($this->freshPlan($plan));

        $this->assertFalse($secondSummary['validated']);
        $fresh = $this->itemFor($plan, $student);
        $this->assertSame('blocked', $fresh->validation_result);
        $this->assertSame('source_status_ineligible', $fresh->validation_reason);
    }

    #[Test]
    public function a_manually_created_target_enrollment_after_dry_run_is_detected_on_revalidation(): void
    {
        ['school' => $school, 'sourceGrade' => $sourceGrade, 'targetGrade' => $targetGrade, 'sourceSection' => $sourceSection, 'targetSection' => $targetSection, 'plan' => $plan] = $this->buildContext();
        $student = $this->createStudent($school, ['student_number' => 'S-1001']);
        $this->enrollmentService()->enroll($student, $sourceSection, '01', '2026-06-01');
        $this->planService()->upsertMapping($plan, $sourceGrade, null, $targetGrade, $targetSection);
        $this->planService()->setItemDecision($this->freshPlan($plan), $this->itemFor($plan, $student), 'promote', null, 'explicit', '007');

        $this->dryRun()->run($this->freshPlan($plan));

        // Staff manually enrolls the Student into the target year through
        // the ordinary, already-sanctioned Enrollment path.
        $this->enrollmentService()->enroll($student, $targetSection, '007', '2027-06-01');

        $summary = $this->dryRun()->run($this->freshPlan($plan));

        $this->assertTrue($summary['validated'], 'the manual Enrollment matches the proposal exactly, so it is a non-blocking no-op');
        $fresh = $this->itemFor($plan, $student);
        $this->assertSame('already_enrolled', $fresh->validation_result);

        $count = app(TenantContext::class)->withSchool($school, fn () => StudentEnrollment::query()->where('student_id', $student->id)->count());
        $this->assertSame(2, $count, 'no duplicate target Enrollment created');
    }

    #[Test]
    public function a_roll_number_occupied_by_another_student_after_dry_run_is_detected_on_revalidation(): void
    {
        ['school' => $school, 'sourceGrade' => $sourceGrade, 'targetGrade' => $targetGrade, 'sourceSection' => $sourceSection, 'targetSection' => $targetSection, 'plan' => $plan] = $this->buildContext();
        $student = $this->createStudent($school, ['student_number' => 'S-1001']);
        $this->enrollmentService()->enroll($student, $sourceSection, '01', '2026-06-01');
        $this->planService()->upsertMapping($plan, $sourceGrade, null, $targetGrade, $targetSection);
        $this->planService()->setItemDecision($this->freshPlan($plan), $this->itemFor($plan, $student), 'promote', null, 'explicit', '007');

        $firstSummary = $this->dryRun()->run($this->freshPlan($plan));
        $this->assertTrue($firstSummary['validated']);

        $otherStudent = $this->createStudent($school, ['student_number' => 'S-9999']);
        $this->enrollmentService()->enroll($otherStudent, $targetSection, '007', '2027-06-01');

        $secondSummary = $this->dryRun()->run($this->freshPlan($plan));

        $this->assertFalse($secondSummary['validated']);
        $fresh = $this->itemFor($plan, $student);
        $this->assertSame('blocked', $fresh->validation_result);
        $this->assertSame('roll_number_conflict_existing', $fresh->validation_reason);
    }

    #[Test]
    public function changing_a_mapping_after_validation_invalidates_it(): void
    {
        ['school' => $school, 'sourceGrade' => $sourceGrade, 'targetGrade' => $targetGrade, 'sourceSection' => $sourceSection, 'targetSection' => $targetSection, 'plan' => $plan] = $this->buildContext();
        $student = $this->createStudent($school, ['student_number' => 'S-1001']);
        $this->enrollmentService()->enroll($student, $sourceSection, '01', '2026-06-01');
        $this->planService()->upsertMapping($plan, $sourceGrade, null, $targetGrade, $targetSection);
        $this->planService()->setItemDecision($this->freshPlan($plan), $this->itemFor($plan, $student), 'promote', null, 'explicit', '007');
        $this->dryRun()->run($this->freshPlan($plan));
        $this->assertSame('validated', $this->freshPlan($plan)->status);

        $versionBefore = $this->freshPlan($plan)->configuration_version;
        $this->planService()->upsertMapping($this->freshPlan($plan), $sourceGrade, null, $targetGrade, null); // remove the default target Section
        $afterEdit = $this->freshPlan($plan);

        $this->assertGreaterThan($versionBefore, $afterEdit->configuration_version);
        $this->assertFalse($afterEdit->isValidatedForCurrentConfiguration());

        $summary = $this->dryRun()->run($afterEdit);
        $this->assertFalse($summary['validated'], 'the item now has no target Section at all (override was never set)');
        $fresh = $this->itemFor($plan, $student);
        $this->assertSame('blocked', $fresh->validation_result);
        $this->assertSame('missing_target_section', $fresh->validation_reason);
    }

    // ==================================================================
    // Configuration mutation guards
    // ==================================================================

    #[Test]
    public function set_item_decision_rejects_an_invalid_decision_value(): void
    {
        ['school' => $school, 'sourceSection' => $sourceSection, 'plan' => $plan] = $this->buildContext();
        $student = $this->createStudent($school, ['student_number' => 'S-1001']);
        $this->enrollmentService()->enroll($student, $sourceSection, '01', '2026-06-01');
        $this->dryRun()->run($this->freshPlan($plan)); // populate the item
        $item = $this->itemFor($plan, $student);

        $this->expectException(InvalidRolloverItemDecisionException::class);
        $this->planService()->setItemDecision($this->freshPlan($plan), $item, 'promoted');
    }

    #[Test]
    public function set_item_decision_rejects_an_invalid_roll_number_strategy(): void
    {
        ['school' => $school, 'sourceSection' => $sourceSection, 'plan' => $plan] = $this->buildContext();
        $student = $this->createStudent($school, ['student_number' => 'S-1001']);
        $this->enrollmentService()->enroll($student, $sourceSection, '01', '2026-06-01');
        $this->dryRun()->run($this->freshPlan($plan));
        $item = $this->itemFor($plan, $student);

        $this->expectException(InvalidRollNumberStrategyException::class);
        $this->planService()->setItemDecision($this->freshPlan($plan), $item, 'promote', null, 'auto_generate');
    }

    #[Test]
    public function configuration_mutation_is_rejected_once_the_plan_is_executing(): void
    {
        ['plan' => $plan] = $this->buildContext();
        app(TenantContext::class)->withSchool($plan->school, fn () => $plan->update(['status' => 'executing']));

        $this->expectException(RolloverPlanNoLongerConfigurableException::class);
        $this->planService()->upsertMapping($this->freshPlan($plan), $this->createGradeLevel($plan->school), null, $this->createGradeLevel($plan->school), null);
    }

    // ==================================================================
    // Zero academic-state mutation (merge-blocking invariant, section 66)
    // ==================================================================

    #[Test]
    public function dry_run_changes_zero_rows_in_every_academic_table(): void
    {
        ['school' => $school, 'sourceGrade' => $sourceGrade, 'targetGrade' => $targetGrade, 'sourceSection' => $sourceSection, 'targetSection' => $targetSection, 'plan' => $plan] = $this->buildContext();
        $student = $this->createStudent($school, ['student_number' => 'S-1001']);
        $this->enrollmentService()->enroll($student, $sourceSection, '01', '2026-06-01');
        $this->planService()->upsertMapping($plan, $sourceGrade, null, $targetGrade, $targetSection);
        $this->planService()->setItemDecision($this->freshPlan($plan), $this->itemFor($plan, $student), 'promote', null, 'explicit', '007');

        $fingerprint = fn () => app(TenantContext::class)->withSchool($school, fn () => [
            'students' => DB::table('students')->orderBy('id')->get()->toArray(),
            'student_enrollments' => DB::table('student_enrollments')->orderBy('id')->get()->toArray(),
            'academic_years' => DB::table('academic_years')->orderBy('id')->get()->toArray(),
            'grade_levels' => DB::table('grade_levels')->orderBy('id')->get()->toArray(),
            'sections' => DB::table('sections')->orderBy('id')->get()->toArray(),
            'campuses' => DB::table('campuses')->orderBy('id')->get()->toArray(),
        ]);

        $before = $fingerprint();
        $this->dryRun()->run($this->freshPlan($plan));
        $after = $fingerprint();

        $this->assertEquals($before, $after, 'dry-run must not change any academic-state row');
    }

    // ==================================================================
    // Cross-School (section 99)
    // ==================================================================

    #[Test]
    public function upsert_mapping_rejects_a_foreign_school_grade_level(): void
    {
        ['plan' => $plan] = $this->buildContext();
        $otherSchool = $this->createSchool();
        $foreignGrade = $this->createGradeLevel($otherSchool);

        $this->expectException(CrossSchoolRolloverPlanException::class);
        $this->planService()->upsertMapping($plan, $foreignGrade, null, $foreignGrade, null);
    }

    // ==================================================================
    // Phase 1G.2 -- subject rollover mapping dry-run integration
    // ==================================================================

    #[Test]
    public function subject_mapping_basic_map_is_ready_with_zero_target_subject_writes(): void
    {
        ['school' => $school, 'sourceGrade' => $sourceGrade, 'targetGrade' => $targetGrade, 'sourceSection' => $sourceSection, 'targetSection' => $targetSection, 'plan' => $plan, 'sourceOffering' => $sourceOffering, 'targetOffering' => $targetOffering] = $this->buildSubjectContext();
        $student = $this->createStudent($school, ['student_number' => 'S-1001']);
        $this->enrollmentService()->enroll($student, $sourceSection, '01', '2026-06-01');
        $this->subjectEnrollmentService()->enroll($student, $sourceOffering, '2026-06-01');
        $this->planService()->upsertSubjectMapping($plan, $sourceOffering, $targetOffering);
        $this->promoteWithDefaultPlacement($plan, $school, $sourceGrade, $targetGrade, $targetSection, $student);

        $summary = $this->dryRun()->run($this->freshPlan($plan));

        $this->assertSame(1, $summary['ready']);
        $this->assertTrue($summary['validated']);
        $fresh = $this->itemFor($plan, $student);
        $this->assertSame('ready', $fresh->validation_result);
        $this->assertNull($fresh->validation_reason);

        $count = app(TenantContext::class)->withSchool($school, fn () => StudentSubjectEnrollment::query()->where('student_id', $student->id)->count());
        $this->assertSame(1, $count, 'dry-run must not create a target subject enrollment -- only the pre-existing source row may exist');
    }

    #[Test]
    public function subject_mapping_explicit_omit_is_non_blocking(): void
    {
        ['school' => $school, 'sourceGrade' => $sourceGrade, 'targetGrade' => $targetGrade, 'sourceSection' => $sourceSection, 'targetSection' => $targetSection, 'plan' => $plan, 'sourceOffering' => $sourceOffering] = $this->buildSubjectContext();
        $student = $this->createStudent($school, ['student_number' => 'S-1001']);
        $this->enrollmentService()->enroll($student, $sourceSection, '01', '2026-06-01');
        $this->subjectEnrollmentService()->enroll($student, $sourceOffering, '2026-06-01');
        $this->planService()->upsertSubjectMapping($plan, $sourceOffering, null); // explicit omit
        $this->promoteWithDefaultPlacement($plan, $school, $sourceGrade, $targetGrade, $targetSection, $student);

        $summary = $this->dryRun()->run($this->freshPlan($plan));

        $this->assertTrue($summary['validated']);
        $fresh = $this->itemFor($plan, $student);
        $this->assertSame('ready', $fresh->validation_result);
        $this->assertNull($fresh->validation_reason);
    }

    #[Test]
    public function subject_mapping_missing_row_is_blocked(): void
    {
        ['school' => $school, 'sourceGrade' => $sourceGrade, 'targetGrade' => $targetGrade, 'sourceSection' => $sourceSection, 'targetSection' => $targetSection, 'plan' => $plan, 'sourceOffering' => $sourceOffering] = $this->buildSubjectContext();
        $student = $this->createStudent($school, ['student_number' => 'S-1001']);
        $this->enrollmentService()->enroll($student, $sourceSection, '01', '2026-06-01');
        $this->subjectEnrollmentService()->enroll($student, $sourceOffering, '2026-06-01');
        // no subject mapping created at all
        $this->promoteWithDefaultPlacement($plan, $school, $sourceGrade, $targetGrade, $targetSection, $student);

        $this->dryRun()->run($this->freshPlan($plan));

        $fresh = $this->itemFor($plan, $student);
        $this->assertSame('blocked', $fresh->validation_result);
        $this->assertSame('missing_subject_mapping', $fresh->validation_reason);
    }

    #[Test]
    public function subject_mapping_inactive_target_is_blocked(): void
    {
        ['school' => $school, 'sourceGrade' => $sourceGrade, 'targetGrade' => $targetGrade, 'sourceSection' => $sourceSection, 'targetSection' => $targetSection, 'plan' => $plan, 'sourceOffering' => $sourceOffering, 'targetOffering' => $targetOffering] = $this->buildSubjectContext();
        $student = $this->createStudent($school, ['student_number' => 'S-1001']);
        $this->enrollmentService()->enroll($student, $sourceSection, '01', '2026-06-01');
        $this->subjectEnrollmentService()->enroll($student, $sourceOffering, '2026-06-01');
        $this->planService()->upsertSubjectMapping($plan, $sourceOffering, $targetOffering);
        $this->promoteWithDefaultPlacement($plan, $school, $sourceGrade, $targetGrade, $targetSection, $student);
        app(TenantContext::class)->withSchool($school, fn () => $targetOffering->update(['status' => 'inactive']));

        $this->dryRun()->run($this->freshPlan($plan));

        $fresh = $this->itemFor($plan, $student);
        $this->assertSame('blocked', $fresh->validation_result);
        $this->assertSame('elective_target_inactive', $fresh->validation_reason);
    }

    #[Test]
    public function subject_mapping_target_that_became_required_is_blocked(): void
    {
        ['school' => $school, 'sourceGrade' => $sourceGrade, 'targetGrade' => $targetGrade, 'sourceSection' => $sourceSection, 'targetSection' => $targetSection, 'plan' => $plan, 'sourceOffering' => $sourceOffering, 'targetOffering' => $targetOffering] = $this->buildSubjectContext();
        $student = $this->createStudent($school, ['student_number' => 'S-1001']);
        $this->enrollmentService()->enroll($student, $sourceSection, '01', '2026-06-01');
        $this->subjectEnrollmentService()->enroll($student, $sourceOffering, '2026-06-01');
        $this->planService()->upsertSubjectMapping($plan, $sourceOffering, $targetOffering);
        $this->promoteWithDefaultPlacement($plan, $school, $sourceGrade, $targetGrade, $targetSection, $student);
        // Mutated directly -- the mapping service itself already rejects
        // a required target at configuration time (Phase 1G.1); this
        // proves dry-run independently re-validates CURRENT state rather
        // than trusting that a once-valid mapping stays valid forever.
        app(TenantContext::class)->withSchool($school, fn () => $targetOffering->update(['is_required' => true]));

        $this->dryRun()->run($this->freshPlan($plan));

        $fresh = $this->itemFor($plan, $student);
        $this->assertSame('blocked', $fresh->validation_result);
        $this->assertSame('elective_target_required', $fresh->validation_reason);
    }

    #[Test]
    public function subject_mapping_target_in_a_different_campus_than_the_resolved_placement_is_blocked(): void
    {
        ['school' => $school, 'campus' => $campus, 'targetYear' => $targetYear, 'sourceGrade' => $sourceGrade, 'targetGrade' => $targetGrade, 'sourceSection' => $sourceSection, 'targetSection' => $targetSection, 'plan' => $plan, 'subject' => $subject, 'sourceOffering' => $sourceOffering] = $this->buildSubjectContext();
        $otherCampus = $this->createCampus($school);
        $wrongCampusTarget = $this->createSubjectOffering($targetYear, $otherCampus, $targetGrade, $subject, ['is_required' => false]);
        $student = $this->createStudent($school, ['student_number' => 'S-1001']);
        $this->enrollmentService()->enroll($student, $sourceSection, '01', '2026-06-01');
        $this->subjectEnrollmentService()->enroll($student, $sourceOffering, '2026-06-01');
        $this->planService()->upsertSubjectMapping($plan, $sourceOffering, $wrongCampusTarget);
        $this->promoteWithDefaultPlacement($plan, $school, $sourceGrade, $targetGrade, $targetSection, $student);

        $this->dryRun()->run($this->freshPlan($plan));

        $fresh = $this->itemFor($plan, $student);
        $this->assertSame('blocked', $fresh->validation_result);
        $this->assertSame('elective_target_context_mismatch', $fresh->validation_reason);
    }

    #[Test]
    public function subject_mapping_target_section_difference_alone_never_conflicts(): void
    {
        ['school' => $school, 'campus' => $campus, 'targetYear' => $targetYear, 'sourceGrade' => $sourceGrade, 'targetGrade' => $targetGrade, 'sourceSection' => $sourceSection, 'plan' => $plan, 'sourceOffering' => $sourceOffering, 'targetOffering' => $targetOffering] = $this->buildSubjectContext();
        $otherTargetSection = $this->createSection($targetYear, $campus, $targetGrade, ['name' => '6Z', 'code' => '6Z']);
        $student = $this->createStudent($school, ['student_number' => 'S-1001']);
        $this->enrollmentService()->enroll($student, $sourceSection, '01', '2026-06-01');
        $this->subjectEnrollmentService()->enroll($student, $sourceOffering, '2026-06-01');
        $this->planService()->upsertSubjectMapping($plan, $sourceOffering, $targetOffering);
        $this->planService()->upsertMapping($plan, $sourceGrade, null, $targetGrade, $otherTargetSection);
        $item = $this->itemFor($plan, $student);
        $this->planService()->setItemDecision($this->freshPlan($plan), $item, 'promote', null, 'explicit', '007');

        $this->dryRun()->run($this->freshPlan($plan));

        $fresh = $this->itemFor($plan, $student);
        $this->assertSame('ready', $fresh->validation_result, 'target Section differing from campus/grade must never conflict on its own');
    }

    #[Test]
    public function subject_mapping_exact_target_already_active_is_reconcilable_non_blocking(): void
    {
        ['school' => $school, 'sourceGrade' => $sourceGrade, 'targetGrade' => $targetGrade, 'sourceSection' => $sourceSection, 'targetSection' => $targetSection, 'plan' => $plan, 'sourceOffering' => $sourceOffering, 'targetOffering' => $targetOffering] = $this->buildSubjectContext();
        $student = $this->createStudent($school, ['student_number' => 'S-1001']);
        $this->enrollmentService()->enroll($student, $sourceSection, '01', '2026-06-01');
        $this->subjectEnrollmentService()->enroll($student, $sourceOffering, '2026-06-01');
        $this->enrollmentService()->enroll($student, $targetSection, '007', '2027-06-01'); // already placed in target year
        $this->subjectEnrollmentService()->enroll($student, $targetOffering, '2027-06-01'); // already holds the exact mapped elective
        $this->planService()->upsertSubjectMapping($plan, $sourceOffering, $targetOffering);
        $this->planService()->upsertMapping($plan, $sourceGrade, null, $targetGrade, $targetSection);
        $item = $this->itemFor($plan, $student);
        $this->planService()->setItemDecision($this->freshPlan($plan), $item, 'promote', null, 'explicit', '007');

        $summary = $this->dryRun()->run($this->freshPlan($plan));

        $this->assertTrue($summary['validated']);
        $fresh = $this->itemFor($plan, $student);
        $this->assertSame('already_enrolled', $fresh->validation_result);
        $this->assertSame('already_enrolled_match', $fresh->validation_reason);

        $count = app(TenantContext::class)->withSchool($school, fn () => StudentSubjectEnrollment::query()->where('student_id', $student->id)->where('status', 'active')->count());
        $this->assertSame(2, $count, 'no duplicate target subject enrollment created');
    }

    #[Test]
    public function subject_mapping_existing_different_offering_in_the_same_target_group_is_blocked(): void
    {
        ['school' => $school, 'campus' => $campus, 'targetYear' => $targetYear, 'sourceGrade' => $sourceGrade, 'targetGrade' => $targetGrade, 'sourceSection' => $sourceSection, 'targetSection' => $targetSection, 'plan' => $plan, 'subject' => $subject, 'sourceOffering' => $sourceOffering, 'targetOffering' => $targetOffering] = $this->buildSubjectContext();
        $groupX = $this->createElectiveGroup($targetYear, $campus, $targetGrade);
        app(TenantContext::class)->withSchool($school, fn () => $targetOffering->update(['elective_group_id' => $groupX->id]));
        $otherSubject = $this->createSubject($school);
        $alreadyActiveOffering = $this->createSubjectOffering($targetYear, $campus, $targetGrade, $otherSubject, ['is_required' => false, 'elective_group_id' => $groupX->id]);

        $student = $this->createStudent($school, ['student_number' => 'S-1001']);
        $this->enrollmentService()->enroll($student, $sourceSection, '01', '2026-06-01');
        $this->subjectEnrollmentService()->enroll($student, $sourceOffering, '2026-06-01');
        $this->enrollmentService()->enroll($student, $targetSection, '007', '2027-06-01');
        $this->subjectEnrollmentService()->enroll($student, $alreadyActiveOffering, '2027-06-01'); // already in Group X via a DIFFERENT offering
        $this->planService()->upsertSubjectMapping($plan, $sourceOffering, $targetOffering);
        $this->planService()->upsertMapping($plan, $sourceGrade, null, $targetGrade, $targetSection);
        $item = $this->itemFor($plan, $student);
        $this->planService()->setItemDecision($this->freshPlan($plan), $item, 'promote', null, 'explicit', '007');

        $this->dryRun()->run($this->freshPlan($plan));

        $fresh = $this->itemFor($plan, $student);
        $this->assertSame('blocked', $fresh->validation_result);
        $this->assertSame('elective_target_existing_conflict', $fresh->validation_reason);
    }

    #[Test]
    public function two_source_electives_mapping_into_the_same_target_group_is_blocked_before_execution(): void
    {
        ['school' => $school, 'campus' => $campus, 'sourceYear' => $sourceYear, 'targetYear' => $targetYear, 'sourceGrade' => $sourceGrade, 'targetGrade' => $targetGrade, 'sourceSection' => $sourceSection, 'targetSection' => $targetSection, 'plan' => $plan, 'subject' => $subjectA, 'sourceOffering' => $sourceOfferingA, 'targetOffering' => $targetOfferingA] = $this->buildSubjectContext();
        $groupY = $this->createElectiveGroup($targetYear, $campus, $targetGrade);
        app(TenantContext::class)->withSchool($school, fn () => $targetOfferingA->update(['elective_group_id' => $groupY->id]));
        $subjectB = $this->createSubject($school);
        $sourceOfferingB = $this->createSubjectOffering($sourceYear, $campus, $sourceGrade, $subjectB, ['is_required' => false]);
        $targetOfferingB = $this->createSubjectOffering($targetYear, $campus, $targetGrade, $subjectB, ['is_required' => false, 'elective_group_id' => $groupY->id]);

        $student = $this->createStudent($school, ['student_number' => 'S-1001']);
        $this->enrollmentService()->enroll($student, $sourceSection, '01', '2026-06-01');
        $this->subjectEnrollmentService()->enroll($student, $sourceOfferingA, '2026-06-01');
        $this->subjectEnrollmentService()->enroll($student, $sourceOfferingB, '2026-06-01');
        $this->planService()->upsertSubjectMapping($plan, $sourceOfferingA, $targetOfferingA);
        $this->planService()->upsertSubjectMapping($plan, $sourceOfferingB, $targetOfferingB);
        $this->promoteWithDefaultPlacement($plan, $school, $sourceGrade, $targetGrade, $targetSection, $student);

        $summary = $this->dryRun()->run($this->freshPlan($plan));

        $this->assertFalse($summary['validated']);
        $fresh = $this->itemFor($plan, $student);
        $this->assertSame('blocked', $fresh->validation_result);
        $this->assertSame('elective_target_group_conflict', $fresh->validation_reason);
    }

    #[Test]
    public function two_mapped_targets_in_different_groups_both_pass(): void
    {
        ['school' => $school, 'campus' => $campus, 'sourceYear' => $sourceYear, 'targetYear' => $targetYear, 'sourceGrade' => $sourceGrade, 'targetGrade' => $targetGrade, 'sourceSection' => $sourceSection, 'targetSection' => $targetSection, 'plan' => $plan, 'sourceOffering' => $sourceOfferingA, 'targetOffering' => $targetOfferingA] = $this->buildSubjectContext();
        $groupX = $this->createElectiveGroup($targetYear, $campus, $targetGrade);
        $groupY = $this->createElectiveGroup($targetYear, $campus, $targetGrade);
        app(TenantContext::class)->withSchool($school, fn () => $targetOfferingA->update(['elective_group_id' => $groupX->id]));
        $subjectB = $this->createSubject($school);
        $sourceOfferingB = $this->createSubjectOffering($sourceYear, $campus, $sourceGrade, $subjectB, ['is_required' => false]);
        $targetOfferingB = $this->createSubjectOffering($targetYear, $campus, $targetGrade, $subjectB, ['is_required' => false, 'elective_group_id' => $groupY->id]);

        $student = $this->createStudent($school, ['student_number' => 'S-1001']);
        $this->enrollmentService()->enroll($student, $sourceSection, '01', '2026-06-01');
        $this->subjectEnrollmentService()->enroll($student, $sourceOfferingA, '2026-06-01');
        $this->subjectEnrollmentService()->enroll($student, $sourceOfferingB, '2026-06-01');
        $this->planService()->upsertSubjectMapping($plan, $sourceOfferingA, $targetOfferingA);
        $this->planService()->upsertSubjectMapping($plan, $sourceOfferingB, $targetOfferingB);
        $this->promoteWithDefaultPlacement($plan, $school, $sourceGrade, $targetGrade, $targetSection, $student);

        $summary = $this->dryRun()->run($this->freshPlan($plan));

        $this->assertTrue($summary['validated']);
        $fresh = $this->itemFor($plan, $student);
        $this->assertSame('ready', $fresh->validation_result);
    }

    #[Test]
    public function two_mapped_ungrouped_targets_both_pass(): void
    {
        ['school' => $school, 'campus' => $campus, 'sourceYear' => $sourceYear, 'targetYear' => $targetYear, 'sourceGrade' => $sourceGrade, 'targetGrade' => $targetGrade, 'sourceSection' => $sourceSection, 'targetSection' => $targetSection, 'plan' => $plan, 'sourceOffering' => $sourceOfferingA, 'targetOffering' => $targetOfferingA] = $this->buildSubjectContext();
        $subjectB = $this->createSubject($school);
        $sourceOfferingB = $this->createSubjectOffering($sourceYear, $campus, $sourceGrade, $subjectB, ['is_required' => false]);
        $targetOfferingB = $this->createSubjectOffering($targetYear, $campus, $targetGrade, $subjectB, ['is_required' => false]);

        $student = $this->createStudent($school, ['student_number' => 'S-1001']);
        $this->enrollmentService()->enroll($student, $sourceSection, '01', '2026-06-01');
        $this->subjectEnrollmentService()->enroll($student, $sourceOfferingA, '2026-06-01');
        $this->subjectEnrollmentService()->enroll($student, $sourceOfferingB, '2026-06-01');
        $this->planService()->upsertSubjectMapping($plan, $sourceOfferingA, $targetOfferingA);
        $this->planService()->upsertSubjectMapping($plan, $sourceOfferingB, $targetOfferingB);
        $this->promoteWithDefaultPlacement($plan, $school, $sourceGrade, $targetGrade, $targetSection, $student);

        $summary = $this->dryRun()->run($this->freshPlan($plan));

        $this->assertTrue($summary['validated']);
        $fresh = $this->itemFor($plan, $student);
        $this->assertSame('ready', $fresh->validation_result);
    }

    #[Test]
    public function two_source_electives_mapped_to_the_identical_target_offering_is_blocked(): void
    {
        ['school' => $school, 'campus' => $campus, 'sourceYear' => $sourceYear, 'sourceGrade' => $sourceGrade, 'targetGrade' => $targetGrade, 'sourceSection' => $sourceSection, 'targetSection' => $targetSection, 'plan' => $plan, 'sourceOffering' => $sourceOfferingA, 'targetOffering' => $sharedTarget] = $this->buildSubjectContext();
        $subjectB = $this->createSubject($school);
        $sourceOfferingB = $this->createSubjectOffering($sourceYear, $campus, $sourceGrade, $subjectB, ['is_required' => false]);

        $student = $this->createStudent($school, ['student_number' => 'S-1001']);
        $this->enrollmentService()->enroll($student, $sourceSection, '01', '2026-06-01');
        $this->subjectEnrollmentService()->enroll($student, $sourceOfferingA, '2026-06-01');
        $this->subjectEnrollmentService()->enroll($student, $sourceOfferingB, '2026-06-01');
        $this->planService()->upsertSubjectMapping($plan, $sourceOfferingA, $sharedTarget);
        $this->planService()->upsertSubjectMapping($plan, $sourceOfferingB, $sharedTarget); // two sources -> one target
        $this->promoteWithDefaultPlacement($plan, $school, $sourceGrade, $targetGrade, $targetSection, $student);

        $summary = $this->dryRun()->run($this->freshPlan($plan));

        $this->assertFalse($summary['validated']);
        $fresh = $this->itemFor($plan, $student);
        $this->assertSame('blocked', $fresh->validation_result);
        $this->assertSame('elective_target_duplicate_mapping', $fresh->validation_reason);
    }

    #[Test]
    public function legacy_null_anchor_row_with_exactly_one_compatible_matching_candidate_resolves_normally(): void
    {
        ['school' => $school, 'sourceGrade' => $sourceGrade, 'targetGrade' => $targetGrade, 'sourceSection' => $sourceSection, 'targetSection' => $targetSection, 'plan' => $plan, 'sourceOffering' => $sourceOffering, 'targetOffering' => $targetOffering] = $this->buildSubjectContext();
        $student = $this->createStudent($school, ['student_number' => 'S-1001']);
        $sourceEnrollment = $this->enrollmentService()->enroll($student, $sourceSection, '01', '2026-06-01');
        app(TenantContext::class)->withSchool($school, fn () => StudentSubjectEnrollment::factory()->create([
            'school_id' => $school->id,
            'student_id' => $student->id,
            'student_enrollment_id' => null,
            'subject_offering_id' => $sourceOffering->id,
            'academic_year_id' => $sourceOffering->academic_year_id,
            'status' => 'active',
            'starts_on' => '2026-06-01',
        ]));
        $this->planService()->upsertSubjectMapping($plan, $sourceOffering, $targetOffering);
        $this->promoteWithDefaultPlacement($plan, $school, $sourceGrade, $targetGrade, $targetSection, $student);

        $summary = $this->dryRun()->run($this->freshPlan($plan));

        $this->assertTrue($summary['validated']);
        $fresh = $this->itemFor($plan, $student);
        $this->assertSame('ready', $fresh->validation_result);
        $this->assertSame($sourceEnrollment->id, $fresh->source_enrollment_id);
    }

    #[Test]
    public function legacy_null_anchor_row_with_multiple_source_year_candidates_is_ambiguous(): void
    {
        ['school' => $school, 'sourceYear' => $sourceYear, 'sourceGrade' => $sourceGrade, 'targetGrade' => $targetGrade, 'sourceSection' => $sourceSection, 'targetSection' => $targetSection, 'plan' => $plan, 'sourceOffering' => $sourceOffering, 'targetOffering' => $targetOffering] = $this->buildSubjectContext();
        $student = $this->createStudent($school, ['student_number' => 'S-1001']);
        $this->enrollmentService()->enroll($student, $sourceSection, '01', '2026-06-01');
        // A second, non-active StudentEnrollment for the SAME source year --
        // excluded from placement's own active/completed candidacy check,
        // but it still counts toward the legacy rule's "exactly one
        // candidate, of any status" proof.
        $this->createStudentEnrollment($student, $sourceSection, ['roll_number' => '02', 'status' => 'withdrawn']);
        app(TenantContext::class)->withSchool($school, fn () => StudentSubjectEnrollment::factory()->create([
            'school_id' => $school->id,
            'student_id' => $student->id,
            'student_enrollment_id' => null,
            'subject_offering_id' => $sourceOffering->id,
            'academic_year_id' => $sourceOffering->academic_year_id,
            'status' => 'active',
            'starts_on' => '2026-06-01',
        ]));
        $this->planService()->upsertSubjectMapping($plan, $sourceOffering, $targetOffering);
        $this->promoteWithDefaultPlacement($plan, $school, $sourceGrade, $targetGrade, $targetSection, $student);

        $this->dryRun()->run($this->freshPlan($plan));

        $fresh = $this->itemFor($plan, $student);
        $this->assertSame('review', $fresh->validation_result);
        $this->assertSame('legacy_source_anchor_ambiguous', $fresh->validation_reason);
    }

    #[Test]
    public function legacy_null_anchor_row_whose_only_candidate_is_not_the_items_source_enrollment_is_ambiguous(): void
    {
        ['school' => $school, 'campus' => $campus, 'sourceGrade' => $sourceGrade, 'targetGrade' => $targetGrade, 'sourceSection' => $sourceSection, 'targetSection' => $targetSection, 'plan' => $plan, 'sourceOffering' => $sourceOffering, 'targetOffering' => $targetOffering] = $this->buildSubjectContext();
        $student = $this->createStudent($school, ['student_number' => 'S-1001']);
        $b = $this->enrollmentService()->enroll($student, $sourceSection, '01', '2026-06-01'); // the true, sole source-year candidate
        // A distractor Enrollment for the student in an UNRELATED year,
        // reusing sourceGrade (a School-scoped reference entity, shared
        // across years) so the Item's own placement pipeline resolves
        // normally against it -- this is what the Item is (incorrectly)
        // anchored to instead of the real candidate B.
        $distractorYear = $this->createAcademicYear($school, ['code' => 'DISTRACT', 'starts_on' => '2020-06-01', 'ends_on' => '2021-04-30']);
        $distractorSection = $this->createSection($distractorYear, $campus, $sourceGrade, ['name' => 'DZ', 'code' => 'DZ']);
        $d = $this->createStudentEnrollment($student, $distractorSection, ['roll_number' => '99', 'status' => 'active']);
        app(TenantContext::class)->withSchool($school, fn () => StudentSubjectEnrollment::factory()->create([
            'school_id' => $school->id,
            'student_id' => $student->id,
            'student_enrollment_id' => null,
            'subject_offering_id' => $sourceOffering->id,
            'academic_year_id' => $sourceOffering->academic_year_id,
            'status' => 'active',
            'starts_on' => '2026-06-01',
        ]));
        $this->planService()->upsertSubjectMapping($plan, $sourceOffering, $targetOffering);
        $this->planService()->upsertMapping($plan, $sourceGrade, null, $targetGrade, $targetSection);
        $this->createEnrollmentRolloverItem($plan, $student, $d, [
            'decision' => 'promote', 'target_section_id' => $targetSection->id,
            'roll_number_strategy' => 'explicit', 'target_roll_number' => '007',
        ]);

        $this->dryRun()->run($this->freshPlan($plan));

        $fresh = $this->itemFor($plan, $student);
        $this->assertSame('review', $fresh->validation_result);
        $this->assertSame('legacy_source_anchor_ambiguous', $fresh->validation_reason);
        $this->assertNotSame($b->id, $d->id);
    }

    #[Test]
    public function legacy_null_anchor_row_incompatible_with_the_sole_candidate_is_ambiguous(): void
    {
        ['school' => $school, 'campus' => $campus, 'sourceYear' => $sourceYear, 'targetGrade' => $targetGrade, 'sourceGrade' => $sourceGrade, 'sourceSection' => $sourceSection, 'targetSection' => $targetSection, 'plan' => $plan, 'subject' => $subject, 'targetOffering' => $targetOffering] = $this->buildSubjectContext();
        $otherCampus = $this->createCampus($school);
        $incompatibleSourceOffering = $this->createSubjectOffering($sourceYear, $otherCampus, $sourceGrade, $subject, ['is_required' => false]);
        $student = $this->createStudent($school, ['student_number' => 'S-1001']);
        $this->enrollmentService()->enroll($student, $sourceSection, '01', '2026-06-01'); // sole candidate is in $campus, not $otherCampus
        app(TenantContext::class)->withSchool($school, fn () => StudentSubjectEnrollment::factory()->create([
            'school_id' => $school->id,
            'student_id' => $student->id,
            'student_enrollment_id' => null,
            'subject_offering_id' => $incompatibleSourceOffering->id,
            'academic_year_id' => $incompatibleSourceOffering->academic_year_id,
            'status' => 'active',
            'starts_on' => '2026-06-01',
        ]));
        $this->planService()->upsertSubjectMapping($plan, $incompatibleSourceOffering, $targetOffering);
        $this->promoteWithDefaultPlacement($plan, $school, $sourceGrade, $targetGrade, $targetSection, $student);

        $this->dryRun()->run($this->freshPlan($plan));

        $fresh = $this->itemFor($plan, $student);
        $this->assertSame('review', $fresh->validation_result);
        $this->assertSame('legacy_source_anchor_ambiguous', $fresh->validation_reason);
    }

    #[Test]
    public function a_malformed_required_offering_participation_can_no_longer_exist(): void
    {
        ['school' => $school, 'sourceYear' => $sourceYear, 'campus' => $campus, 'sourceGrade' => $sourceGrade, 'targetGrade' => $targetGrade, 'sourceSection' => $sourceSection, 'targetSection' => $targetSection, 'plan' => $plan] = $this->buildSubjectContext();
        $subject = $this->createSubject($school);
        $requiredSourceOffering = $this->createSubjectOffering($sourceYear, $campus, $sourceGrade, $subject, ['is_required' => true]);
        $student = $this->createStudent($school, ['student_number' => 'S-1001']);
        $sourceEnrollment = $this->enrollmentService()->enroll($student, $sourceSection, '01', '2026-06-01');
        // StudentSubjectEnrollmentService::enroll() rejects a required
        // Offering, and since ADR 0069 the database refuses even a raw
        // row (`subject_offering_evidence_guard`): the malformed/legacy
        // participation this test once simulated cannot exist, and the
        // migration refuses to install over any that does.
        try {
            DB::transaction(fn () => app(TenantContext::class)->withSchool($school, fn () => StudentSubjectEnrollment::factory()->create([
                'school_id' => $school->id,
                'student_id' => $student->id,
                'student_enrollment_id' => $sourceEnrollment->id,
                'subject_offering_id' => $requiredSourceOffering->id,
                'academic_year_id' => $requiredSourceOffering->academic_year_id,
                'status' => 'active',
                'starts_on' => '2026-06-01',
            ])));
            $this->fail('the database refuses an elective participation on a required Offering');
        } catch (QueryException $e) {
            $this->assertStringContainsString('subject_offering_classification_mismatch', $e->getMessage());
        }
        // no subject mapping exists for the required offering -- and none should ever be required
        $this->promoteWithDefaultPlacement($plan, $school, $sourceGrade, $targetGrade, $targetSection, $student);

        $summary = $this->dryRun()->run($this->freshPlan($plan));

        $this->assertTrue($summary['validated']);
        $fresh = $this->itemFor($plan, $student);
        $this->assertSame('ready', $fresh->validation_result);
        $this->assertNull($fresh->validation_reason);
    }

    #[Test]
    public function a_real_subject_mapping_change_after_validation_invalidates_it(): void
    {
        ['school' => $school, 'sourceGrade' => $sourceGrade, 'targetGrade' => $targetGrade, 'sourceSection' => $sourceSection, 'targetSection' => $targetSection, 'plan' => $plan, 'sourceOffering' => $sourceOffering, 'targetOffering' => $targetOffering] = $this->buildSubjectContext();
        $student = $this->createStudent($school, ['student_number' => 'S-1001']);
        $this->enrollmentService()->enroll($student, $sourceSection, '01', '2026-06-01');
        $this->subjectEnrollmentService()->enroll($student, $sourceOffering, '2026-06-01');
        $this->planService()->upsertSubjectMapping($plan, $sourceOffering, $targetOffering);
        $this->promoteWithDefaultPlacement($plan, $school, $sourceGrade, $targetGrade, $targetSection, $student);
        $this->dryRun()->run($this->freshPlan($plan));
        $this->assertSame('validated', $this->freshPlan($plan)->status);

        $versionBefore = $this->freshPlan($plan)->configuration_version;
        $this->planService()->removeSubjectMapping($this->freshPlan($plan), $sourceOffering); // real change: mapped -> unconfigured
        $afterEdit = $this->freshPlan($plan);

        $this->assertGreaterThan($versionBefore, $afterEdit->configuration_version);
        $this->assertFalse($afterEdit->isValidatedForCurrentConfiguration());

        $summary = $this->dryRun()->run($afterEdit);
        $this->assertFalse($summary['validated']);
        $fresh = $this->itemFor($plan, $student);
        $this->assertSame('blocked', $fresh->validation_result);
        $this->assertSame('missing_subject_mapping', $fresh->validation_reason);
    }

    #[Test]
    public function a_true_no_op_subject_mapping_upsert_preserves_validation(): void
    {
        ['school' => $school, 'sourceGrade' => $sourceGrade, 'targetGrade' => $targetGrade, 'sourceSection' => $sourceSection, 'targetSection' => $targetSection, 'plan' => $plan, 'sourceOffering' => $sourceOffering, 'targetOffering' => $targetOffering] = $this->buildSubjectContext();
        $student = $this->createStudent($school, ['student_number' => 'S-1001']);
        $this->enrollmentService()->enroll($student, $sourceSection, '01', '2026-06-01');
        $this->subjectEnrollmentService()->enroll($student, $sourceOffering, '2026-06-01');
        $this->planService()->upsertSubjectMapping($plan, $sourceOffering, $targetOffering);
        $this->promoteWithDefaultPlacement($plan, $school, $sourceGrade, $targetGrade, $targetSection, $student);
        $this->dryRun()->run($this->freshPlan($plan));
        $freshPlan = $this->freshPlan($plan);
        $this->assertTrue($freshPlan->isValidatedForCurrentConfiguration());
        $versionBefore = $freshPlan->configuration_version;

        $this->planService()->upsertSubjectMapping($freshPlan, $sourceOffering, $targetOffering); // identical target -- true no-op

        $afterNoOp = $this->freshPlan($plan);
        $this->assertSame($versionBefore, $afterNoOp->configuration_version, 'a true no-op must never bump configuration_version');
        $this->assertTrue($afterNoOp->isValidatedForCurrentConfiguration(), 'a no-op must never invalidate an existing validation');
    }

    #[Test]
    public function subject_mapping_dry_run_is_deterministic_and_repeatable_when_unchanged(): void
    {
        ['school' => $school, 'sourceGrade' => $sourceGrade, 'targetGrade' => $targetGrade, 'sourceSection' => $sourceSection, 'targetSection' => $targetSection, 'plan' => $plan, 'sourceOffering' => $sourceOffering, 'targetOffering' => $targetOffering] = $this->buildSubjectContext();
        $student = $this->createStudent($school, ['student_number' => 'S-1001']);
        $this->enrollmentService()->enroll($student, $sourceSection, '01', '2026-06-01');
        $this->subjectEnrollmentService()->enroll($student, $sourceOffering, '2026-06-01');
        $this->planService()->upsertSubjectMapping($plan, $sourceOffering, $targetOffering);
        $this->promoteWithDefaultPlacement($plan, $school, $sourceGrade, $targetGrade, $targetSection, $student);

        $first = $this->dryRun()->run($this->freshPlan($plan));
        $second = $this->dryRun()->run($this->freshPlan($plan));

        $this->assertEquals($first, $second);
        $fresh = $this->itemFor($plan, $student);
        $this->assertSame('ready', $fresh->validation_result);

        $count = app(TenantContext::class)->withSchool($school, fn () => StudentSubjectEnrollment::query()->where('student_id', $student->id)->count());
        $this->assertSame(1, $count, 'no duplicate subject rows from re-running dry-run');
    }
}
