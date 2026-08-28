<?php

namespace Tests\Feature\StudentEnrollment;

use App\Domain\AcademicStructure\Infrastructure\AcademicYear;
use App\Domain\AcademicStructure\Infrastructure\GradeLevel;
use App\Domain\AcademicStructure\Infrastructure\Section;
use App\Domain\AcademicStructure\Infrastructure\Subject;
use App\Domain\AcademicStructure\Infrastructure\SubjectOffering;
use App\Domain\Students\Application\EnrollmentRolloverDryRunService;
use App\Domain\Students\Application\EnrollmentRolloverItemExecutionService;
use App\Domain\Students\Application\EnrollmentRolloverPlanService;
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
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 1G.3: `EnrollmentRolloverItemExecutionService`'s subject-elective
 * application -- the WRITE side of the same three-state mapping/legacy-
 * anchor rules `EnrollmentRolloverDryRunServiceTest`'s subject-mapping
 * suite already proves read-only, now actually creating
 * `StudentSubjectEnrollment` rows through the canonical
 * `StudentSubjectEnrollmentService::enroll()` -- inside the SAME
 * per-Item transaction placement already uses. Every test proves
 * source history is never touched and that a blocked elective demotes
 * the WHOLE Item, never a partial/duplicate write.
 */
class EnrollmentRolloverSubjectExecutionServiceTest extends TestCase
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

    private function subjectEnrollmentService(): StudentSubjectEnrollmentService
    {
        return app(StudentSubjectEnrollmentService::class);
    }

    /**
     * @return array{school: School, campus: Campus, sourceYear: AcademicYear, targetYear: AcademicYear, sourceGrade: GradeLevel, targetGrade: GradeLevel, sourceSection: Section, targetSection: Section, plan: EnrollmentRolloverPlan, subject: Subject, sourceOffering: SubjectOffering, targetOffering: SubjectOffering}
     */
    private function buildSubjectContext(): array
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
        $subject = $this->createSubject($school);
        $sourceOffering = $this->createSubjectOffering($sourceYear, $campus, $sourceGrade, $subject, ['is_required' => false]);
        $targetOffering = $this->createSubjectOffering($targetYear, $campus, $targetGrade, $subject, ['is_required' => false]);

        return compact('school', 'campus', 'sourceYear', 'targetYear', 'sourceGrade', 'targetGrade', 'sourceSection', 'targetSection', 'plan', 'subject', 'sourceOffering', 'targetOffering');
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

    private function activeTargetSubjectRows(School $school, string $targetEnrollmentId): Collection
    {
        return app(TenantContext::class)->withSchool($school, fn () => StudentSubjectEnrollment::query()
            ->where('student_enrollment_id', $targetEnrollmentId)
            ->where('status', 'active')
            ->get());
    }

    private function fingerprint(School $school): array
    {
        return app(TenantContext::class)->withSchool($school, fn () => [
            'student_enrollments' => DB::table('student_enrollments')->orderBy('id')->get()->toArray(),
            'student_subject_enrollments' => DB::table('student_subject_enrollments')->orderBy('id')->get()->toArray(),
        ]);
    }

    /**
     * Builds a `ready` Item for a freshly-enrolled Student with exactly
     * one active source elective, mapped (or omitted) as the caller
     * requests, then validates it. Placement is a fresh promotion (the
     * target StudentEnrollment does not exist yet) unless
     * `$preExistingTargetRollNumber` is given, in which case the
     * Student is placed in the target year FIRST (an `already_enrolled`
     * Item).
     *
     * @return array{school: School, campus: Campus, sourceYear: AcademicYear, targetYear: AcademicYear, sourceGrade: GradeLevel, targetGrade: GradeLevel, sourceSection: Section, targetSection: Section, plan: EnrollmentRolloverPlan, subject: Subject, sourceOffering: SubjectOffering, targetOffering: SubjectOffering, student: Student, item: EnrollmentRolloverItem}
     */
    private function buildSubjectReadyContext(
        ?SubjectOffering $mapTo = null,
        bool $configureOmit = false,
        ?string $preExistingTargetRollNumber = null,
        string $rollNumber = '011',
    ): array {
        $ctx = $this->buildSubjectContext();
        ['school' => $school, 'sourceSection' => $sourceSection, 'targetSection' => $targetSection, 'sourceGrade' => $sourceGrade, 'targetGrade' => $targetGrade, 'plan' => $plan, 'sourceOffering' => $sourceOffering, 'targetOffering' => $targetOffering] = $ctx;

        $student = $this->createStudent($school, ['student_number' => 'S-1001']);
        $this->enrollmentService()->enroll($student, $sourceSection, '007', '2026-06-01');
        $this->subjectEnrollmentService()->enroll($student, $sourceOffering, '2026-06-01');

        if ($preExistingTargetRollNumber !== null) {
            $this->enrollmentService()->enroll($student, $targetSection, $preExistingTargetRollNumber, '2027-06-01');
        }

        if ($configureOmit) {
            $this->planService()->upsertSubjectMapping($plan, $sourceOffering, null);
        } else {
            $this->planService()->upsertSubjectMapping($plan, $sourceOffering, $mapTo ?? $targetOffering);
        }

        $this->planService()->upsertMapping($plan, $sourceGrade, null, $targetGrade, $targetSection);
        $item = $this->itemFor($plan, $student);
        $effectiveRollNumber = $preExistingTargetRollNumber ?? $rollNumber;
        app(TenantContext::class)->withSchool($school, fn () => $item->update(['decision' => 'promote', 'roll_number_strategy' => 'explicit', 'target_roll_number' => $effectiveRollNumber]));

        $summary = $this->dryRun()->run($this->freshPlan($plan));
        $this->assertTrue($summary['validated'], 'fixture setup must produce a validated plan');
        $fresh = $this->itemFor($this->freshPlan($plan), $student);
        $this->assertContains($fresh->validation_result, ['ready', 'already_enrolled'], 'fixture setup must produce an executable item');

        return [...$ctx, 'student' => $student, 'item' => $fresh];
    }

    // ==================================================================
    // Section 55 -- success matrix
    // ==================================================================

    #[Test]
    public function one_mapped_elective_creates_the_target_participation_anchored_to_the_target_enrollment(): void
    {
        ['school' => $school, 'targetOffering' => $targetOffering, 'item' => $item] = $this->buildSubjectReadyContext();

        $result = $this->execution()->execute($item);

        $this->assertSame('succeeded', $result->execution_status);
        $row = app(TenantContext::class)->withSchool($school, fn () => StudentSubjectEnrollment::query()
            ->where('student_enrollment_id', $result->target_enrollment_id)
            ->where('subject_offering_id', $targetOffering->id)
            ->where('status', 'active')
            ->first());
        $this->assertNotNull($row, 'the mapped target elective must be created');
        $this->assertSame($result->target_enrollment_id, $row->student_enrollment_id);
    }

    #[Test]
    public function target_participation_snapshots_the_target_offerings_current_elective_group(): void
    {
        $ctx = $this->buildSubjectContext();
        $group = $this->createElectiveGroup($ctx['targetYear'], $ctx['campus'], $ctx['targetGrade']);
        app(TenantContext::class)->withSchool($ctx['school'], fn () => $ctx['targetOffering']->update(['elective_group_id' => $group->id]));

        ['school' => $school, 'item' => $item] = $this->buildSubjectReadyContextFrom($ctx);

        $result = $this->execution()->execute($item);

        $row = $this->activeTargetSubjectRows($school, $result->target_enrollment_id)->first();
        $this->assertSame($group->id, $row->elective_group_id);
    }

    #[Test]
    public function ungrouped_target_stores_a_null_elective_group(): void
    {
        ['school' => $school, 'item' => $item] = $this->buildSubjectReadyContext();

        $result = $this->execution()->execute($item);

        $row = $this->activeTargetSubjectRows($school, $result->target_enrollment_id)->first();
        $this->assertNull($row->elective_group_id);
    }

    #[Test]
    public function explicit_omit_creates_no_target_participation(): void
    {
        ['school' => $school, 'item' => $item] = $this->buildSubjectReadyContext(configureOmit: true);

        $result = $this->execution()->execute($item);

        $this->assertSame('succeeded', $result->execution_status);
        $this->assertCount(0, $this->activeTargetSubjectRows($school, $result->target_enrollment_id));
    }

    #[Test]
    public function two_mapped_electives_in_different_groups_both_create(): void
    {
        $ctx = $this->buildSubjectContext();
        ['school' => $school, 'campus' => $campus, 'sourceYear' => $sourceYear, 'targetYear' => $targetYear, 'sourceGrade' => $sourceGrade, 'targetGrade' => $targetGrade, 'plan' => $plan, 'sourceOffering' => $offeringA, 'targetOffering' => $targetA] = $ctx;
        $groupX = $this->createElectiveGroup($targetYear, $campus, $targetGrade);
        $groupY = $this->createElectiveGroup($targetYear, $campus, $targetGrade);
        app(TenantContext::class)->withSchool($school, fn () => $targetA->update(['elective_group_id' => $groupX->id]));
        $subjectB = $this->createSubject($school);
        $offeringB = $this->createSubjectOffering($sourceYear, $campus, $sourceGrade, $subjectB, ['is_required' => false]);
        $targetB = $this->createSubjectOffering($targetYear, $campus, $targetGrade, $subjectB, ['is_required' => false, 'elective_group_id' => $groupY->id]);

        ['student' => $student, 'item' => $item] = $this->wireTwoElectiveItem($ctx, $offeringA, $targetA, $offeringB, $targetB);

        $result = $this->execution()->execute($item);

        $this->assertSame('succeeded', $result->execution_status);
        $rows = $this->activeTargetSubjectRows($school, $result->target_enrollment_id);
        $this->assertCount(2, $rows);
        $this->assertEqualsCanonicalizing([$targetA->id, $targetB->id], $rows->pluck('subject_offering_id')->all());
    }

    #[Test]
    public function two_mapped_ungrouped_electives_both_create(): void
    {
        $ctx = $this->buildSubjectContext();
        ['school' => $school, 'campus' => $campus, 'sourceYear' => $sourceYear, 'targetYear' => $targetYear, 'sourceGrade' => $sourceGrade, 'targetGrade' => $targetGrade, 'sourceOffering' => $offeringA, 'targetOffering' => $targetA] = $ctx;
        $subjectB = $this->createSubject($school);
        $offeringB = $this->createSubjectOffering($sourceYear, $campus, $sourceGrade, $subjectB, ['is_required' => false]);
        $targetB = $this->createSubjectOffering($targetYear, $campus, $targetGrade, $subjectB, ['is_required' => false]);

        ['item' => $item] = $this->wireTwoElectiveItem($ctx, $offeringA, $targetA, $offeringB, $targetB);

        $result = $this->execution()->execute($item);

        $this->assertSame('succeeded', $result->execution_status);
        $this->assertCount(2, $this->activeTargetSubjectRows($school, $result->target_enrollment_id));
    }

    #[Test]
    public function exact_target_already_active_reconciles_without_a_duplicate(): void
    {
        $ctx = $this->buildSubjectContext();
        $student = $this->createStudent($ctx['school'], ['student_number' => 'S-2002']);
        $this->enrollmentService()->enroll($student, $ctx['sourceSection'], '01', '2026-06-01');
        $this->subjectEnrollmentService()->enroll($student, $ctx['sourceOffering'], '2026-06-01');
        $this->enrollmentService()->enroll($student, $ctx['targetSection'], '007', '2027-06-01');
        $this->subjectEnrollmentService()->enroll($student, $ctx['targetOffering'], '2027-06-01');
        $this->planService()->upsertSubjectMapping($ctx['plan'], $ctx['sourceOffering'], $ctx['targetOffering']);
        $this->planService()->upsertMapping($ctx['plan'], $ctx['sourceGrade'], null, $ctx['targetGrade'], $ctx['targetSection']);
        $item = $this->itemFor($ctx['plan'], $student);
        $this->planService()->setItemDecision($this->freshPlan($ctx['plan']), $item, 'promote', null, 'explicit', '007');
        $summary = $this->dryRun()->run($this->freshPlan($ctx['plan']));
        $this->assertTrue($summary['validated']);
        $item = $this->itemFor($this->freshPlan($ctx['plan']), $student);
        $this->assertSame('already_enrolled', $item->validation_result);

        $result = $this->execution()->execute($item);

        $this->assertSame('reconciled', $result->execution_status);
        $rows = $this->activeTargetSubjectRows($ctx['school'], $result->target_enrollment_id);
        $this->assertCount(1, $rows, 'no duplicate target elective must be created for an already-satisfied mapping');
    }

    #[Test]
    public function exact_target_already_satisfied_plus_one_missing_target_both_resolve_correctly(): void
    {
        $ctx = $this->buildSubjectContext();
        ['school' => $school, 'campus' => $campus, 'sourceYear' => $sourceYear, 'targetYear' => $targetYear, 'sourceGrade' => $sourceGrade, 'targetGrade' => $targetGrade, 'targetSection' => $targetSection, 'plan' => $plan, 'sourceOffering' => $offeringA, 'targetOffering' => $targetA] = $ctx;
        $subjectB = $this->createSubject($school);
        $offeringB = $this->createSubjectOffering($sourceYear, $campus, $sourceGrade, $subjectB, ['is_required' => false]);
        $targetB = $this->createSubjectOffering($targetYear, $campus, $targetGrade, $subjectB, ['is_required' => false]);

        $student = $this->createStudent($school, ['student_number' => 'S-3003']);
        $this->enrollmentService()->enroll($student, $ctx['sourceSection'], '01', '2026-06-01');
        $this->subjectEnrollmentService()->enroll($student, $offeringA, '2026-06-01');
        $this->subjectEnrollmentService()->enroll($student, $offeringB, '2026-06-01');
        $this->enrollmentService()->enroll($student, $targetSection, '007', '2027-06-01');
        $this->subjectEnrollmentService()->enroll($student, $targetA, '2027-06-01'); // A already satisfied

        $this->planService()->upsertSubjectMapping($plan, $offeringA, $targetA);
        $this->planService()->upsertSubjectMapping($plan, $offeringB, $targetB);
        $this->planService()->upsertMapping($plan, $sourceGrade, null, $targetGrade, $targetSection);
        $item = $this->itemFor($plan, $student);
        $this->planService()->setItemDecision($this->freshPlan($plan), $item, 'promote', null, 'explicit', '007');
        $summary = $this->dryRun()->run($this->freshPlan($plan));
        $this->assertTrue($summary['validated']);
        $item = $this->itemFor($this->freshPlan($plan), $student);

        $result = $this->execution()->execute($item);

        $this->assertSame('reconciled', $result->execution_status);
        $rows = $this->activeTargetSubjectRows($school, $result->target_enrollment_id);
        $this->assertCount(2, $rows, 'A stays satisfied (no duplicate), B is newly created');
        $this->assertEqualsCanonicalizing([$targetA->id, $targetB->id], $rows->pluck('subject_offering_id')->all());
    }

    #[Test]
    public function an_item_with_zero_eligible_electives_executes_unchanged(): void
    {
        $ctx = $this->buildSubjectContext();
        ['school' => $school, 'sourceSection' => $sourceSection, 'targetSection' => $targetSection, 'sourceGrade' => $sourceGrade, 'targetGrade' => $targetGrade, 'plan' => $plan] = $ctx;
        $student = $this->createStudent($school, ['student_number' => 'S-4004']);
        $this->enrollmentService()->enroll($student, $sourceSection, '01', '2026-06-01');
        // No source elective participation at all.
        $this->planService()->upsertMapping($plan, $sourceGrade, null, $targetGrade, $targetSection);
        $item = $this->itemFor($plan, $student);
        app(TenantContext::class)->withSchool($school, fn () => $item->update(['decision' => 'promote', 'roll_number_strategy' => 'explicit', 'target_roll_number' => '021']));
        $summary = $this->dryRun()->run($this->freshPlan($plan));
        $this->assertTrue($summary['validated']);
        $item = $this->itemFor($this->freshPlan($plan), $student);

        $result = $this->execution()->execute($item);

        $this->assertSame('succeeded', $result->execution_status);
        $this->assertCount(0, $this->activeTargetSubjectRows($school, $result->target_enrollment_id));
    }

    // ==================================================================
    // Section 56 -- post-validation drift / defensive failures
    // ==================================================================

    #[Test]
    public function a_new_unmapped_source_elective_added_after_validation_defensively_fails_the_item(): void
    {
        ['school' => $school, 'campus' => $campus, 'sourceYear' => $sourceYear, 'sourceGrade' => $sourceGrade, 'targetOffering' => $targetOffering, 'item' => $item, 'student' => $student] = $this->buildSubjectReadyContext();
        // Drift: a new active source elective appears for this Student's
        // SAME source Enrollment after the plan was last validated --
        // never touches subject-mapping config, so configuration_version
        // is untouched and execute() reaches applySubjectElectives().
        $unmappedSubject = $this->createSubject($school);
        $unmappedOffering = $this->createSubjectOffering($sourceYear, $campus, $sourceGrade, $unmappedSubject, ['is_required' => false]);
        $sourceEnrollment = app(TenantContext::class)->withSchool($school, fn () => StudentEnrollment::query()->where('student_id', $student->id)->where('academic_year_id', $sourceYear->id)->firstOrFail());
        app(TenantContext::class)->withSchool($school, fn () => StudentSubjectEnrollment::query()->create([
            'school_id' => $school->id,
            'student_id' => $student->id,
            'student_enrollment_id' => $sourceEnrollment->id,
            'subject_offering_id' => $unmappedOffering->id,
            'academic_year_id' => $sourceYear->id,
            'status' => 'active',
            'starts_on' => '2026-06-01',
        ]));

        $before = $this->fingerprint($school);
        $result = $this->execution()->execute($this->freshItem($item));
        $after = $this->fingerprint($school);

        $this->assertSame('failed', $result->execution_status);
        $this->assertSame('missing_subject_mapping', $result->validation_reason);
        $this->assertNull($result->target_enrollment_id);
        $this->assertEquals($before['student_enrollments'], $after['student_enrollments'], 'no StudentEnrollment write on defensive failure');
        $sub = collect($after['student_subject_enrollments'])->firstWhere('subject_offering_id', $targetOffering->id);
        $this->assertNull($sub, 'no target elective must be created when the item fails defensively');
    }

    #[Test]
    public function a_target_offering_deactivated_after_validation_defensively_fails_the_item(): void
    {
        ['school' => $school, 'targetOffering' => $targetOffering, 'item' => $item] = $this->buildSubjectReadyContext();
        app(TenantContext::class)->withSchool($school, fn () => $targetOffering->update(['status' => 'inactive']));

        $result = $this->execution()->execute($this->freshItem($item));

        $this->assertSame('failed', $result->execution_status);
        $this->assertSame('elective_target_inactive', $result->validation_reason);
        $this->assertNull($result->target_enrollment_id);
    }

    #[Test]
    public function a_target_offering_that_became_required_after_validation_defensively_fails_the_item(): void
    {
        ['school' => $school, 'targetOffering' => $targetOffering, 'item' => $item] = $this->buildSubjectReadyContext();
        app(TenantContext::class)->withSchool($school, fn () => $targetOffering->update(['is_required' => true]));

        $result = $this->execution()->execute($this->freshItem($item));

        $this->assertSame('failed', $result->execution_status);
        $this->assertSame('elective_target_required', $result->validation_reason);
        $this->assertNull($result->target_enrollment_id);
    }

    #[Test]
    public function a_target_offering_context_changed_after_validation_defensively_fails_the_item(): void
    {
        ['school' => $school, 'targetOffering' => $targetOffering, 'item' => $item] = $this->buildSubjectReadyContext();
        $otherCampus = $this->createCampus($school);
        app(TenantContext::class)->withSchool($school, fn () => $targetOffering->update(['campus_id' => $otherCampus->id]));

        $result = $this->execution()->execute($this->freshItem($item));

        $this->assertSame('failed', $result->execution_status);
        $this->assertSame('elective_target_context_mismatch', $result->validation_reason);
        $this->assertNull($result->target_enrollment_id);
    }

    #[Test]
    public function an_existing_different_offering_appearing_in_the_target_group_after_validation_fails_the_item(): void
    {
        $ctx = $this->buildSubjectContext();
        $group = $this->createElectiveGroup($ctx['targetYear'], $ctx['campus'], $ctx['targetGrade']);
        app(TenantContext::class)->withSchool($ctx['school'], fn () => $ctx['targetOffering']->update(['elective_group_id' => $group->id]));
        $otherSubject = $this->createSubject($ctx['school']);
        $conflicting = $this->createSubjectOffering($ctx['targetYear'], $ctx['campus'], $ctx['targetGrade'], $otherSubject, ['is_required' => false, 'elective_group_id' => $group->id]);

        ['school' => $school, 'item' => $item, 'student' => $student] = $this->buildSubjectReadyContextFrom($ctx, preExistingTargetRollNumber: '007');

        // Drift: a conflicting Group-mate becomes active in the target
        // placement AFTER dry-run already validated a clean subject
        // evaluation -- never auto-withdrawn/replaced.
        $this->subjectEnrollmentService()->enroll($student, $conflicting, '2027-06-01');

        $result = $this->execution()->execute($this->freshItem($item));

        $this->assertSame('failed', $result->execution_status);
        $this->assertSame('elective_target_existing_conflict', $result->validation_reason);
    }

    // ==================================================================
    // Section 26/36 -- whole-Item atomicity
    // ==================================================================

    #[Test]
    public function the_second_of_two_mapped_electives_failing_rolls_back_the_first_and_the_fresh_target_enrollment(): void
    {
        $ctx = $this->buildSubjectContext();
        ['school' => $school, 'campus' => $campus, 'sourceYear' => $sourceYear, 'targetYear' => $targetYear, 'sourceGrade' => $sourceGrade, 'targetGrade' => $targetGrade, 'sourceOffering' => $offeringA, 'targetOffering' => $targetA] = $ctx;
        $groupX = $this->createElectiveGroup($targetYear, $campus, $targetGrade);
        $groupY = $this->createElectiveGroup($targetYear, $campus, $targetGrade);
        $groupZ = $this->createElectiveGroup($targetYear, $campus, $targetGrade);
        app(TenantContext::class)->withSchool($school, fn () => $targetA->update(['elective_group_id' => $groupX->id]));
        $subjectB = $this->createSubject($school);
        $offeringB = $this->createSubjectOffering($sourceYear, $campus, $sourceGrade, $subjectB, ['is_required' => false]);
        $targetB = $this->createSubjectOffering($targetYear, $campus, $targetGrade, $subjectB, ['is_required' => false, 'elective_group_id' => $groupY->id]);
        // A pre-existing, already-occupied Group Z -- distinct from both
        // A's and B's dry-run-time groups, so validation sees zero
        // conflict. This is what forces WHICHEVER elective is attempted
        // second (order is never assumed) into a genuine database-level
        // ElectiveGroupConflictException.
        $conflictingSubject = $this->createSubject($school);
        $conflictingOffering = $this->createSubjectOffering($targetYear, $campus, $targetGrade, $conflictingSubject, ['is_required' => false, 'elective_group_id' => $groupZ->id]);

        ['student' => $student, 'item' => $item] = $this->wireTwoElectiveItem($ctx, $offeringA, $targetA, $offeringB, $targetB, preExistingTargetRollNumber: '007');
        $targetEnrollment = app(TenantContext::class)->withSchool($school, fn () => StudentEnrollment::query()->where('student_id', $student->id)->where('academic_year_id', $targetYear->id)->firstOrFail());
        $this->subjectEnrollmentService()->enroll($student, $conflictingOffering, '2027-06-01');

        // Deterministically simulate a mid-attempt ElectiveGroup
        // reconfiguration (PHASE-1G-3 doc's post-validation-drift
        // scenario) WITHOUT real OS concurrency and WITHOUT assuming
        // iteration order: the instant EITHER mapped elective's row
        // commits, retarget the OTHER (not-yet-attempted) target
        // Offering into the already-occupied Group Z -- so whichever
        // elective is attempted second genuinely fails against the real
        // database constraint, not a stubbed exception.
        $firstOfferingId = null;
        StudentSubjectEnrollment::created(function (StudentSubjectEnrollment $enrollment) use (&$firstOfferingId, $targetA, $targetB, $groupZ) {
            if ($firstOfferingId !== null) {
                return;
            }
            $firstOfferingId = $enrollment->subject_offering_id;
            $other = $firstOfferingId === $targetA->id ? $targetB : $targetA;
            $other->update(['elective_group_id' => $groupZ->id]);
        });

        try {
            $before = $this->fingerprint($school);
            $result = $this->execution()->execute($this->freshItem($item));
        } finally {
            Event::forget('eloquent.created: '.StudentSubjectEnrollment::class);
        }

        $this->assertNotNull($firstOfferingId, 'the first elective must have been attempted for the sabotage to fire');
        $this->assertSame('failed', $result->execution_status, 'the whole Item must fail when the second elective is retargeted into an occupied group mid-attempt');
        $this->assertSame('elective_target_group_conflict_at_execution', $result->validation_reason);

        $after = $this->fingerprint($school);
        $this->assertEquals($before['student_enrollments'], $after['student_enrollments'], 'the pre-existing target Enrollment must remain untouched -- this attempt never wrote to it');

        $firstOfferingActive = app(TenantContext::class)->withSchool($school, fn () => StudentSubjectEnrollment::query()
            ->where('student_enrollment_id', $targetEnrollment->id)
            ->where('subject_offering_id', $firstOfferingId)
            ->where('status', 'active')
            ->count());
        $this->assertSame(0, $firstOfferingActive, 'the FIRST elective, already inserted earlier in the SAME failed attempt, must roll back too');

        $groupZActive = app(TenantContext::class)->withSchool($school, fn () => StudentSubjectEnrollment::query()->where('elective_group_id', $groupZ->id)->where('status', 'active')->count());
        $this->assertSame(1, $groupZActive, 'only the pre-existing occupant remains active in Group Z');
    }

    // ==================================================================
    // Section 57 -- source history unchanged
    // ==================================================================

    #[Test]
    public function execution_never_mutates_source_enrollment_or_source_subject_enrollment_rows(): void
    {
        ['school' => $school, 'item' => $item] = $this->buildSubjectReadyContext();
        $before = $this->fingerprint($school);
        $sourceIds = collect($before['student_subject_enrollments'])->pluck('id')->all();

        $this->execution()->execute($item);

        $after = $this->fingerprint($school);
        $unchangedSourceRows = collect($after['student_subject_enrollments'])->whereIn('id', $sourceIds)->values()->all();
        $this->assertEquals(collect($before['student_subject_enrollments'])->values()->all(), $unchangedSourceRows, 'every pre-existing (source) row must be byte-identical after execution');
        $this->assertEquals($before['student_enrollments'][0], collect($after['student_enrollments'])->firstWhere('id', $before['student_enrollments'][0]->id), 'the source Enrollment must never be mutated');
    }

    // ==================================================================
    // Section 58 -- replay
    // ==================================================================

    #[Test]
    public function replaying_a_successfully_executed_item_creates_no_duplicate_rows(): void
    {
        ['school' => $school, 'item' => $item] = $this->buildSubjectReadyContext();
        $first = $this->execution()->execute($item);
        $countAfterFirst = $this->activeTargetSubjectRows($school, $first->target_enrollment_id)->count();

        $second = $this->execution()->execute($this->freshItem($item));

        $this->assertSame($first->target_enrollment_id, $second->target_enrollment_id);
        $this->assertSame('succeeded', $second->execution_status);
        $this->assertSame($countAfterFirst, $this->activeTargetSubjectRows($school, $second->target_enrollment_id)->count(), 'replay must not create a duplicate target elective');
    }

    // ==================================================================
    // Section 41 -- dry-run/execution parity
    // ==================================================================

    #[Test]
    public function every_dry_run_accepted_subject_intent_executes_successfully_mapped_and_omitted_together(): void
    {
        $ctx = $this->buildSubjectContext();
        ['school' => $school, 'campus' => $campus, 'sourceYear' => $sourceYear, 'targetYear' => $targetYear, 'sourceGrade' => $sourceGrade, 'targetGrade' => $targetGrade, 'targetSection' => $targetSection, 'plan' => $plan, 'sourceOffering' => $mappedOffering, 'targetOffering' => $mappedTarget] = $ctx;
        $omittedSubject = $this->createSubject($school);
        $omittedOffering = $this->createSubjectOffering($sourceYear, $campus, $sourceGrade, $omittedSubject, ['is_required' => false]);

        $student = $this->createStudent($school, ['student_number' => 'S-5005']);
        $this->enrollmentService()->enroll($student, $ctx['sourceSection'], '01', '2026-06-01');
        $this->subjectEnrollmentService()->enroll($student, $mappedOffering, '2026-06-01');
        $this->subjectEnrollmentService()->enroll($student, $omittedOffering, '2026-06-01');
        $this->planService()->upsertSubjectMapping($plan, $mappedOffering, $mappedTarget);
        $this->planService()->upsertSubjectMapping($plan, $omittedOffering, null);
        $this->planService()->upsertMapping($plan, $sourceGrade, null, $targetGrade, $targetSection);
        $item = $this->itemFor($plan, $student);
        app(TenantContext::class)->withSchool($school, fn () => $item->update(['decision' => 'promote', 'roll_number_strategy' => 'explicit', 'target_roll_number' => '041']));
        $summary = $this->dryRun()->run($this->freshPlan($plan));
        $this->assertTrue($summary['validated']);
        $item = $this->itemFor($this->freshPlan($plan), $student);
        $this->assertSame('ready', $item->validation_result);

        $result = $this->execution()->execute($item);

        $this->assertSame('succeeded', $result->execution_status);
        $rows = $this->activeTargetSubjectRows($school, $result->target_enrollment_id);
        $this->assertCount(1, $rows);
        $this->assertSame($mappedTarget->id, $rows->first()->subject_offering_id);
    }

    // ==================================================================
    // Helpers for multi-offering fixtures
    // ==================================================================

    /**
     * @return array{school: School, campus: Campus, sourceYear: AcademicYear, targetYear: AcademicYear, sourceGrade: GradeLevel, targetGrade: GradeLevel, sourceSection: Section, targetSection: Section, plan: EnrollmentRolloverPlan, subject: Subject, sourceOffering: SubjectOffering, targetOffering: SubjectOffering, student: Student, item: EnrollmentRolloverItem}
     */
    private function buildSubjectReadyContextFrom(array $ctx, ?string $preExistingTargetRollNumber = null, string $rollNumber = '011'): array
    {
        ['school' => $school, 'sourceSection' => $sourceSection, 'targetSection' => $targetSection, 'sourceGrade' => $sourceGrade, 'targetGrade' => $targetGrade, 'plan' => $plan, 'sourceOffering' => $sourceOffering, 'targetOffering' => $targetOffering] = $ctx;

        $student = $this->createStudent($school, ['student_number' => 'S-9001']);
        $this->enrollmentService()->enroll($student, $sourceSection, '007', '2026-06-01');
        $this->subjectEnrollmentService()->enroll($student, $sourceOffering, '2026-06-01');

        if ($preExistingTargetRollNumber !== null) {
            $this->enrollmentService()->enroll($student, $targetSection, $preExistingTargetRollNumber, '2027-06-01');
        }

        $this->planService()->upsertSubjectMapping($plan, $sourceOffering, $targetOffering);
        $this->planService()->upsertMapping($plan, $sourceGrade, null, $targetGrade, $targetSection);
        $item = $this->itemFor($plan, $student);
        $effectiveRollNumber = $preExistingTargetRollNumber ?? $rollNumber;
        app(TenantContext::class)->withSchool($school, fn () => $item->update(['decision' => 'promote', 'roll_number_strategy' => 'explicit', 'target_roll_number' => $effectiveRollNumber]));

        $summary = $this->dryRun()->run($this->freshPlan($plan));
        $this->assertTrue($summary['validated'], 'fixture setup must produce a validated plan');
        $fresh = $this->itemFor($this->freshPlan($plan), $student);
        $this->assertContains($fresh->validation_result, ['ready', 'already_enrolled']);

        return [...$ctx, 'student' => $student, 'item' => $fresh];
    }

    /**
     * @return array{student: Student, item: EnrollmentRolloverItem}
     */
    private function wireTwoElectiveItem(array $ctx, SubjectOffering $offeringA, SubjectOffering $targetA, SubjectOffering $offeringB, SubjectOffering $targetB, ?string $preExistingTargetRollNumber = null): array
    {
        ['school' => $school, 'sourceSection' => $sourceSection, 'targetSection' => $targetSection, 'sourceGrade' => $sourceGrade, 'targetGrade' => $targetGrade, 'plan' => $plan] = $ctx;

        $student = $this->createStudent($school, ['student_number' => 'S-6006']);
        $this->enrollmentService()->enroll($student, $sourceSection, '01', '2026-06-01');
        $this->subjectEnrollmentService()->enroll($student, $offeringA, '2026-06-01');
        $this->subjectEnrollmentService()->enroll($student, $offeringB, '2026-06-01');

        if ($preExistingTargetRollNumber !== null) {
            $this->enrollmentService()->enroll($student, $targetSection, $preExistingTargetRollNumber, '2027-06-01');
        }

        $this->planService()->upsertSubjectMapping($plan, $offeringA, $targetA);
        $this->planService()->upsertSubjectMapping($plan, $offeringB, $targetB);
        $this->planService()->upsertMapping($plan, $sourceGrade, null, $targetGrade, $targetSection);
        $item = $this->itemFor($plan, $student);
        $rollNumber = $preExistingTargetRollNumber ?? '031';
        app(TenantContext::class)->withSchool($school, fn () => $item->update(['decision' => 'promote', 'roll_number_strategy' => 'explicit', 'target_roll_number' => $rollNumber]));

        $summary = $this->dryRun()->run($this->freshPlan($plan));
        $this->assertTrue($summary['validated'], 'fixture setup must produce a validated plan');
        $fresh = $this->itemFor($this->freshPlan($plan), $student);
        $this->assertContains($fresh->validation_result, ['ready', 'already_enrolled']);

        return ['student' => $student, 'item' => $fresh];
    }
}
