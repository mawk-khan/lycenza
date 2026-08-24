<?php

namespace Tests\Feature\StudentEnrollment;

use App\Domain\AcademicStructure\Infrastructure\AcademicYear;
use App\Domain\AcademicStructure\Infrastructure\GradeLevel;
use App\Domain\AcademicStructure\Infrastructure\Section;
use App\Domain\Students\Application\EnrollmentRolloverDryRunService;
use App\Domain\Students\Application\EnrollmentRolloverItemExecutionService;
use App\Domain\Students\Application\EnrollmentRolloverPlanService;
use App\Domain\Students\Application\Exceptions\RolloverItemAlreadyExecutedException;
use App\Domain\Students\Application\Exceptions\RolloverItemNotExecutableException;
use App\Domain\Students\Application\Exceptions\RolloverPlanNotExecutionReadyException;
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
use RuntimeException;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 1B.7C: the per-Student promotion EXECUTION primitive
 * (EnrollmentRolloverItemExecutionService). Every test proves execution
 * touches ONLY the one target StudentEnrollment row (+ rollover
 * planning state) it is explicitly meant to create/reconcile -- never a
 * source Enrollment mutation, never a second/duplicate target
 * Enrollment, never an AcademicYear/Section/GradeLevel/Campus row.
 */
class EnrollmentRolloverItemExecutionServiceTest extends TestCase
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

    private function execution(): EnrollmentRolloverItemExecutionService
    {
        return app(EnrollmentRolloverItemExecutionService::class);
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

    /** Mirrors EnrollmentRolloverDryRunServiceTest's identical fixture helper. */
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

    /**
     * Builds a fully validated Plan with exactly one `ready` Item for a
     * freshly-enrolled Student, ready to be handed straight to
     * execute(). Every execution test starts from this shared shape.
     *
     * @return array{school: School, campus: Campus, sourceYear: AcademicYear, targetYear: AcademicYear, sourceGrade: GradeLevel, targetGrade: GradeLevel, sourceSection: Section, targetSection: Section, plan: EnrollmentRolloverPlan, student: Student, sourceEnrollment: StudentEnrollment, item: EnrollmentRolloverItem}
     */
    private function buildReadyContext(string $rollNumber = '011'): array
    {
        $ctx = $this->buildContext();
        ['school' => $school, 'sourceGrade' => $sourceGrade, 'targetGrade' => $targetGrade, 'sourceSection' => $sourceSection, 'targetSection' => $targetSection, 'plan' => $plan] = $ctx;

        $student = $this->createStudent($school, ['student_number' => 'S-1001']);
        $sourceEnrollment = $this->enrollmentService()->enroll($student, $sourceSection, '007', '2026-06-01');
        $this->planService()->upsertMapping($plan, $sourceGrade, null, $targetGrade, $targetSection);

        $item = $this->itemFor($plan, $student);
        $this->planService()->setItemDecision($this->freshPlan($plan), $item, 'promote', null, 'explicit', $rollNumber);

        $summary = $this->dryRun()->run($this->freshPlan($plan));
        $this->assertTrue($summary['validated'], 'fixture setup must produce a validated plan');

        $item = $this->itemFor($this->freshPlan($plan), $student);
        $this->assertSame('ready', $item->validation_result);

        return [...$ctx, 'student' => $student, 'sourceEnrollment' => $sourceEnrollment, 'item' => $item];
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

    // ==================================================================
    // Sections 45/46 -- Plan/configuration readiness gate
    // ==================================================================

    #[Test]
    public function execution_rejects_a_draft_plan(): void
    {
        ['plan' => $plan, 'item' => $item] = $this->buildReadyContext();
        // Query-builder update, not $plan->update() -- $plan is a stale
        // in-memory instance (created before dry-run validated it), so
        // an instance-level update() would see 'draft' -> 'draft' as no
        // change at all and silently skip issuing any SQL.
        app(TenantContext::class)->withSchool($plan->school, fn () => EnrollmentRolloverPlan::query()->whereKey($plan->id)->update(['status' => 'draft']));

        $this->expectException(RolloverPlanNotExecutionReadyException::class);
        $this->execution()->execute($item);
    }

    #[Test]
    public function execution_rejects_a_stale_configuration_version(): void
    {
        ['plan' => $plan, 'item' => $item, 'school' => $school, 'sourceGrade' => $sourceGrade, 'targetGrade' => $targetGrade, 'targetSection' => $targetSection] = $this->buildReadyContext();

        // status stays 'validated' -- upsertMapping() never touches it --
        // but configuration_version now no longer equals
        // validated_configuration_version (this checkpoint's brief,
        // section 45/46; EnrollmentRolloverPlan::isValidatedForCurrentConfiguration()).
        $this->planService()->upsertMapping($this->freshPlan($plan), $sourceGrade, null, $targetGrade, $targetSection);

        $this->expectException(RolloverPlanNotExecutionReadyException::class);
        $this->execution()->execute($this->freshItem($item));
    }

    #[Test]
    public function execution_defense_in_depth_rejects_a_review_or_blocked_item(): void
    {
        ['plan' => $plan, 'item' => $item, 'school' => $school] = $this->buildReadyContext();

        // Simulates a hypothetical divergence between Plan-level and
        // Item-level state (section 44's "defense in depth" -- this
        // should be unreachable via the public service surface, since a
        // validated Plan guarantees zero review/blocked Items).
        app(TenantContext::class)->withSchool($school, fn () => $item->update(['validation_result' => 'review']));

        $this->expectException(RolloverItemNotExecutableException::class);
        $this->execution()->execute($this->freshItem($item));
    }

    // ==================================================================
    // Section 43 -- Excluded item
    // ==================================================================

    #[Test]
    public function excluded_item_is_skipped_with_zero_academic_writes(): void
    {
        $ctx = $this->buildContext();
        ['school' => $school, 'sourceSection' => $sourceSection, 'plan' => $plan] = $ctx;
        $student = $this->createStudent($school, ['student_number' => 'S-2001']);
        $this->enrollmentService()->enroll($student, $sourceSection, '01', '2026-06-01');

        $item = $this->itemFor($plan, $student);
        $this->planService()->setItemDecision($this->freshPlan($plan), $item, 'exclude');
        $summary = $this->dryRun()->run($this->freshPlan($plan));
        $this->assertTrue($summary['validated']);

        $item = $this->itemFor($this->freshPlan($plan), $student);
        $this->assertSame('excluded', $item->validation_result);

        $before = $this->fingerprint($school);
        $result = $this->execution()->execute($item);
        $after = $this->fingerprint($school);

        $this->assertSame('skipped', $result->execution_status);
        $this->assertNull($result->target_enrollment_id);
        $this->assertEquals($before, $after, 'an excluded Item must produce zero academic-state writes');
    }

    // ==================================================================
    // Sections 47/49 -- basic promotion, source non-completion
    // ==================================================================

    #[Test]
    public function basic_promotion_creates_the_target_enrollment_and_never_touches_the_source(): void
    {
        ['school' => $school, 'campus' => $campus, 'targetYear' => $targetYear, 'targetGrade' => $targetGrade, 'targetSection' => $targetSection, 'student' => $student, 'sourceEnrollment' => $sourceEnrollment, 'item' => $item] = $this->buildReadyContext('011');

        $result = $this->execution()->execute($item);

        $this->assertSame('succeeded', $result->execution_status);
        $this->assertNotNull($result->target_enrollment_id);
        $this->assertNotNull($result->executed_at);

        $target = app(TenantContext::class)->withSchool($school, fn () => StudentEnrollment::query()->findOrFail($result->target_enrollment_id));
        $this->assertSame($student->id, $target->student_id);
        $this->assertSame($targetYear->id, $target->academic_year_id);
        $this->assertSame($campus->id, $target->campus_id);
        $this->assertSame($targetGrade->id, $target->grade_level_id);
        $this->assertSame($targetSection->id, $target->section_id);
        $this->assertSame('011', $target->roll_number);
        $this->assertSame('active', $target->status);

        $freshSource = app(TenantContext::class)->withSchool($school, fn () => $sourceEnrollment->fresh());
        $this->assertSame('active', $freshSource->status, 'source Enrollment must remain active -- rollover never completes it');
        $this->assertNull($freshSource->ends_on);
        $this->assertSame($sourceEnrollment->section_id, $freshSource->section_id);
        $this->assertSame($sourceEnrollment->grade_level_id, $freshSource->grade_level_id);
        $this->assertSame($sourceEnrollment->roll_number, $freshSource->roll_number);
    }

    // ==================================================================
    // Section 48 -- completed source
    // ==================================================================

    #[Test]
    public function a_completed_source_enrollment_may_still_execute_and_remains_completed(): void
    {
        $ctx = $this->buildContext();
        ['school' => $school, 'sourceGrade' => $sourceGrade, 'targetGrade' => $targetGrade, 'sourceSection' => $sourceSection, 'targetSection' => $targetSection, 'plan' => $plan] = $ctx;
        $student = $this->createStudent($school, ['student_number' => 'S-3001']);
        $sourceEnrollment = $this->enrollmentService()->enroll($student, $sourceSection, '01', '2026-06-01');
        $this->enrollmentService()->complete($sourceEnrollment, '2027-04-30');
        $this->planService()->upsertMapping($plan, $sourceGrade, null, $targetGrade, $targetSection);

        $item = $this->itemFor($plan, $student);
        $this->planService()->setItemDecision($this->freshPlan($plan), $item, 'promote', null, 'explicit', '099');
        $summary = $this->dryRun()->run($this->freshPlan($plan));
        $this->assertTrue($summary['validated']);
        $item = $this->itemFor($this->freshPlan($plan), $student);

        $result = $this->execution()->execute($item);

        $this->assertSame('succeeded', $result->execution_status);
        $freshSource = app(TenantContext::class)->withSchool($school, fn () => $sourceEnrollment->fresh());
        $this->assertSame('completed', $freshSource->status, 'a completed source must remain completed, never reactivated');
    }

    // ==================================================================
    // Section 50 -- repeat
    // ==================================================================

    #[Test]
    public function repeat_decision_creates_a_same_grade_target_enrollment(): void
    {
        $ctx = $this->buildContext();
        ['school' => $school, 'campus' => $campus, 'sourceGrade' => $sourceGrade, 'sourceSection' => $sourceSection, 'targetYear' => $targetYear, 'plan' => $plan] = $ctx;
        $repeatSection = $this->createSection($targetYear, $campus, $sourceGrade, ['name' => '5C', 'code' => '5C']);

        $student = $this->createStudent($school, ['student_number' => 'S-4001']);
        $this->enrollmentService()->enroll($student, $sourceSection, '01', '2026-06-01');
        $this->planService()->upsertMapping($plan, $sourceGrade, null, $sourceGrade, $repeatSection);

        $item = $this->itemFor($plan, $student);
        $this->planService()->setItemDecision($this->freshPlan($plan), $item, 'repeat', null, 'explicit', '050');
        $summary = $this->dryRun()->run($this->freshPlan($plan));
        $this->assertTrue($summary['validated']);
        $item = $this->itemFor($this->freshPlan($plan), $student);

        $result = $this->execution()->execute($item);

        $this->assertSame('succeeded', $result->execution_status);
        $target = app(TenantContext::class)->withSchool($school, fn () => StudentEnrollment::query()->findOrFail($result->target_enrollment_id));
        $this->assertSame($sourceGrade->id, $target->grade_level_id);
        $this->assertSame($repeatSection->id, $target->section_id);
        $this->assertSame('active', $target->status, 'repeat is a rollover decision, never a distinct Enrollment status');
    }

    // ==================================================================
    // Section 51 -- cross-campus promotion
    // ==================================================================

    #[Test]
    public function cross_campus_promotion_derives_the_new_campus_from_the_target_section(): void
    {
        $ctx = $this->buildContext();
        ['school' => $school, 'sourceGrade' => $sourceGrade, 'targetGrade' => $targetGrade, 'sourceSection' => $sourceSection, 'targetYear' => $targetYear, 'plan' => $plan] = $ctx;
        $campusB = $this->createCampus($school, ['name' => 'Campus B', 'code' => 'CB']);
        $targetSectionOnCampusB = $this->createSection($targetYear, $campusB, $targetGrade, ['name' => '6D', 'code' => '6D']);

        $student = $this->createStudent($school, ['student_number' => 'S-5001']);
        $this->enrollmentService()->enroll($student, $sourceSection, '01', '2026-06-01');
        $this->planService()->upsertMapping($plan, $sourceGrade, null, $targetGrade, $targetSectionOnCampusB);

        $item = $this->itemFor($plan, $student);
        $this->planService()->setItemDecision($this->freshPlan($plan), $item, 'promote', null, 'explicit', '060');
        $summary = $this->dryRun()->run($this->freshPlan($plan));
        $this->assertTrue($summary['validated']);
        $item = $this->itemFor($this->freshPlan($plan), $student);

        $result = $this->execution()->execute($item);

        $target = app(TenantContext::class)->withSchool($school, fn () => StudentEnrollment::query()->findOrFail($result->target_enrollment_id));
        $this->assertSame($campusB->id, $target->campus_id);
        $this->assertSame($targetSectionOnCampusB->id, $target->section_id);
    }

    // ==================================================================
    // Section 52 -- already-enrolled exact match reconciliation
    // ==================================================================

    #[Test]
    public function already_enrolled_exact_match_reconciles_without_creating_a_new_enrollment(): void
    {
        $ctx = $this->buildContext();
        ['school' => $school, 'sourceGrade' => $sourceGrade, 'targetGrade' => $targetGrade, 'sourceSection' => $sourceSection, 'targetSection' => $targetSection, 'plan' => $plan] = $ctx;
        $student = $this->createStudent($school, ['student_number' => 'S-6001']);
        $this->enrollmentService()->enroll($student, $sourceSection, '01', '2026-06-01');
        $preExisting = $this->enrollmentService()->enroll($student, $targetSection, '070', '2027-06-01');
        $this->planService()->upsertMapping($plan, $sourceGrade, null, $targetGrade, $targetSection);

        $item = $this->itemFor($plan, $student);
        $this->planService()->setItemDecision($this->freshPlan($plan), $item, 'promote', null, 'explicit', '070');
        $summary = $this->dryRun()->run($this->freshPlan($plan));
        $this->assertTrue($summary['validated']);
        $item = $this->itemFor($this->freshPlan($plan), $student);
        $this->assertSame('already_enrolled', $item->validation_result);

        $enrollmentCountBefore = app(TenantContext::class)->withSchool($school, fn () => StudentEnrollment::query()->count());
        $result = $this->execution()->execute($item);
        $enrollmentCountAfter = app(TenantContext::class)->withSchool($school, fn () => StudentEnrollment::query()->count());

        $this->assertSame('reconciled', $result->execution_status);
        $this->assertSame($preExisting->id, $result->target_enrollment_id);
        $this->assertSame($enrollmentCountBefore, $enrollmentCountAfter, 'reconciliation must create zero new Enrollments');
    }

    // ==================================================================
    // Section 53 -- idempotent retry
    // ==================================================================

    #[Test]
    public function retrying_execution_of_an_already_succeeded_item_is_a_pure_no_op(): void
    {
        ['school' => $school, 'item' => $item] = $this->buildReadyContext();

        $first = $this->execution()->execute($item);
        $enrollmentCountAfterFirst = app(TenantContext::class)->withSchool($school, fn () => StudentEnrollment::query()->count());
        $auditCountAfterFirst = app(TenantContext::class)->withSchool($school, fn () => DB::table('school_audit_events')->where('event_type', 'student_enrollment.created')->count());

        $second = $this->execution()->execute($this->freshItem($item));
        $enrollmentCountAfterSecond = app(TenantContext::class)->withSchool($school, fn () => StudentEnrollment::query()->count());
        $auditCountAfterSecond = app(TenantContext::class)->withSchool($school, fn () => DB::table('school_audit_events')->where('event_type', 'student_enrollment.created')->count());

        $this->assertSame($first->target_enrollment_id, $second->target_enrollment_id);
        $this->assertSame($enrollmentCountAfterFirst, $enrollmentCountAfterSecond, 'a retried execute() must never create a second target Enrollment');
        $this->assertSame($auditCountAfterFirst, $auditCountAfterSecond, 'a retried execute() must never record a second creation audit event');
    }

    // ==================================================================
    // Section 54 -- external target conflict (different existing Enrollment)
    // ==================================================================

    #[Test]
    public function a_different_pre_existing_target_enrollment_invalidates_the_plan_instead_of_being_overwritten(): void
    {
        ['school' => $school, 'targetYear' => $targetYear, 'campus' => $campus, 'targetGrade' => $targetGrade, 'plan' => $plan, 'student' => $student, 'item' => $item] = $this->buildReadyContext('011');
        $otherSection = $this->createSection($targetYear, $campus, $targetGrade, ['name' => '6C', 'code' => '6C']);
        $manualTarget = $this->enrollmentService()->enroll($student, $otherSection, '999', '2027-06-01');

        $result = $this->execution()->execute($item);

        $this->assertSame('failed', $result->execution_status);
        $this->assertNull($result->target_enrollment_id);
        $this->assertSame('blocked', $result->validation_result);
        $this->assertSame('existing_target_enrollment_conflict_at_execution', $result->validation_reason);

        $freshManualTarget = app(TenantContext::class)->withSchool($school, fn () => $manualTarget->fresh());
        $this->assertSame('active', $freshManualTarget->status, 'the pre-existing manual target must never be overwritten/transferred');

        $freshPlan = $this->freshPlan($plan);
        $this->assertSame('draft', $freshPlan->status);
        $this->assertNull($freshPlan->validated_configuration_version);
    }

    // ==================================================================
    // Section 55 -- roll number conflict after dry-run
    // ==================================================================

    #[Test]
    public function a_roll_number_claimed_by_another_student_after_validation_is_detected_and_recoverable(): void
    {
        ['school' => $school, 'targetSection' => $targetSection, 'sourceSection' => $sourceSection, 'plan' => $plan, 'item' => $item] = $this->buildReadyContext('011');
        $otherStudent = $this->createStudent($school, ['student_number' => 'S-7001']);
        $this->enrollmentService()->enroll($otherStudent, $sourceSection, '02', '2026-06-01');
        $this->enrollmentService()->enroll($otherStudent, $targetSection, '011', '2027-06-01');

        $result = $this->execution()->execute($item);

        $this->assertSame('failed', $result->execution_status);
        $this->assertNull($result->target_enrollment_id);
        $this->assertSame('blocked', $result->validation_result);

        $freshPlan = $this->freshPlan($plan);
        $this->assertSame('draft', $freshPlan->status);

        // The connection must remain healthy after this conflict -- no
        // SQLSTATE 25P02 leaks into the NEXT, unrelated operation.
        $newSchool = $this->createSchool();
        $this->assertNotNull($newSchool->id);
    }

    // ==================================================================
    // Section 56 -- source changed after dry-run (sanctioned transfer)
    // ==================================================================

    #[Test]
    public function a_source_transfer_after_validation_is_detected_and_never_auto_replaces_the_source(): void
    {
        ['school' => $school, 'campus' => $campus, 'sourceYear' => $sourceYear, 'sourceGrade' => $sourceGrade, 'sourceSection' => $sourceSection, 'plan' => $plan, 'student' => $student, 'sourceEnrollment' => $sourceEnrollment, 'item' => $item] = $this->buildReadyContext();
        $anotherSourceSection = $this->createSection($sourceYear, $campus, $sourceGrade, ['name' => '5D', 'code' => '5D']);
        $this->enrollmentService()->transferPlacement($sourceEnrollment, $anotherSourceSection, '007', '2026-09-01');

        $result = $this->execution()->execute($item);

        $this->assertSame('failed', $result->execution_status);
        $this->assertSame('source_changed_since_validation', $result->validation_reason);

        $freshOriginalSource = app(TenantContext::class)->withSchool($school, fn () => $sourceEnrollment->fresh());
        $this->assertSame('transferred', $freshOriginalSource->status, 'the legitimate transfer must not be rolled back');

        $freshPlan = $this->freshPlan($plan);
        $this->assertSame('draft', $freshPlan->status);
    }

    // ==================================================================
    // Section 57 -- target Section stale
    // ==================================================================

    #[Test]
    public function a_target_section_deactivated_after_validation_is_detected(): void
    {
        ['school' => $school, 'targetSection' => $targetSection, 'plan' => $plan, 'item' => $item] = $this->buildReadyContext();

        app(TenantContext::class)->withSchool($school, fn () => Section::query()->whereKey($targetSection->id)->update(['status' => 'inactive']));

        $result = $this->execution()->execute($item);

        $this->assertSame('failed', $result->execution_status);
        $this->assertSame('target_section_changed_since_validation', $result->validation_reason);
        $this->assertNull($result->target_enrollment_id);
    }

    // ==================================================================
    // Section 58 -- configuration version changed before execution
    // ==================================================================

    #[Test]
    public function a_mapping_change_after_validation_rejects_execution_before_any_mutation(): void
    {
        ['plan' => $plan, 'item' => $item, 'sourceGrade' => $sourceGrade, 'targetGrade' => $targetGrade, 'targetSection' => $targetSection, 'school' => $school] = $this->buildReadyContext();
        $this->planService()->upsertMapping($this->freshPlan($plan), $sourceGrade, null, $targetGrade, $targetSection);

        $before = $this->fingerprint($school);
        $this->expectException(RolloverPlanNotExecutionReadyException::class);

        try {
            $this->execution()->execute($this->freshItem($item));
        } finally {
            $after = $this->fingerprint($school);
            $this->assertEquals($before, $after, 'a rejected execution must produce zero academic-state writes');
        }
    }

    // ==================================================================
    // Section 61 -- target_enrollment_id provenance integrity
    // ==================================================================

    #[Test]
    public function an_item_already_pointing_at_a_matching_target_returns_it_unchanged(): void
    {
        ['school' => $school, 'item' => $item] = $this->buildReadyContext();
        $first = $this->execution()->execute($item);

        $result = $this->execution()->execute($this->freshItem($item));

        $this->assertSame($first->target_enrollment_id, $result->target_enrollment_id);
        $this->assertSame('succeeded', $result->execution_status);
    }

    #[Test]
    public function a_contradictory_target_enrollment_id_raises_an_integrity_exception(): void
    {
        $ctx = $this->buildReadyContext();
        ['school' => $school, 'item' => $item] = $ctx;
        $otherStudent = $this->createStudent($school, ['student_number' => 'S-8001']);
        $unrelatedTarget = $this->enrollmentService()->enroll($otherStudent, $ctx['targetSection'], '500', '2027-06-01');

        app(TenantContext::class)->withSchool($school, fn () => $item->update([
            'execution_status' => 'succeeded',
            'target_enrollment_id' => $unrelatedTarget->id,
            'executed_at' => now(),
        ]));

        $this->expectException(RuntimeException::class);
        $this->execution()->execute($this->freshItem($item));
    }

    // ==================================================================
    // Section 45's sibling -- setItemDecision() cannot reconfigure an
    // already-executed Item (primary defense for section 61)
    // ==================================================================

    #[Test]
    public function set_item_decision_refuses_to_reconfigure_an_already_executed_item(): void
    {
        ['plan' => $plan, 'item' => $item] = $this->buildReadyContext();
        $this->execution()->execute($item);

        $this->expectException(RolloverItemAlreadyExecutedException::class);
        $this->planService()->setItemDecision($this->freshPlan($plan), $this->freshItem($item), 'exclude');
    }

    // ==================================================================
    // Sections 64/65 -- academic-state fingerprint
    // ==================================================================

    #[Test]
    public function a_successful_execution_changes_exactly_one_student_enrollment_row(): void
    {
        ['school' => $school, 'item' => $item] = $this->buildReadyContext();

        $before = $this->fingerprint($school);
        $result = $this->execution()->execute($item);
        $after = $this->fingerprint($school);

        foreach (['students', 'academic_years', 'grade_levels', 'sections', 'campuses'] as $table) {
            $this->assertEquals($before[$table], $after[$table], "execution must not change any {$table} row");
        }

        $this->assertCount(count($before['student_enrollments']) + 1, $after['student_enrollments']);
        $newIds = collect($after['student_enrollments'])->pluck('id')->diff(collect($before['student_enrollments'])->pluck('id'));
        $this->assertSame([$result->target_enrollment_id], $newIds->values()->all());
    }

    #[Test]
    public function a_failed_execution_leaves_every_academic_table_byte_identical(): void
    {
        ['school' => $school, 'targetSection' => $targetSection, 'targetYear' => $targetYear, 'campus' => $campus, 'targetGrade' => $targetGrade, 'student' => $student, 'item' => $item] = $this->buildReadyContext('011');
        $otherSection = $this->createSection($targetYear, $campus, $targetGrade, ['name' => '6E', 'code' => '6E']);
        $this->enrollmentService()->enroll($student, $otherSection, '900', '2027-06-01');

        $before = $this->fingerprint($school);
        $result = $this->execution()->execute($item);
        $after = $this->fingerprint($school);

        $this->assertSame('failed', $result->execution_status);
        $this->assertEquals($before, $after, 'a known execution-time conflict must leave every academic-state row unchanged');
    }
}
