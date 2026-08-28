<?php

namespace Tests\Feature\StudentEnrollment;

use App\Domain\AcademicStructure\Infrastructure\AcademicYear;
use App\Domain\AcademicStructure\Infrastructure\GradeLevel;
use App\Domain\AcademicStructure\Infrastructure\Section;
use App\Domain\AcademicStructure\Infrastructure\Subject;
use App\Domain\AcademicStructure\Infrastructure\SubjectOffering;
use App\Domain\Students\Application\EnrollmentRolloverDryRunService;
use App\Domain\Students\Application\EnrollmentRolloverExecutionService;
use App\Domain\Students\Application\EnrollmentRolloverPlanService;
use App\Domain\Students\Application\StudentEnrollmentService;
use App\Domain\Students\Infrastructure\EnrollmentRolloverItem;
use App\Domain\Students\Infrastructure\EnrollmentRolloverPlan;
use App\Domain\Students\Infrastructure\Student;
use App\Domain\Students\Infrastructure\StudentSubjectEnrollment;
use App\Models\Campus;
use App\Models\School;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 1G.4: the JSON API surface for elective subject-mapping
 * configuration (EnrollmentRolloverSubjectMappingController). Every
 * mutation exercises the HTTP boundary around the already-accepted
 * Phase 1G.1 service (EnrollmentRolloverPlanService::upsertSubjectMapping()/
 * removeSubjectMapping()) -- authorization, tenant-safe id resolution,
 * three-state semantics, and response shape. Domain-level validation
 * (year/required/school) is already covered at depth in
 * EnrollmentRolloverSubjectMappingServiceTest; this file only proves
 * the HTTP boundary translates it correctly.
 */
class EnrollmentRolloverSubjectMappingApiTest extends TestCase
{
    use CreatesTenancyFixtures;

    private function token(User $user): string
    {
        return $user->createToken('test-device')->plainTextToken;
    }

    private function asUser(User $user)
    {
        return $this->withHeader('Authorization', 'Bearer '.$this->token($user));
    }

    private function planService(): EnrollmentRolloverPlanService
    {
        return app(EnrollmentRolloverPlanService::class);
    }

    /**
     * @return array{school: School, campus: Campus, sourceYear: AcademicYear, targetYear: AcademicYear, grade: GradeLevel, subject: Subject, plan: EnrollmentRolloverPlan, sourceOffering: SubjectOffering, targetOffering: SubjectOffering}
     */
    private function buildContext(): array
    {
        $school = $this->createSchool();
        $campus = $this->createCampus($school);
        $sourceYear = $this->createAcademicYear($school, ['code' => 'SRC', 'starts_on' => '2026-06-01', 'ends_on' => '2027-04-30']);
        $targetYear = $this->createAcademicYear($school, ['code' => 'TGT', 'starts_on' => '2027-06-01', 'ends_on' => '2028-04-30']);
        $grade = $this->createGradeLevel($school);
        $subject = $this->createSubject($school);
        $plan = $this->planService()->createDraft($school, $sourceYear, $targetYear);
        $sourceOffering = $this->createSubjectOffering($sourceYear, $campus, $grade, $subject, ['is_required' => false]);
        $targetOffering = $this->createSubjectOffering($targetYear, $campus, $grade, $subject, ['is_required' => false]);

        return compact('school', 'campus', 'sourceYear', 'targetYear', 'grade', 'subject', 'plan', 'sourceOffering', 'targetOffering');
    }

    private function freshPlan(School $school, string $planId): EnrollmentRolloverPlan
    {
        return app(TenantContext::class)->withSchool($school, fn () => EnrollmentRolloverPlan::query()->findOrFail($planId));
    }

    // ==================================================================
    // Authorization
    // ==================================================================

    #[Test]
    public function a_guest_is_denied_on_upsert(): void
    {
        ['school' => $school, 'plan' => $plan, 'sourceOffering' => $source, 'targetOffering' => $target] = $this->buildContext();

        $this->putJson("/api/v1/schools/{$school->id}/enrollment-rollovers/{$plan->id}/subject-mappings/{$source->id}", [
            'target_subject_offering_id' => $target->id,
        ])->assertUnauthorized();
    }

