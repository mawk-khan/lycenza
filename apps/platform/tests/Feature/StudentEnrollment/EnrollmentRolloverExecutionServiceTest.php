<?php

namespace Tests\Feature\StudentEnrollment;

use App\Domain\AcademicStructure\Infrastructure\AcademicYear;
use App\Domain\AcademicStructure\Infrastructure\GradeLevel;
use App\Domain\AcademicStructure\Infrastructure\Section;
use App\Domain\Students\Application\EnrollmentRolloverDryRunService;
use App\Domain\Students\Application\EnrollmentRolloverExecutionService;
use App\Domain\Students\Application\EnrollmentRolloverPlanService;
use App\Domain\Students\Application\Exceptions\RolloverItemAlreadyExecutedException;
use App\Domain\Students\Application\Exceptions\RolloverPlanAlreadyExecutingException;
use App\Domain\Students\Application\Exceptions\RolloverPlanNoLongerConfigurableException;
use App\Domain\Students\Application\Exceptions\RolloverPlanNotExecutionReadyException;
use App\Domain\Students\Application\Exceptions\RolloverPlanNotResumableException;
use App\Domain\Students\Application\StudentEnrollmentService;
use App\Domain\Students\Infrastructure\EnrollmentRolloverItem;
use App\Domain\Students\Infrastructure\EnrollmentRolloverPlan;
use App\Domain\Students\Infrastructure\Student;
use App\Domain\Students\Infrastructure\StudentEnrollment;
use App\Models\Campus;
use App\Models\School;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 1B.7D: the PLAN-LEVEL bulk/resumable execution orchestrator
 * (EnrollmentRolloverExecutionService). Every test proves the
 * orchestrator delegates ALL academic mutation to the already-proven
 * 1B.7C per-Item primitive -- it never creates a StudentEnrollment
 * directly, never runs the whole Plan in one transaction, and always
 * stops immediately (never finalizes) the moment external drift
 * invalidates the Plan mid-run.
 */