    #[Test]
    public function base_view_plus_rollover_view_alone_denies_upsert(): void
    {
        ['school' => $school, 'plan' => $plan, 'sourceOffering' => $source, 'targetOffering' => $target] = $this->buildContext();
        $user = $this->createUserWithCapabilities($school, ['enrollments.view', 'enrollments.rollovers.view']);

        $this->asUser($user)->withHeader('Idempotency-Key', 'test-sm-deny-1')
            ->putJson("/api/v1/schools/{$school->id}/enrollment-rollovers/{$plan->id}/subject-mappings/{$source->id}", [
                'target_subject_offering_id' => $target->id,
            ])->assertForbidden();
    }

    #[Test]
    public function base_manage_plus_rollover_manage_allows_upsert(): void
    {
        ['school' => $school, 'plan' => $plan, 'sourceOffering' => $source, 'targetOffering' => $target] = $this->buildContext();
        $user = $this->createUserWithCapabilities($school, ['enrollments.view', 'enrollments.rollovers.view', 'enrollments.manage', 'enrollments.rollovers.manage']);

        $this->asUser($user)->withHeader('Idempotency-Key', 'test-sm-allow-1')
            ->putJson("/api/v1/schools/{$school->id}/enrollment-rollovers/{$plan->id}/subject-mappings/{$source->id}", [
                'target_subject_offering_id' => $target->id,
            ])->assertOk();
    }

    #[Test]
    public function base_manage_alone_denies_upsert(): void
    {
        ['school' => $school, 'plan' => $plan, 'sourceOffering' => $source, 'targetOffering' => $target] = $this->buildContext();
        $user = $this->createUserWithCapabilities($school, ['enrollments.view', 'enrollments.manage']);

        $this->asUser($user)->withHeader('Idempotency-Key', 'test-sm-deny-2')
            ->putJson("/api/v1/schools/{$school->id}/enrollment-rollovers/{$plan->id}/subject-mappings/{$source->id}", [
                'target_subject_offering_id' => $target->id,
            ])->assertForbidden();
    }

    #[Test]
    public function no_academic_capability_is_ever_required(): void
    {
        ['school' => $school, 'plan' => $plan, 'sourceOffering' => $source, 'targetOffering' => $target] = $this->buildContext();
        // Deliberately grants ONLY the rollover capabilities -- no
        // academics.subjects.* of any kind (this checkpoint's brief,
        // section 12).
        $user = $this->createUserWithCapabilities($school, ['enrollments.view', 'enrollments.rollovers.view', 'enrollments.manage', 'enrollments.rollovers.manage']);

        $this->asUser($user)->withHeader('Idempotency-Key', 'test-sm-noacademic-1')
            ->putJson("/api/v1/schools/{$school->id}/enrollment-rollovers/{$plan->id}/subject-mappings/{$source->id}", [
                'target_subject_offering_id' => $target->id,
            ])->assertOk();
    }

    #[Test]
    public function base_manage_plus_rollover_manage_allows_delete(): void
    {
        ['school' => $school, 'plan' => $plan, 'sourceOffering' => $source, 'targetOffering' => $target] = $this->buildContext();
        $this->planService()->upsertSubjectMapping($plan, $source, $target);
        $user = $this->createUserWithCapabilities($school, ['enrollments.view', 'enrollments.rollovers.view', 'enrollments.manage', 'enrollments.rollovers.manage']);

        $this->asUser($user)
            ->deleteJson("/api/v1/schools/{$school->id}/enrollment-rollovers/{$plan->id}/subject-mappings/{$source->id}")
            ->assertOk();
    }

    #[Test]
    public function view_alone_denies_delete(): void
    {
        ['school' => $school, 'plan' => $plan, 'sourceOffering' => $source, 'targetOffering' => $target] = $this->buildContext();
        $this->planService()->upsertSubjectMapping($plan, $source, $target);
        $user = $this->createUserWithCapabilities($school, ['enrollments.view', 'enrollments.rollovers.view']);

        $this->asUser($user)
            ->deleteJson("/api/v1/schools/{$school->id}/enrollment-rollovers/{$plan->id}/subject-mappings/{$source->id}")
            ->assertForbidden();
    }

    // ==================================================================
    // Three-state semantics
    // ==================================================================

    #[Test]
    public function upsert_with_a_target_creates_an_explicit_map(): void
    {
        ['school' => $school, 'plan' => $plan, 'sourceOffering' => $source, 'targetOffering' => $target] = $this->buildContext();
        $user = $this->createUserWithCapabilities($school, ['enrollments.view', 'enrollments.rollovers.view', 'enrollments.manage', 'enrollments.rollovers.manage']);

        $response = $this->asUser($user)->withHeader('Idempotency-Key', 'test-sm-map-1')
            ->putJson("/api/v1/schools/{$school->id}/enrollment-rollovers/{$plan->id}/subject-mappings/{$source->id}", [
                'target_subject_offering_id' => $target->id,
            ])->assertOk();

        $response->assertJsonPath('data.state', 'mapped');
        $response->assertJsonPath('data.targetSubjectOffering.id', $target->id);
        $response->assertJsonPath('data.sourceSubjectOffering.id', $source->id);
        $response->assertJsonPath('data.plan.configurationVersion', 2);
    }

    #[Test]
    public function upsert_with_a_null_target_is_an_explicit_omit_not_unconfigured(): void
    {
        ['school' => $school, 'plan' => $plan, 'sourceOffering' => $source] = $this->buildContext();
        $user = $this->createUserWithCapabilities($school, ['enrollments.view', 'enrollments.rollovers.view', 'enrollments.manage', 'enrollments.rollovers.manage']);

        $response = $this->asUser($user)->withHeader('Idempotency-Key', 'test-sm-omit-1')
            ->putJson("/api/v1/schools/{$school->id}/enrollment-rollovers/{$plan->id}/subject-mappings/{$source->id}", [
                'target_subject_offering_id' => null,
            ])->assertOk();

        $response->assertJsonPath('data.state', 'omit');
        $response->assertJsonPath('data.targetSubjectOffering', null);
    }

    #[Test]
    public function delete_returns_an_explicit_omit_to_unconfigured(): void
    {
        ['school' => $school, 'plan' => $plan, 'sourceOffering' => $source] = $this->buildContext();
        $this->planService()->upsertSubjectMapping($plan, $source, null);
        $user = $this->createUserWithCapabilities($school, ['enrollments.view', 'enrollments.rollovers.view', 'enrollments.manage', 'enrollments.rollovers.manage']);

        $response = $this->asUser($user)
            ->deleteJson("/api/v1/schools/{$school->id}/enrollment-rollovers/{$plan->id}/subject-mappings/{$source->id}")
            ->assertOk();

        $response->assertJsonPath('data.state', 'unconfigured');
        $response->assertJsonPath('data.targetSubjectOffering', null);
    }

    #[Test]
    public function delete_returns_a_mapped_source_to_unconfigured(): void
    {
        ['school' => $school, 'plan' => $plan, 'sourceOffering' => $source, 'targetOffering' => $target] = $this->buildContext();
        $this->planService()->upsertSubjectMapping($plan, $source, $target);
        $user = $this->createUserWithCapabilities($school, ['enrollments.view', 'enrollments.rollovers.view', 'enrollments.manage', 'enrollments.rollovers.manage']);

        $response = $this->asUser($user)
            ->deleteJson("/api/v1/schools/{$school->id}/enrollment-rollovers/{$plan->id}/subject-mappings/{$source->id}")
            ->assertOk();

        $response->assertJsonPath('data.state', 'unconfigured');
    }

    #[Test]
    public function delete_on_an_already_unconfigured_source_is_an_idempotent_no_op(): void
    {
        ['school' => $school, 'plan' => $plan, 'sourceOffering' => $source] = $this->buildContext();
        $user = $this->createUserWithCapabilities($school, ['enrollments.view', 'enrollments.rollovers.view', 'enrollments.manage', 'enrollments.rollovers.manage']);

        $this->asUser($user)
            ->deleteJson("/api/v1/schools/{$school->id}/enrollment-rollovers/{$plan->id}/subject-mappings/{$source->id}")
            ->assertOk();

        $this->assertSame(1, $this->freshPlan($school, $plan->id)->configuration_version);
    }

    #[Test]
    public function a_true_no_op_upsert_does_not_bump_the_configuration_version(): void
    {
        ['school' => $school, 'plan' => $plan, 'sourceOffering' => $source, 'targetOffering' => $target] = $this->buildContext();
        $this->planService()->upsertSubjectMapping($plan, $source, $target);
        $user = $this->createUserWithCapabilities($school, ['enrollments.view', 'enrollments.rollovers.view', 'enrollments.manage', 'enrollments.rollovers.manage']);

        $response = $this->asUser($user)->withHeader('Idempotency-Key', 'test-sm-noop-1')
            ->putJson("/api/v1/schools/{$school->id}/enrollment-rollovers/{$plan->id}/subject-mappings/{$source->id}", [
                'target_subject_offering_id' => $target->id,
            ])->assertOk();

        $response->assertJsonPath('data.plan.configurationVersion', 2);
    }

    // ==================================================================
    // Validation / cross-tenant
    // ==================================================================