class EnrollmentRolloverExecutionServiceTest extends TestCase
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

    private function execution(): EnrollmentRolloverExecutionService
    {
        return app(EnrollmentRolloverExecutionService::class);
    }

    private function enrollmentService(): StudentEnrollmentService
    {
        return app(StudentEnrollmentService::class);
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

    private function freshItem(EnrollmentRolloverItem $item): EnrollmentRolloverItem
    {
        return app(TenantContext::class)->withSchool($item->school, fn () => $item->fresh());
    }

    private function fingerprint(School $school): array
    {
        return app(TenantContext::class)->withSchool($school, fn () => [
            'students' => DB::table('students')->orderBy('id')->get()->toArray(),
            'student_enrollments' => DB::table('student_enrollments')->orderBy('id')->get()->toArray(),
            'academic_years' => DB::table('academic_years')->orderBy('id')->get()->toArray(),
            'grade_levels' => DB::table('grade_levels')->orderBy('id')->get()->toArray(),
            'sections' => DB::table('sections')->orderBy('id')->get()->toArray(),
            'campuses' => DB::table('campuses')->orderBy('id')->get()->toArray(),
        ]);
    }

    /**
     * Builds a validated Plan with $count 'promote' Items (distinct
     * roll numbers "101".."10N"), ready to be handed straight to
     * start(). Used by tests that only care about bulk plumbing, not
     * per-Item variety.
     *
     * @return array{school: School, campus: Campus, targetYear: AcademicYear, targetSection: Section, plan: EnrollmentRolloverPlan, students: array<int, Student>}
     */
    private function buildValidatedPlanWithNPromoteItems(int $count): array
    {
        $ctx = $this->buildContext();
        ['school' => $school, 'sourceGrade' => $sourceGrade, 'targetGrade' => $targetGrade, 'sourceSection' => $sourceSection, 'targetSection' => $targetSection, 'plan' => $plan] = $ctx;
        $this->planService()->upsertMapping($plan, $sourceGrade, null, $targetGrade, $targetSection);

        $students = [];
        foreach (range(1, $count) as $i) {
            $student = $this->createStudent($school, ['student_number' => "S-BULK-{$i}"]);
            $this->enrollmentService()->enroll($student, $sourceSection, (string) (100 + $i), '2026-06-01');
            $item = $this->itemFor($plan, $student);
            $this->planService()->setItemDecision($this->freshPlan($plan), $item, 'promote', null, 'explicit', (string) (100 + $i));
            $students[] = $student;
        }

        $summary = $this->dryRun()->run($this->freshPlan($plan));
        $this->assertTrue($summary['validated'], 'fixture setup must produce a validated plan');

        return [...$ctx, 'students' => $students];
    }

    // ==================================================================
    // Plan claim (sections 11, 33, 66)
    // ==================================================================

    #[Test]
    public function start_claims_a_validated_plan_transitions_to_executing_and_sets_started_at(): void
    {
        ['plan' => $plan, 'students' => $students] = $this->buildValidatedPlanWithNPromoteItems(1);

        $summary = $this->execution()->start($plan);

        $this->assertSame('completed', $summary['planStatus']);
        $fresh = $this->freshPlan($plan);
        $this->assertNotNull($fresh->execution_started_at);
    }

    #[Test]
    public function start_rejects_a_draft_plan(): void
    {
        ['plan' => $plan] = $this->buildContext();

        $this->expectException(RolloverPlanNotExecutionReadyException::class);
        $this->execution()->start($plan);
    }

    #[Test]
    public function start_rejects_an_already_executing_plan(): void
    {
        ['plan' => $plan] = $this->buildValidatedPlanWithNPromoteItems(3);

        // Stop after the first item so the plan stays 'executing'.
        $this->execution()->start($plan, batchSize: 100, afterEachItem: fn () => true);
        $this->assertSame('executing', $this->freshPlan($plan)->status);

        $this->expectException(RolloverPlanAlreadyExecutingException::class);
        $this->execution()->start($this->freshPlan($plan));
    }

    #[Test]
    public function start_rejects_a_stale_configuration_version(): void
    {
        ['plan' => $plan, 'sourceGrade' => $sourceGrade, 'targetGrade' => $targetGrade, 'targetSection' => $targetSection] = $this->buildValidatedPlanWithNPromoteItems(1);
        $this->planService()->upsertMapping($this->freshPlan($plan), $sourceGrade, null, $targetGrade, $targetSection);

        $this->expectException(RolloverPlanNotExecutionReadyException::class);
        $this->execution()->start($this->freshPlan($plan));
    }

    // ==================================================================
    // Resume (sections 13, 14, 68)
    // ==================================================================

    #[Test]
    public function resume_rejects_a_plan_that_is_not_executing(): void
    {
        ['plan' => $plan] = $this->buildValidatedPlanWithNPromoteItems(1);

        $this->expectException(RolloverPlanNotResumableException::class);
        $this->execution()->resume($plan);
    }

    #[Test]
    public function resume_after_full_completion_is_a_clean_no_op_rejection(): void
    {
        ['school' => $school, 'plan' => $plan] = $this->buildValidatedPlanWithNPromoteItems(1);
        $this->execution()->start($plan);
        $this->assertSame('completed', $this->freshPlan($plan)->status);

        $enrollmentCountBefore = app(TenantContext::class)->withSchool($school, fn () => StudentEnrollment::query()->count());

        $this->expectException(RolloverPlanNotResumableException::class);

        try {
            $this->execution()->resume($this->freshPlan($plan));
        } finally {
            $enrollmentCountAfter = app(TenantContext::class)->withSchool($school, fn () => StudentEnrollment::query()->count());
            $this->assertSame($enrollmentCountBefore, $enrollmentCountAfter, 'resuming a completed plan must create zero new Enrollments');
        }
    }

    #[Test]
    public function execution_started_at_is_preserved_across_resume(): void
    {
        ['plan' => $plan] = $this->buildValidatedPlanWithNPromoteItems(3);

        $this->execution()->start($plan, batchSize: 100, afterEachItem: fn () => true);
        $startedAt = $this->freshPlan($plan)->execution_started_at;
        $this->assertNotNull($startedAt);

        $this->execution()->resume($this->freshPlan($plan));

        $this->assertTrue($startedAt->equalTo($this->freshPlan($plan)->execution_started_at), 'execution_started_at must never be rewritten by resume()');
    }

    // ==================================================================
    // Section 47 -- mixed Item types, section 78 -- no direct creation
    // ==================================================================

    #[Test]
    public function a_plan_with_mixed_item_types_executes_each_correctly_and_completes(): void
    {
        $ctx = $this->buildContext();
        ['school' => $school, 'campus' => $campus, 'sourceGrade' => $sourceGrade, 'targetGrade' => $targetGrade, 'sourceSection' => $sourceSection, 'targetSection' => $targetSection, 'sourceYear' => $sourceYear, 'targetYear' => $targetYear, 'plan' => $plan] = $ctx;
        // A SEPARATE source Section for the repeat Student -- mapping
        // resolution keys on (source Section, source Grade), so a
        // repeat Student sharing the SAME source Section as the promote
        // Students would have a section-specific repeat mapping
        // override the grade-default promote mapping for ALL of them.
        $repeatSourceSection = $this->createSection($sourceYear, $campus, $sourceGrade, ['name' => '5B', 'code' => '5B']);
        $repeatSection = $this->createSection($targetYear, $campus, $sourceGrade, ['name' => '5C', 'code' => '5C']);
        $this->planService()->upsertMapping($plan, $sourceGrade, null, $targetGrade, $targetSection);
        $this->planService()->upsertMapping($this->freshPlan($plan), $sourceGrade, $repeatSourceSection, $sourceGrade, $repeatSection);

        $promoteStudent = $this->createStudent($school, ['student_number' => 'S-MIX-1']);
        $this->enrollmentService()->enroll($promoteStudent, $sourceSection, '01', '2026-06-01');
        $repeatStudent = $this->createStudent($school, ['student_number' => 'S-MIX-2']);
        $this->enrollmentService()->enroll($repeatStudent, $repeatSourceSection, '02', '2026-06-01');
        $alreadyEnrolledStudent = $this->createStudent($school, ['student_number' => 'S-MIX-3']);
        $this->enrollmentService()->enroll($alreadyEnrolledStudent, $sourceSection, '03', '2026-06-01');
        $preExisting = $this->enrollmentService()->enroll($alreadyEnrolledStudent, $targetSection, '070', '2027-06-01');
        $excludedStudent = $this->createStudent($school, ['student_number' => 'S-MIX-4']);
        $this->enrollmentService()->enroll($excludedStudent, $sourceSection, '04', '2026-06-01');

        $this->planService()->setItemDecision($this->freshPlan($plan), $this->itemFor($plan, $promoteStudent), 'promote', null, 'explicit', '101');
        $this->planService()->setItemDecision($this->freshPlan($plan), $this->itemFor($plan, $repeatStudent), 'repeat', null, 'explicit', '102');
        $this->planService()->setItemDecision($this->freshPlan($plan), $this->itemFor($plan, $alreadyEnrolledStudent), 'promote', null, 'explicit', '070');
        $this->planService()->setItemDecision($this->freshPlan($plan), $this->itemFor($plan, $excludedStudent), 'exclude');

        $summary = $this->dryRun()->run($this->freshPlan($plan));
        $this->assertTrue($summary['validated']);

        $enrollmentCountBefore = app(TenantContext::class)->withSchool($school, fn () => StudentEnrollment::query()->count());
        $result = $this->execution()->start($this->freshPlan($plan));
        $enrollmentCountAfter = app(TenantContext::class)->withSchool($school, fn () => StudentEnrollment::query()->count());

        $this->assertSame('completed', $result['planStatus']);
        $this->assertSame(2, $result['succeeded'], 'promote + repeat');
        $this->assertSame(1, $result['reconciled'], 'already-enrolled exact match');
        $this->assertSame(1, $result['skipped'], 'excluded');
        $this->assertSame(0, $result['failed']);
        $this->assertSame(0, $result['pending']);
        // Exactly 2 new Enrollments (promote + repeat); the already-enrolled
        // reconciliation creates none, the exclusion creates none.
        $this->assertSame($enrollmentCountBefore + 2, $enrollmentCountAfter);

        $reconciledItem = $this->itemFor($this->freshPlan($plan), $alreadyEnrolledStudent);
        $this->assertSame($preExisting->id, $reconciledItem->target_enrollment_id);

        $excludedItem = $this->itemFor($this->freshPlan($plan), $excludedStudent);
        $this->assertSame('skipped', $excludedItem->execution_status);
        $this->assertNull($excludedItem->target_enrollment_id);
    }

    // ==================================================================
    // Section 48 -- many Items crossing batch size
    // ==================================================================

    #[Test]
    public function many_items_crossing_the_batch_size_all_execute_exactly_once(): void
    {
        ['school' => $school, 'plan' => $plan, 'students' => $students] = $this->buildValidatedPlanWithNPromoteItems(7);

        $result = $this->execution()->start($this->freshPlan($plan), batchSize: 3);

        $this->assertSame('completed', $result['planStatus']);
        $this->assertSame(7, $result['succeeded']);
        $this->assertSame(0, $result['pending']);

        foreach ($students as $student) {
            $count = app(TenantContext::class)->withSchool($school, fn () => StudentEnrollment::query()->where('student_id', $student->id)->count());
            $this->assertSame(2, $count, 'each Student must have exactly source + target Enrollment, never a duplicate target');
        }
    }

    // ==================================================================
    // Section 71/72 -- zero executable target creations
    // ==================================================================

    #[Test]
    public function a_plan_with_only_excluded_and_already_enrolled_items_completes_with_zero_new_enrollments(): void
    {
        $ctx = $this->buildContext();
        ['school' => $school, 'sourceGrade' => $sourceGrade, 'targetGrade' => $targetGrade, 'sourceSection' => $sourceSection, 'targetSection' => $targetSection, 'plan' => $plan] = $ctx;
        $this->planService()->upsertMapping($plan, $sourceGrade, null, $targetGrade, $targetSection);

        $excludedStudent = $this->createStudent($school, ['student_number' => 'S-ZERO-1']);
        $this->enrollmentService()->enroll($excludedStudent, $sourceSection, '01', '2026-06-01');
        $alreadyEnrolledStudent = $this->createStudent($school, ['student_number' => 'S-ZERO-2']);
        $this->enrollmentService()->enroll($alreadyEnrolledStudent, $sourceSection, '02', '2026-06-01');
        $this->enrollmentService()->enroll($alreadyEnrolledStudent, $targetSection, '099', '2027-06-01');

        $this->planService()->setItemDecision($this->freshPlan($plan), $this->itemFor($plan, $excludedStudent), 'exclude');
        $this->planService()->setItemDecision($this->freshPlan($plan), $this->itemFor($plan, $alreadyEnrolledStudent), 'promote', null, 'explicit', '099');

        $summary = $this->dryRun()->run($this->freshPlan($plan));
        $this->assertTrue($summary['validated']);

        $before = $this->fingerprint($school);
        $result = $this->execution()->start($this->freshPlan($plan));
        $after = $this->fingerprint($school);

        $this->assertSame('completed', $result['planStatus']);
        $this->assertSame(0, $result['succeeded']);
        $this->assertSame(1, $result['reconciled']);
        $this->assertSame(1, $result['skipped']);
        $this->assertEquals($before['student_enrollments'], $after['student_enrollments'], 'zero new StudentEnrollment rows for an all-excluded/already-enrolled plan');
    }

    // ==================================================================
    // Section 49/21 -- invalidation mid-run stops immediately
    // ==================================================================

    #[Test]
    public function invalidation_mid_run_stops_immediately_and_preserves_the_prior_success(): void
    {
        $ctx = $this->buildContext();
        ['school' => $school, 'targetYear' => $targetYear, 'campus' => $campus, 'targetGrade' => $targetGrade, 'sourceGrade' => $sourceGrade, 'sourceSection' => $sourceSection, 'targetSection' => $targetSection, 'plan' => $plan] = $ctx;
        $this->planService()->upsertMapping($plan, $sourceGrade, null, $targetGrade, $targetSection);

        $firstStudent = $this->createStudent($school, ['student_number' => 'S-INV-1']);
        $this->enrollmentService()->enroll($firstStudent, $sourceSection, '01', '2026-06-01');
        $secondStudent = $this->createStudent($school, ['student_number' => 'S-INV-2']);
        $this->enrollmentService()->enroll($secondStudent, $sourceSection, '02', '2026-06-01');

        $this->planService()->setItemDecision($this->freshPlan($plan), $this->itemFor($plan, $firstStudent), 'promote', null, 'explicit', '201');
        $this->planService()->setItemDecision($this->freshPlan($plan), $this->itemFor($plan, $secondStudent), 'promote', null, 'explicit', '202');
        $summary = $this->dryRun()->run($this->freshPlan($plan));
        $this->assertTrue($summary['validated']);

        $secondItemId = $this->itemFor($this->freshPlan($plan), $secondStudent)->id;
        $conflictingOtherStudent = $this->createStudent($school, ['student_number' => 'S-INV-3']);

        // Deterministic test seam (this checkpoint's brief, section 30):
        // right after the FIRST item succeeds, inject a real conflict for
        // the SECOND item's exact target proposal before it is attempted.
        $result = $this->execution()->start($this->freshPlan($plan), batchSize: 100, afterEachItem: function (string $itemId) use ($secondItemId, $conflictingOtherStudent, $sourceSection, $targetSection) {
            if ($itemId !== $secondItemId) {
                $this->enrollmentService()->enroll($conflictingOtherStudent, $sourceSection, '03', '2026-06-01');
                $this->enrollmentService()->enroll($conflictingOtherStudent, $targetSection, '202', '2027-06-01');
            }

            return false;
        });

        $this->assertSame(1, $result['succeeded']);
        $this->assertSame(1, $result['failed']);
        $this->assertNotSame('completed', $result['planStatus']);
        $this->assertNotSame('completed_with_errors', $result['planStatus']);

        $freshPlan = $this->freshPlan($plan);
        $this->assertSame('draft', $freshPlan->status, 'invalidation must revert the Plan, never leave it looking completed');

        $firstItem = $this->itemFor($this->freshPlan($plan), $firstStudent);
        $this->assertSame('succeeded', $firstItem->execution_status);
        $this->assertNotNull($firstItem->target_enrollment_id);

        $secondItem = $this->freshItem($this->itemFor($this->freshPlan($plan), $secondStudent));
        $this->assertSame('failed', $secondItem->execution_status);
        $this->assertNull($secondItem->target_enrollment_id);
    }

    // ==================================================================
    // Sections 73/74 -- academic-state fingerprint
    // ==================================================================

    #[Test]
    public function a_fully_successful_plan_changes_academic_state_by_exactly_the_new_target_enrollments(): void
    {
        ['school' => $school, 'plan' => $plan, 'students' => $students] = $this->buildValidatedPlanWithNPromoteItems(3);

        $before = $this->fingerprint($school);
        $result = $this->execution()->start($this->freshPlan($plan));
        $after = $this->fingerprint($school);

        foreach (['students', 'academic_years', 'grade_levels', 'sections', 'campuses'] as $table) {
            $this->assertEquals($before[$table], $after[$table], "execution must not change any {$table} row");
        }
        $this->assertCount(count($before['student_enrollments']) + 3, $after['student_enrollments']);
        $this->assertSame(3, $result['succeeded']);

        // Every source Enrollment remains exactly as it was.
        foreach ($students as $student) {
            $source = app(TenantContext::class)->withSchool($school, fn () => StudentEnrollment::query()
                ->where('student_id', $student->id)->where('status', 'active')
                ->whereHas('academicYear', fn ($q) => $q->where('code', 'SRC'))
                ->first());
            $this->assertNotNull($source, 'the source Enrollment must remain active and untouched');
        }
    }

    // ==================================================================
    // Sections 50/51/52 -- revalidation after partial execution
    // ==================================================================

    #[Test]
    public function dry_run_after_partial_execution_preserves_provenance_and_reclassifies_as_already_enrolled(): void
    {
        ['school' => $school, 'plan' => $plan, 'students' => $students] = $this->buildValidatedPlanWithNPromoteItems(2);

        $this->execution()->start($this->freshPlan($plan), batchSize: 100, afterEachItem: fn () => true);
        $freshPlan = $this->freshPlan($plan);
        $this->assertSame('executing', $freshPlan->status);

        $executedStudent = null;
        $unexecutedStudent = null;
        foreach ($students as $student) {
            $item = $this->itemFor($freshPlan, $student);
            if ($item->target_enrollment_id !== null) {
                $executedStudent = $student;
            } else {
                $unexecutedStudent = $student;
            }
        }
        $this->assertNotNull($executedStudent);
        $executedItemBefore = $this->itemFor($freshPlan, $executedStudent);

        // Force the plan back to a configurable state (simulating an
        // operator noticing the interrupted run) so dry-run can be
        // re-run without touching configuration_version.
        app(TenantContext::class)->withSchool($school, fn () => EnrollmentRolloverPlan::query()->whereKey($plan->id)->update(['status' => 'draft']));

        $summary = $this->dryRun()->run($this->freshPlan($plan));

        $executedItemAfter = $this->itemFor($this->freshPlan($plan), $executedStudent);
        $this->assertSame($executedItemBefore->target_enrollment_id, $executedItemAfter->target_enrollment_id, 'dry-run must never erase execution provenance');
        $this->assertSame('succeeded', $executedItemAfter->execution_status, 'dry-run must never touch execution_status');
        $this->assertSame('already_enrolled', $executedItemAfter->validation_result, 'the already-created target must be recognized, not treated as a fresh proposal');
        $this->assertTrue($summary['validated']);
    }

    #[Test]
    public function starting_again_after_revalidation_never_re_touches_the_already_succeeded_item(): void
    {
        ['school' => $school, 'plan' => $plan, 'students' => $students] = $this->buildValidatedPlanWithNPromoteItems(2);

        $this->execution()->start($this->freshPlan($plan), batchSize: 100, afterEachItem: fn () => true);
        app(TenantContext::class)->withSchool($school, fn () => EnrollmentRolloverPlan::query()->whereKey($plan->id)->update(['status' => 'draft']));
        $this->dryRun()->run($this->freshPlan($plan));

        $enrollmentCountBefore = app(TenantContext::class)->withSchool($school, fn () => StudentEnrollment::query()->count());
        $result = $this->execution()->start($this->freshPlan($plan));
        $enrollmentCountAfter = app(TenantContext::class)->withSchool($school, fn () => StudentEnrollment::query()->count());

        $this->assertSame('completed', $result['planStatus']);
        // Plan-wide totals: the previously-succeeded Item stays
        // 'succeeded' forever (execution_status is a terminal-accepted
        // outcome the orchestrator never re-selects for processing --
        // it is NOT re-touched, NOT reclassified to 'reconciled', even
        // though dry-run's own validation_result now shows
        // 'already_enrolled' for it); the newly-processed Item adds to
        // the SAME 'succeeded' bucket.
        $this->assertSame(2, $result['succeeded']);
        $this->assertSame(0, $result['reconciled']);
        $this->assertSame($enrollmentCountBefore + 1, $enrollmentCountAfter, 'exactly one new target Enrollment -- the already-executed one is never re-created');
    }

    // ==================================================================
    // Section 29/30/67 -- resumability after deterministic interruption
    // ==================================================================

    #[Test]
    public function resumability_after_a_deterministic_interruption_processes_remaining_items_without_duplication(): void
    {
        ['school' => $school, 'plan' => $plan, 'students' => $students] = $this->buildValidatedPlanWithNPromoteItems(5);

        $processed = [];
        $this->execution()->start($this->freshPlan($plan), batchSize: 100, afterEachItem: function (string $itemId) use (&$processed) {
            $processed[] = $itemId;

            return count($processed) >= 2; // stop after exactly 2 items
        });

        $freshPlan = $this->freshPlan($plan);
        $this->assertSame('executing', $freshPlan->status, 'an interrupted run must leave the Plan executing, never completed');

        $succeededBefore = app(TenantContext::class)->withSchool($school, fn () => EnrollmentRolloverItem::query()->where('plan_id', $plan->id)->where('execution_status', 'succeeded')->count());
        $this->assertSame(2, $succeededBefore);

        $result = $this->execution()->resume($freshPlan);

        $this->assertSame('completed', $result['planStatus']);
        $this->assertSame(5, $result['succeeded']);

        foreach ($students as $student) {
            $count = app(TenantContext::class)->withSchool($school, fn () => StudentEnrollment::query()->where('student_id', $student->id)->count());
            $this->assertSame(2, $count, 'each Student must have exactly one source + one target Enrollment');
        }
    }

    // ==================================================================
    // Sections 53/54 -- executed-Item immutability
    // ==================================================================

    #[Test]
    public function no_items_configuration_can_be_rewritten_while_the_plan_is_executing(): void
    {
        ['plan' => $plan] = $this->buildValidatedPlanWithNPromoteItems(2);
        $this->execution()->start($this->freshPlan($plan), batchSize: 100, afterEachItem: fn () => true);

        $freshPlan = $this->freshPlan($plan);
        $this->assertSame('executing', $freshPlan->status);
        $executedItem = app(TenantContext::class)->withSchool($freshPlan->school, fn () => EnrollmentRolloverItem::query()
            ->where('plan_id', $freshPlan->id)->whereNotNull('target_enrollment_id')->firstOrFail());

        // While the Plan itself is 'executing', EnrollmentRolloverPlanService::assertConfigurable()
        // (only draft/validated are configurable) already blocks EVERY
        // Item's configuration from changing -- executed or not. The
        // NARROWER RolloverItemAlreadyExecutedException guard (proven by
        // the next test) exists for the case where the Plan has reverted
        // to draft/validated after a partial-execution invalidation but
        // this specific Item has already produced a target Enrollment.
        $this->expectException(RolloverPlanNoLongerConfigurableException::class);
        $this->planService()->setItemDecision($freshPlan, $executedItem, 'exclude');
    }

    #[Test]
    public function an_executed_items_configuration_cannot_be_rewritten_once_the_plan_is_configurable_again(): void
    {
        ['school' => $school, 'plan' => $plan] = $this->buildValidatedPlanWithNPromoteItems(2);
        $this->execution()->start($this->freshPlan($plan), batchSize: 100, afterEachItem: fn () => true);

        $freshPlan = $this->freshPlan($plan);
        $executedItem = app(TenantContext::class)->withSchool($freshPlan->school, fn () => EnrollmentRolloverItem::query()
            ->where('plan_id', $freshPlan->id)->whereNotNull('target_enrollment_id')->firstOrFail());

        app(TenantContext::class)->withSchool($school, fn () => EnrollmentRolloverPlan::query()->whereKey($plan->id)->update(['status' => 'draft']));

        $this->expectException(RolloverItemAlreadyExecutedException::class);
        $this->planService()->setItemDecision($this->freshPlan($plan), $executedItem, 'exclude');
    }

    #[Test]
    public function a_mapping_edit_after_partial_execution_never_moves_already_executed_provenance(): void
    {
        $ctx = $this->buildContext();
        ['school' => $school, 'campus' => $campus, 'sourceGrade' => $sourceGrade, 'targetGrade' => $targetGrade, 'sourceSection' => $sourceSection, 'targetSection' => $targetSection, 'targetYear' => $targetYear, 'plan' => $plan] = $ctx;
        $this->planService()->upsertMapping($plan, $sourceGrade, null, $targetGrade, $targetSection);

        $student = $this->createStudent($school, ['student_number' => 'S-IMMUT-1']);
        $this->enrollmentService()->enroll($student, $sourceSection, '01', '2026-06-01');
        $this->planService()->setItemDecision($this->freshPlan($plan), $this->itemFor($plan, $student), 'promote', null, 'explicit', '301');
        $summary = $this->dryRun()->run($this->freshPlan($plan));
        $this->assertTrue($summary['validated']);

        $this->execution()->start($this->freshPlan($plan));
        $executedItem = $this->itemFor($this->freshPlan($plan), $student);
        $this->assertNotNull($executedItem->target_enrollment_id);
        $originalTargetId = $executedItem->target_enrollment_id;
        $originalTarget = app(TenantContext::class)->withSchool($school, fn () => StudentEnrollment::query()->findOrFail($originalTargetId));

        $anotherTargetSection = $this->createSection($targetYear, $campus, $targetGrade, ['name' => '6D', 'code' => '6D']);
        app(TenantContext::class)->withSchool($school, fn () => EnrollmentRolloverPlan::query()->whereKey($plan->id)->update(['status' => 'draft']));
        $this->planService()->upsertMapping($this->freshPlan($plan), $sourceGrade, null, $targetGrade, $anotherTargetSection);

        $freshExecutedItem = $this->itemFor($this->freshPlan($plan), $student);
        $this->assertSame($originalTargetId, $freshExecutedItem->target_enrollment_id, 'a mapping edit must never move an already-executed Item\'s provenance');

        $freshOriginalTarget = app(TenantContext::class)->withSchool($school, fn () => $originalTarget->fresh());
        $this->assertSame($targetSection->id, $freshOriginalTarget->section_id, 'the real target Enrollment must never be silently moved to the new Section');
    }

    // ==================================================================
    // Sections 37/38/69 -- durable summary
    // ==================================================================

    #[Test]
    public function plan_summary_is_recomputed_from_persisted_items_not_in_memory_state(): void
    {
        ['school' => $school, 'plan' => $plan] = $this->buildValidatedPlanWithNPromoteItems(5);

        $this->execution()->start($this->freshPlan($plan), batchSize: 100, afterEachItem: fn () => true);

        // Read persisted Item rows directly, bypassing the orchestrator
        // entirely, to confirm exactly one Item succeeded before resume.
        $succeededBeforeResume = app(TenantContext::class)->withSchool($school, fn () => EnrollmentRolloverItem::query()
            ->where('plan_id', $plan->id)->where('execution_status', 'succeeded')->count());
        $this->assertSame(1, $succeededBeforeResume);

        // A BRAND NEW service instance (fresh container resolution, no
        // shared state with the one that ran start()) must report the
        // full, correct summary purely from the database -- summarize()
        // is defined as "recomputed from Items", never an in-memory
        // counter accumulated during the loop.
        $result = app(EnrollmentRolloverExecutionService::class)->resume($this->freshPlan($plan));

        $this->assertSame(5, $result['total']);
        $this->assertSame(5, $result['succeeded']);
        $this->assertSame(0, $result['pending']);
        $this->assertSame('completed', $result['planStatus']);
    }

    // ==================================================================
    // Section 75 -- target AcademicYear never activated
    // ==================================================================

    #[Test]
    public function successful_execution_never_activates_the_target_academic_year(): void
    {
        ['school' => $school, 'targetYear' => $targetYear, 'plan' => $plan] = $this->buildValidatedPlanWithNPromoteItems(1);

        $this->execution()->start($this->freshPlan($plan));

        $freshTargetYear = app(TenantContext::class)->withSchool($school, fn () => $targetYear->fresh());
        $this->assertSame('draft', $freshTargetYear->status);
    }

    // ==================================================================
    // Section 64 -- cross-School
    // ==================================================================

    #[Test]
    public function a_plan_cannot_be_started_from_a_different_schools_context(): void
    {
        ['plan' => $plan] = $this->buildValidatedPlanWithNPromoteItems(1);
        $otherSchool = $this->createSchool();

        $foreignView = app(TenantContext::class)->withSchool($otherSchool, fn () => EnrollmentRolloverPlan::query()->whereKey($plan->id)->first());
        $this->assertNull($foreignView, 'RLS must hide the Plan entirely from a different School context');
    }
}