    #[Test]
    public function a_foreign_school_target_id_is_rejected(): void
    {
        ['school' => $school, 'plan' => $plan, 'sourceOffering' => $source] = $this->buildContext();
        $otherSchool = $this->createSchool();
        $otherCampus = $this->createCampus($otherSchool);
        $otherYear = $this->createAcademicYear($otherSchool);
        $otherGrade = $this->createGradeLevel($otherSchool);
        $otherSubject = $this->createSubject($otherSchool);
        $foreignOffering = $this->createSubjectOffering($otherYear, $otherCampus, $otherGrade, $otherSubject, ['is_required' => false]);

        $user = $this->createUserWithCapabilities($school, ['enrollments.view', 'enrollments.rollovers.view', 'enrollments.manage', 'enrollments.rollovers.manage']);

        $this->asUser($user)->withHeader('Idempotency-Key', 'test-sm-foreign-1')
            ->putJson("/api/v1/schools/{$school->id}/enrollment-rollovers/{$plan->id}/subject-mappings/{$source->id}", [
                'target_subject_offering_id' => $foreignOffering->id,
            ])->assertUnprocessable();
    }

    #[Test]
    public function a_foreign_school_source_id_is_not_found(): void
    {
        ['school' => $school, 'plan' => $plan, 'targetOffering' => $target] = $this->buildContext();
        $otherSchool = $this->createSchool();
        $otherCampus = $this->createCampus($otherSchool);
        $otherYear = $this->createAcademicYear($otherSchool);
        $otherGrade = $this->createGradeLevel($otherSchool);
        $otherSubject = $this->createSubject($otherSchool);
        $foreignSource = $this->createSubjectOffering($otherYear, $otherCampus, $otherGrade, $otherSubject, ['is_required' => false]);

        $user = $this->createUserWithCapabilities($school, ['enrollments.view', 'enrollments.rollovers.view', 'enrollments.manage', 'enrollments.rollovers.manage']);

        $this->asUser($user)->withHeader('Idempotency-Key', 'test-sm-foreign-2')
            ->putJson("/api/v1/schools/{$school->id}/enrollment-rollovers/{$plan->id}/subject-mappings/{$foreignSource->id}", [
                'target_subject_offering_id' => $target->id,
            ])->assertNotFound();
    }

    #[Test]
    public function a_source_from_the_wrong_academic_year_is_rejected(): void
    {
        ['school' => $school, 'campus' => $campus, 'targetYear' => $targetYear, 'grade' => $grade, 'plan' => $plan, 'targetOffering' => $target] = $this->buildContext();
        // A source Offering that belongs to the PLAN'S TARGET year --
        // structurally same-School, but the wrong side. A distinct
        // Subject avoids colliding with the fixture's own
        // (year, campus, grade, subject) uniqueness.
        $otherSubject = $this->createSubject($school, ['code' => 'OTH1']);
        $wrongYearSource = $this->createSubjectOffering($targetYear, $campus, $grade, $otherSubject, ['is_required' => false]);

        $user = $this->createUserWithCapabilities($school, ['enrollments.view', 'enrollments.rollovers.view', 'enrollments.manage', 'enrollments.rollovers.manage']);

        $this->asUser($user)->withHeader('Idempotency-Key', 'test-sm-year-1')
            ->putJson("/api/v1/schools/{$school->id}/enrollment-rollovers/{$plan->id}/subject-mappings/{$wrongYearSource->id}", [
                'target_subject_offering_id' => $target->id,
            ])->assertUnprocessable();
    }

    #[Test]
    public function a_required_source_offering_is_rejected(): void
    {
        ['school' => $school, 'campus' => $campus, 'sourceYear' => $sourceYear, 'grade' => $grade, 'plan' => $plan, 'targetOffering' => $target] = $this->buildContext();
        $otherSubject = $this->createSubject($school, ['code' => 'OTH2']);
        $requiredSource = $this->createSubjectOffering($sourceYear, $campus, $grade, $otherSubject, ['is_required' => true]);

        $user = $this->createUserWithCapabilities($school, ['enrollments.view', 'enrollments.rollovers.view', 'enrollments.manage', 'enrollments.rollovers.manage']);

        $this->asUser($user)->withHeader('Idempotency-Key', 'test-sm-req-1')
            ->putJson("/api/v1/schools/{$school->id}/enrollment-rollovers/{$plan->id}/subject-mappings/{$requiredSource->id}", [
                'target_subject_offering_id' => $target->id,
            ])->assertUnprocessable();
    }

    #[Test]
    public function a_required_target_offering_is_rejected(): void
    {
        ['school' => $school, 'campus' => $campus, 'targetYear' => $targetYear, 'grade' => $grade, 'plan' => $plan, 'sourceOffering' => $source] = $this->buildContext();
        $otherSubject = $this->createSubject($school, ['code' => 'OTH3']);
        $requiredTarget = $this->createSubjectOffering($targetYear, $campus, $grade, $otherSubject, ['is_required' => true]);

        $user = $this->createUserWithCapabilities($school, ['enrollments.view', 'enrollments.rollovers.view', 'enrollments.manage', 'enrollments.rollovers.manage']);

        $this->asUser($user)->withHeader('Idempotency-Key', 'test-sm-req-2')
            ->putJson("/api/v1/schools/{$school->id}/enrollment-rollovers/{$plan->id}/subject-mappings/{$source->id}", [
                'target_subject_offering_id' => $requiredTarget->id,
            ])->assertUnprocessable();
    }

    #[Test]
    public function a_mapping_belonging_to_a_different_plan_never_leaks_across_plans(): void
    {
        ['school' => $school, 'sourceYear' => $sourceYear, 'targetYear' => $targetYear, 'campus' => $campus, 'grade' => $grade, 'subject' => $subject, 'sourceOffering' => $source, 'targetOffering' => $target] = $this->buildContext();
        $otherPlan = $this->planService()->createDraft($school, $sourceYear, $this->createAcademicYear($school, ['code' => 'TGT2', 'starts_on' => '2028-06-01', 'ends_on' => '2029-04-30']));

        $user = $this->createUserWithCapabilities($school, ['enrollments.view', 'enrollments.rollovers.view', 'enrollments.manage', 'enrollments.rollovers.manage']);

        // Mapping the SAME source Offering under a DIFFERENT Plan must
        // not collide with or reveal the other Plan's configuration --
        // each Plan owns its own row for (plan_id, source_subject_offering_id).
        $response = $this->asUser($user)->withHeader('Idempotency-Key', 'test-sm-plan-isolation-1')
            ->putJson("/api/v1/schools/{$school->id}/enrollment-rollovers/{$otherPlan->id}/subject-mappings/{$source->id}", [
                'target_subject_offering_id' => null,
            ])->assertOk();

        $response->assertJsonPath('data.state', 'omit');
    }

    // ==================================================================
    // Read projection
    // ==================================================================

    #[Test]
    public function the_plan_detail_embeds_subject_mappings_and_unmapped_discovery(): void
    {
        ['school' => $school, 'plan' => $plan, 'sourceOffering' => $source, 'targetOffering' => $target] = $this->buildContext();
        $this->planService()->upsertSubjectMapping($plan, $source, $target);
        $user = $this->createUserWithCapabilities($school, ['enrollments.view', 'enrollments.rollovers.view']);

        $response = $this->asUser($user)
            ->getJson("/api/v1/schools/{$school->id}/enrollment-rollovers/{$plan->id}")
            ->assertOk();

        $response->assertJsonStructure(['data' => ['subjectMappings', 'unmappedSourceSubjectOfferings']]);
        $response->assertJsonPath('data.subjectMappings.0.state', 'mapped');
        $response->assertJsonPath('data.subjectMappings.0.sourceSubjectOffering.id', $source->id);
        $response->assertJsonPath('data.subjectMappings.0.targetSubjectOffering.id', $target->id);
    }

    #[Test]
    public function unmapped_discovery_surfaces_a_source_offering_with_active_participation_and_no_mapping(): void
    {
        ['school' => $school, 'campus' => $campus, 'sourceYear' => $sourceYear, 'grade' => $grade, 'plan' => $plan, 'sourceOffering' => $source] = $this->buildContext();
        $section = $this->createSection($sourceYear, $campus, $grade);
        $student = $this->createStudent($school);
        $enrollment = $this->createStudentEnrollment($student, $section);
        $this->createStudentSubjectEnrollment($student, $source, ['student_enrollment_id' => $enrollment->id, 'status' => 'active']);

        // Populate Items the same way the accepted architecture does
        // (dry-run's own population pass) -- unmapped discovery reads
        // Item rows, it never invents its own candidate-eligibility
        // logic.
        app(EnrollmentRolloverDryRunService::class)->run($plan);

        $user = $this->createUserWithCapabilities($school, ['enrollments.view', 'enrollments.rollovers.view']);

        $response = $this->asUser($user)
            ->getJson("/api/v1/schools/{$school->id}/enrollment-rollovers/{$plan->id}")
            ->assertOk();

        $response->assertJsonPath('data.unmappedSourceSubjectOfferings.0.id', $source->id);
        $response->assertJsonCount(0, 'data.subjectMappings');
    }

    #[Test]
    public function a_configured_mapping_no_longer_appears_in_unmapped_discovery(): void
    {
        ['school' => $school, 'campus' => $campus, 'sourceYear' => $sourceYear, 'grade' => $grade, 'plan' => $plan, 'sourceOffering' => $source, 'targetOffering' => $target] = $this->buildContext();
        $section = $this->createSection($sourceYear, $campus, $grade);
        $student = $this->createStudent($school);
        $enrollment = $this->createStudentEnrollment($student, $section);
        $this->createStudentSubjectEnrollment($student, $source, ['student_enrollment_id' => $enrollment->id, 'status' => 'active']);
        app(EnrollmentRolloverDryRunService::class)->run($plan);

        $this->planService()->upsertSubjectMapping($plan, $source, $target);

        $user = $this->createUserWithCapabilities($school, ['enrollments.view', 'enrollments.rollovers.view']);
        $response = $this->asUser($user)
            ->getJson("/api/v1/schools/{$school->id}/enrollment-rollovers/{$plan->id}")
            ->assertOk();

        $response->assertJsonCount(0, 'data.unmappedSourceSubjectOfferings');
        $response->assertJsonCount(1, 'data.subjectMappings');
    }

    // ==================================================================
    // End-to-end: configure -> dry-run -> execute (through the API's
    // own service boundary; no separate subject-execution endpoint
    // exists -- 1G.3 already composed it into item execution).
    // ==================================================================

    #[Test]
    public function a_mapped_elective_is_carried_forward_through_dry_run_and_execution(): void
    {
        ['school' => $school, 'campus' => $campus, 'sourceYear' => $sourceYear, 'targetYear' => $targetYear, 'grade' => $grade, 'plan' => $plan, 'sourceOffering' => $source, 'targetOffering' => $target] = $this->buildContext();
        $sourceSection = $this->createSection($sourceYear, $campus, $grade, ['code' => 'S1']);
        $targetSection = $this->createSection($targetYear, $campus, $grade, ['code' => 'T1']);
        $student = $this->createStudent($school);
        $sourceEnrollment = $this->createStudentEnrollment($student, $sourceSection, ['status' => 'active']);
        $this->createStudentSubjectEnrollment($student, $source, ['student_enrollment_id' => $sourceEnrollment->id, 'status' => 'active']);

        $this->planService()->upsertMapping($plan, $grade, null, $grade, $targetSection);
        $this->planService()->upsertSubjectMapping($plan, $source, $target);

        // Populates the Item (undecided by default), then gives it an
        // explicit placement decision -- the same two-pass shape every
        // other rollover execution test in this suite uses
        // (EnrollmentRolloverExecutionServiceTest).
        app(EnrollmentRolloverDryRunService::class)->run($this->freshPlan($school, $plan->id));
        $item = app(TenantContext::class)->withSchool($school, fn () => EnrollmentRolloverItem::query()->where('plan_id', $plan->id)->where('student_id', $student->id)->firstOrFail());
        $this->planService()->setItemDecision($this->freshPlan($school, $plan->id), $item, 'repeat', null, 'explicit', '101');

        $preCount = app(TenantContext::class)->withSchool($school, fn () => StudentSubjectEnrollment::query()->where('subject_offering_id', $target->id)->count());
        $this->assertSame(0, $preCount);

        $dryRunSummary = app(EnrollmentRolloverDryRunService::class)->run($this->freshPlan($school, $plan->id));
        $this->assertSame(0, $dryRunSummary['review'] + $dryRunSummary['blocked']);
        // Dry-run itself must create zero academic writes.
        $postDryRunCount = app(TenantContext::class)->withSchool($school, fn () => StudentSubjectEnrollment::query()->where('subject_offering_id', $target->id)->count());
        $this->assertSame(0, $postDryRunCount);

        app(EnrollmentRolloverExecutionService::class)->start($this->freshPlan($school, $plan->id), afterEachItem: fn () => false);

        $postExecutionCount = app(TenantContext::class)->withSchool($school, fn () => StudentSubjectEnrollment::query()
            ->where('subject_offering_id', $target->id)
            ->where('status', 'active')
            ->count());
        $this->assertSame(1, $postExecutionCount);
    }
}
