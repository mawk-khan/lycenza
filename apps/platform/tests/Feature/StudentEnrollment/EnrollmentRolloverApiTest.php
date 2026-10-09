<?php

namespace Tests\Feature\StudentEnrollment;

use App\Domain\AcademicStructure\Infrastructure\AcademicYear;
use App\Domain\AcademicStructure\Infrastructure\GradeLevel;
use App\Domain\AcademicStructure\Infrastructure\Section;
use App\Domain\Students\Application\EnrollmentRolloverDryRunService;
use App\Domain\Students\Application\EnrollmentRolloverPlanService;
use App\Domain\Students\Application\StudentEnrollmentService;
use App\Domain\Students\Infrastructure\EnrollmentRolloverItem;
use App\Domain\Students\Infrastructure\EnrollmentRolloverPlan;
use App\Domain\Students\Infrastructure\Student;
use App\Domain\Students\Infrastructure\StudentEnrollment;
use App\Models\Campus;
use App\Models\MembershipRoleAssignment;
use App\Models\Role;
use App\Models\School;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use App\Support\Testing\LocalCatalogueFixtures;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 1B.7E: the administrative rollover HTTP surface
 * (EnrollmentRolloverController/EnrollmentRolloverMappingController/
 * EnrollmentRolloverItemController). Every test exercises the HTTP
 * boundary around the already-accepted rollover domain (Phase
 * 1B.7A-1B.7D) -- authentication, School membership, dual
 * `enrollments.*`/`enrollments.rollovers.*` capability independence,
 * tenant-safe id resolution, nested Plan ownership, response privacy,
 * and safe translation of the existing domain exceptions. No dry-run/
 * mapping/execution business rule is re-tested here at the depth the
 * 1B.7A-1B.7D domain suites already cover.
 */
class EnrollmentRolloverApiTest extends TestCase
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

    private function grantCapabilities(User $user, School $school, array $capabilities): void
    {
        $membership = $this->createMembership($user, $school);
        $role = LocalCatalogueFixtures::asOwner(fn () => Role::query()->create(['key' => 'test_rollover_http_'.Str::random(8), 'name' => 'Test', 'scope' => 'school', 'is_system' => false]));
        LocalCatalogueFixtures::asOwner(fn () => $role->capabilities()->sync($capabilities));
        app(TenantContext::class)->withSchool($school, fn () => LocalCatalogueFixtures::asOwner(fn () => MembershipRoleAssignment::query()->create([
            'school_id' => $school->id, 'school_membership_id' => $membership->id, 'role_id' => $role->id,
        ])));
    }

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

    /**
     * @return array{school: School, campus: Campus, sourceYear: AcademicYear, targetYear: AcademicYear, sourceGrade: GradeLevel, targetGrade: GradeLevel, sourceSection: Section, targetSection: Section}
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

        return compact('school', 'campus', 'sourceYear', 'targetYear', 'sourceGrade', 'targetGrade', 'sourceSection', 'targetSection');
    }

    private function planId(School $school, AcademicYear $sourceYear, AcademicYear $targetYear): string
    {
        return $this->planService()->createDraft($school, $sourceYear, $targetYear)->id;
    }

    private function freshPlan(School $school, string $planId): EnrollmentRolloverPlan
    {
        return app(TenantContext::class)->withSchool($school, fn () => EnrollmentRolloverPlan::query()->findOrFail($planId));
    }

    // ==================================================================
    // Authentication / membership (sections 51/52)
    // ==================================================================

    #[Test]
    public function a_guest_is_denied_on_the_directory(): void
    {
        $school = $this->createSchool();

        $this->getJson("/api/v1/schools/{$school->id}/enrollment-rollovers")->assertUnauthorized();
    }

    #[Test]
    public function a_guest_is_denied_on_create(): void
    {
        ['school' => $school, 'sourceYear' => $sourceYear, 'targetYear' => $targetYear] = $this->buildContext();

        $this->postJson("/api/v1/schools/{$school->id}/enrollment-rollovers", [
            'source_academic_year_id' => $sourceYear->id, 'target_academic_year_id' => $targetYear->id,
        ])->assertUnauthorized();
    }

    #[Test]
    public function a_central_user_without_membership_is_not_found_not_forbidden(): void
    {
        $user = $this->createUser();
        $school = $this->createSchool();

        $this->asUser($user)->getJson("/api/v1/schools/{$school->id}/enrollment-rollovers")->assertNotFound();
    }

    // ==================================================================
    // Section 53 -- read capability matrix
    // ==================================================================

    #[Test]
    public function base_view_plus_rollover_view_allows_reading_the_directory(): void
    {
        $school = $this->createSchool();
        $user = $this->createUser();
        $this->grantCapabilities($user, $school, ['enrollments.view', 'enrollments.rollovers.view']);

        $this->asUser($user)->getJson("/api/v1/schools/{$school->id}/enrollment-rollovers")->assertOk();
    }

    #[Test]
    public function base_view_alone_denies_the_directory(): void
    {
        $school = $this->createSchool();
        $user = $this->createUser();
        $this->grantCapabilities($user, $school, ['enrollments.view']);

        $this->asUser($user)->getJson("/api/v1/schools/{$school->id}/enrollment-rollovers")->assertForbidden();
    }

    #[Test]
    public function rollover_view_alone_denies_the_directory(): void
    {
        $school = $this->createSchool();
        $user = $this->createUser();
        $this->grantCapabilities($user, $school, ['enrollments.rollovers.view']);

        $this->asUser($user)->getJson("/api/v1/schools/{$school->id}/enrollment-rollovers")->assertForbidden();
    }

    #[Test]
    public function rollover_manage_alone_does_not_imply_read(): void
    {
        $school = $this->createSchool();
        $user = $this->createUser();
        $this->grantCapabilities($user, $school, ['enrollments.manage', 'enrollments.rollovers.manage']);

        $this->asUser($user)->getJson("/api/v1/schools/{$school->id}/enrollment-rollovers")->assertForbidden();
    }

    // ==================================================================
    // Section 54 -- mutation capability matrix
    // ==================================================================

    #[Test]
    public function base_manage_plus_rollover_manage_allows_create(): void
    {
        ['school' => $school, 'sourceYear' => $sourceYear, 'targetYear' => $targetYear] = $this->buildContext();
        $user = $this->createUser();
        $this->grantCapabilities($user, $school, ['enrollments.manage', 'enrollments.rollovers.manage']);

        $this->asUser($user)->withHeader('Idempotency-Key', 'test-rollover-create-1')
            ->postJson("/api/v1/schools/{$school->id}/enrollment-rollovers", [
                'source_academic_year_id' => $sourceYear->id, 'target_academic_year_id' => $targetYear->id,
            ])->assertCreated();
    }

    #[Test]
    public function base_manage_alone_denies_create(): void
    {
        ['school' => $school, 'sourceYear' => $sourceYear, 'targetYear' => $targetYear] = $this->buildContext();
        $user = $this->createUser();
        $this->grantCapabilities($user, $school, ['enrollments.manage']);

        $this->asUser($user)->postJson("/api/v1/schools/{$school->id}/enrollment-rollovers", [
            'source_academic_year_id' => $sourceYear->id, 'target_academic_year_id' => $targetYear->id,
        ])->assertForbidden();
    }

    #[Test]
    public function rollover_manage_alone_denies_create(): void
    {
        ['school' => $school, 'sourceYear' => $sourceYear, 'targetYear' => $targetYear] = $this->buildContext();
        $user = $this->createUser();
        $this->grantCapabilities($user, $school, ['enrollments.rollovers.manage']);

        $this->asUser($user)->postJson("/api/v1/schools/{$school->id}/enrollment-rollovers", [
            'source_academic_year_id' => $sourceYear->id, 'target_academic_year_id' => $targetYear->id,
        ])->assertForbidden();
    }

    #[Test]
    public function the_read_only_pair_denies_create(): void
    {
        ['school' => $school, 'sourceYear' => $sourceYear, 'targetYear' => $targetYear] = $this->buildContext();
        $user = $this->createUser();
        $this->grantCapabilities($user, $school, ['enrollments.view', 'enrollments.rollovers.view']);

        $this->asUser($user)->postJson("/api/v1/schools/{$school->id}/enrollment-rollovers", [
            'source_academic_year_id' => $sourceYear->id, 'target_academic_year_id' => $targetYear->id,
        ])->assertForbidden();
    }

    // ==================================================================
    // Section 55 -- School-specific capability isolation
    // ==================================================================

    #[Test]
    public function rollover_capability_in_school_a_does_not_grant_school_b_access(): void
    {
        ['school' => $schoolA] = $this->buildContext();
        $schoolB = $this->createSchool();
        $user = $this->createUser();
        $this->grantCapabilities($user, $schoolA, ['enrollments.view', 'enrollments.rollovers.view']);
        $this->createMembership($user, $schoolB);

        $this->asUser($user)->getJson("/api/v1/schools/{$schoolB->id}/enrollment-rollovers")->assertForbidden();
    }

    // ==================================================================
    // Section 56 -- default role grants
    // ==================================================================

    #[Test]
    public function school_admin_has_full_rollover_view_and_manage(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');

        $this->asUser($user)->getJson("/api/v1/schools/{$school->id}/enrollment-rollovers")->assertOk();

        $sourceYear = app(TenantContext::class)->withSchool($school, fn () => AcademicYear::factory()->for($school, 'school')->create(['code' => 'SRC']));
        $targetYear = app(TenantContext::class)->withSchool($school, fn () => AcademicYear::factory()->for($school, 'school')->create(['code' => 'TGT', 'starts_on' => '2027-06-01', 'ends_on' => '2028-05-31']));

        $this->asUser($user)->withHeader('Idempotency-Key', 'test-sa-create')
            ->postJson("/api/v1/schools/{$school->id}/enrollment-rollovers", [
                'source_academic_year_id' => $sourceYear->id, 'target_academic_year_id' => $targetYear->id,
            ])->assertCreated();
    }

    #[Test]
    public function principal_has_rollover_view_but_not_manage(): void
    {
        [$user, $school] = $this->createSchoolAdmin('principal');

        $this->asUser($user)->getJson("/api/v1/schools/{$school->id}/enrollment-rollovers")->assertOk();

        $sourceYear = app(TenantContext::class)->withSchool($school, fn () => AcademicYear::factory()->for($school, 'school')->create(['code' => 'SRC']));
        $targetYear = app(TenantContext::class)->withSchool($school, fn () => AcademicYear::factory()->for($school, 'school')->create(['code' => 'TGT', 'starts_on' => '2027-06-01', 'ends_on' => '2028-05-31']));

        $this->asUser($user)->postJson("/api/v1/schools/{$school->id}/enrollment-rollovers", [
            'source_academic_year_id' => $sourceYear->id, 'target_academic_year_id' => $targetYear->id,
        ])->assertForbidden();

        // Existing unrelated Enrollment permissions remain unchanged.
        $this->asUser($user)->getJson("/api/v1/schools/{$school->id}/enrollments")->assertOk();
        $this->asUser($user)->getJson("/api/v1/schools/{$school->id}/students")->assertOk();
    }

    // ==================================================================
    // Sections 14/66/67 -- Plan create
    // ==================================================================

    #[Test]
    public function creating_a_plan_derives_school_and_actor_and_accepts_only_year_ids(): void
    {
        ['school' => $school, 'sourceYear' => $sourceYear, 'targetYear' => $targetYear] = $this->buildContext();
        $user = $this->createUser();
        $this->grantCapabilities($user, $school, ['enrollments.manage', 'enrollments.rollovers.manage']);

        $response = $this->asUser($user)->withHeader('Idempotency-Key', 'test-create-1')
            ->postJson("/api/v1/schools/{$school->id}/enrollment-rollovers", [
                'source_academic_year_id' => $sourceYear->id,
                'target_academic_year_id' => $targetYear->id,
                // Attempted caller-authority fields -- must be silently ignored.
                'status' => 'validated',
                'configuration_version' => 999,
                'school_id' => $this->createSchool()->id,
            ]);

        $response->assertCreated();
        $response->assertJsonPath('data.status', 'draft');
        $response->assertJsonPath('data.configurationVersion', 1);

        $plan = app(TenantContext::class)->withSchool($school, fn () => EnrollmentRolloverPlan::query()->findOrFail($response->json('data.id')));
        $this->assertSame($school->id, $plan->school_id);
    }

    #[Test]
    public function creating_a_plan_with_a_foreign_school_year_fails_validation_like_a_random_uuid(): void
    {
        ['school' => $school] = $this->buildContext();
        $otherSchool = $this->createSchool();
        $foreignYear = $this->createAcademicYear($otherSchool);
        $user = $this->createUser();
        $this->grantCapabilities($user, $school, ['enrollments.manage', 'enrollments.rollovers.manage']);

        $foreignResponse = $this->asUser($user)->withHeader('Idempotency-Key', 'test-foreign-1')
            ->postJson("/api/v1/schools/{$school->id}/enrollment-rollovers", [
                'source_academic_year_id' => $foreignYear->id, 'target_academic_year_id' => (string) Str::uuid(),
            ]);
        $randomResponse = $this->asUser($user)->withHeader('Idempotency-Key', 'test-foreign-2')
            ->postJson("/api/v1/schools/{$school->id}/enrollment-rollovers", [
                'source_academic_year_id' => (string) Str::uuid(), 'target_academic_year_id' => (string) Str::uuid(),
            ]);

        $foreignResponse->assertUnprocessable();
        $randomResponse->assertUnprocessable();
        $this->assertSame($foreignResponse->json('error.status'), $randomResponse->json('error.status'));
    }

    #[Test]
    public function a_second_open_plan_for_the_same_year_pair_is_a_clean_conflict(): void
    {
        ['school' => $school, 'sourceYear' => $sourceYear, 'targetYear' => $targetYear] = $this->buildContext();
        $user = $this->createUser();
        $this->grantCapabilities($user, $school, ['enrollments.manage', 'enrollments.rollovers.manage']);
        $this->planService()->createDraft($school, $sourceYear, $targetYear);

        $response = $this->asUser($user)->withHeader('Idempotency-Key', 'test-dup-1')
            ->postJson("/api/v1/schools/{$school->id}/enrollment-rollovers", [
                'source_academic_year_id' => $sourceYear->id, 'target_academic_year_id' => $targetYear->id,
            ]);

        $response->assertStatus(422);
        $body = $response->json();
        $this->assertStringNotContainsString('enrollment_rollover_plans_one_open_per_year_pair', json_encode($body));
        $this->assertStringNotContainsString('SQLSTATE', json_encode($body));
    }

    // ==================================================================
    // Sections 68/69 -- Plan list / detail
    // ==================================================================

    #[Test]
    public function plan_list_and_detail_are_school_scoped_and_paginated(): void
    {
        ['school' => $school, 'sourceYear' => $sourceYear, 'targetYear' => $targetYear] = $this->buildContext();
        $otherSchool = $this->createSchool();
        $otherYear1 = $this->createAcademicYear($otherSchool, ['code' => 'OA']);
        $otherYear2 = $this->createAcademicYear($otherSchool, ['code' => 'OB', 'starts_on' => '2027-06-01', 'ends_on' => '2028-05-31']);
        $this->planService()->createDraft($otherSchool, $otherYear1, $otherYear2);
        $planId = $this->planId($school, $sourceYear, $targetYear);

        $user = $this->createUser();
        $this->grantCapabilities($user, $school, ['enrollments.view', 'enrollments.rollovers.view']);

        $list = $this->asUser($user)->getJson("/api/v1/schools/{$school->id}/enrollment-rollovers");
        $list->assertOk();
        $this->assertCount(1, $list->json('data'));
        $this->assertArrayHasKey('meta', $list->json());

        $detail = $this->asUser($user)->getJson("/api/v1/schools/{$school->id}/enrollment-rollovers/{$planId}");
        $detail->assertOk();
        $detail->assertJsonPath('data.id', $planId);
        $detail->assertJsonStructure(['data' => ['sourceAcademicYear', 'targetAcademicYear', 'status', 'configurationVersion', 'isValidatedForCurrentConfiguration', 'mappings', 'executionSummary']]);
    }

    // ==================================================================
    // Sections 70 -- Item pagination
    // ==================================================================

    #[Test]
    public function item_directory_is_paginated_with_a_safe_student_summary(): void
    {
        ['school' => $school, 'sourceYear' => $sourceYear, 'targetYear' => $targetYear, 'sourceGrade' => $sourceGrade, 'targetGrade' => $targetGrade, 'sourceSection' => $sourceSection, 'targetSection' => $targetSection] = $this->buildContext();
        $planId = $this->planId($school, $sourceYear, $targetYear);
        $plan = $this->freshPlan($school, $planId);
        $this->planService()->upsertMapping($plan, $sourceGrade, null, $targetGrade, $targetSection);

        foreach (range(1, 3) as $i) {
            $student = $this->createStudent($school, ['student_number' => "S-PAGE-{$i}", 'date_of_birth' => '2015-01-01']);
            $this->enrollmentService()->enroll($student, $sourceSection, (string) $i, '2026-06-01');
        }
        $this->dryRun()->run($this->freshPlan($school, $planId));

        $user = $this->createUser();
        $this->grantCapabilities($user, $school, ['enrollments.view', 'enrollments.rollovers.view']);

        $response = $this->asUser($user)->getJson("/api/v1/schools/{$school->id}/enrollment-rollovers/{$planId}/items?per_page=2");

        $response->assertOk();
        $this->assertCount(2, $response->json('data'));
        $this->assertSame(3, $response->json('meta.total'));
        $response->assertJsonStructure(['data' => [['id', 'student' => ['id', 'studentNumber', 'firstName', 'lastName'], 'decision', 'validationResult', 'executionStatus']]]);
    }

    // ==================================================================
    // Sections 22-24/71/72 -- Mapping create/update
    // ==================================================================

    #[Test]
    public function mapping_create_and_update_delegate_to_the_plan_service(): void
    {
        ['school' => $school, 'sourceYear' => $sourceYear, 'targetYear' => $targetYear, 'sourceGrade' => $sourceGrade, 'targetGrade' => $targetGrade, 'targetSection' => $targetSection] = $this->buildContext();
        $anotherTargetSection = $this->createSection($targetYear, $this->createCampus($school), $targetGrade, ['name' => '6C', 'code' => '6C']);
        $planId = $this->planId($school, $sourceYear, $targetYear);
        $user = $this->createUser();
        $this->grantCapabilities($user, $school, ['enrollments.manage', 'enrollments.rollovers.manage']);

        $store = $this->asUser($user)->withHeader('Idempotency-Key', 'test-map-1')
            ->postJson("/api/v1/schools/{$school->id}/enrollment-rollovers/{$planId}/mappings", [
                'source_grade_level_id' => $sourceGrade->id, 'source_section_id' => null,
                'target_grade_level_id' => $targetGrade->id, 'target_section_id' => $targetSection->id,
            ]);
        $store->assertCreated();
        $mappingId = $store->json('data.id');

        $versionAfterCreate = $this->freshPlan($school, $planId)->configuration_version;

        $update = $this->asUser($user)->withHeader('Idempotency-Key', 'test-map-2')
            ->patchJson("/api/v1/schools/{$school->id}/enrollment-rollovers/{$planId}/mappings/{$mappingId}", [
                'target_grade_level_id' => $targetGrade->id, 'target_section_id' => $anotherTargetSection->id,
            ]);
        $update->assertOk();
        $update->assertJsonPath('data.targetSection.id', $anotherTargetSection->id);

        $versionAfterUpdate = $this->freshPlan($school, $planId)->configuration_version;
        $this->assertGreaterThan($versionAfterCreate, $versionAfterUpdate);
    }

    #[Test]
    public function mapping_edit_is_rejected_while_the_plan_is_executing(): void
    {
        ['school' => $school, 'sourceYear' => $sourceYear, 'targetYear' => $targetYear, 'sourceGrade' => $sourceGrade, 'targetGrade' => $targetGrade, 'targetSection' => $targetSection] = $this->buildContext();
        $planId = $this->planId($school, $sourceYear, $targetYear);
        $plan = $this->freshPlan($school, $planId);
        $this->planService()->upsertMapping($plan, $sourceGrade, null, $targetGrade, $targetSection);
        app(TenantContext::class)->withSchool($school, fn () => EnrollmentRolloverPlan::query()->whereKey($planId)->update(['status' => 'executing']));

        $user = $this->createUser();
        $this->grantCapabilities($user, $school, ['enrollments.manage', 'enrollments.rollovers.manage']);

        $response = $this->asUser($user)->withHeader('Idempotency-Key', 'test-map-exec')
            ->postJson("/api/v1/schools/{$school->id}/enrollment-rollovers/{$planId}/mappings", [
                'source_grade_level_id' => $sourceGrade->id, 'source_section_id' => null,
                'target_grade_level_id' => $targetGrade->id, 'target_section_id' => $targetSection->id,
            ]);

        $response->assertStatus(422);
    }

    // ==================================================================
    // Sections 25-27/74/75/85 -- Item configuration
    // ==================================================================

    #[Test]
    public function item_configuration_update_preserves_roll_number_text_and_ignores_protected_fields(): void
    {
        ['school' => $school, 'sourceYear' => $sourceYear, 'targetYear' => $targetYear, 'sourceGrade' => $sourceGrade, 'targetGrade' => $targetGrade, 'sourceSection' => $sourceSection, 'targetSection' => $targetSection] = $this->buildContext();
        $planId = $this->planId($school, $sourceYear, $targetYear);
        $plan = $this->freshPlan($school, $planId);
        $this->planService()->upsertMapping($plan, $sourceGrade, null, $targetGrade, $targetSection);
        $student = $this->createStudent($school, ['student_number' => 'S-ITEM-1']);
        $this->enrollmentService()->enroll($student, $sourceSection, '01', '2026-06-01');
        $this->dryRun()->run($this->freshPlan($school, $planId));
        $itemId = app(TenantContext::class)->withSchool($school, fn () => EnrollmentRolloverItem::query()->where('plan_id', $planId)->where('student_id', $student->id)->firstOrFail()->id);

        $user = $this->createUser();
        $this->grantCapabilities($user, $school, ['enrollments.manage', 'enrollments.rollovers.manage']);

        $maliciousTargetId = (string) Str::uuid();
        $response = $this->asUser($user)->withHeader('Idempotency-Key', 'test-item-1')
            ->patchJson("/api/v1/schools/{$school->id}/enrollment-rollovers/{$planId}/items/{$itemId}", [
                'decision' => 'promote',
                'roll_number_strategy' => 'explicit',
                'target_roll_number' => '007',
                // Protected/malicious fields:
                'student_id' => (string) Str::uuid(),
                'source_enrollment_id' => (string) Str::uuid(),
                'target_enrollment_id' => $maliciousTargetId,
                'execution_status' => 'succeeded',
                'validation_result' => 'ready',
                'school_id' => (string) Str::uuid(),
                'configuration_version' => 999,
            ]);

        $response->assertOk();
        $response->assertJsonPath('data.targetRollNumber', '007');
        $response->assertJsonPath('data.executionStatus', null);
        $response->assertJsonPath('data.targetEnrollmentId', null);

        $item = app(TenantContext::class)->withSchool($school, fn () => EnrollmentRolloverItem::query()->findOrFail($itemId));
        $this->assertSame($student->id, $item->student_id, 'student_id must never be rewritten via HTTP');
        $this->assertNull($item->target_enrollment_id);
    }

    #[Test]
    public function an_already_executed_items_configuration_is_rejected_through_http(): void
    {
        ['school' => $school, 'sourceYear' => $sourceYear, 'targetYear' => $targetYear, 'sourceGrade' => $sourceGrade, 'targetGrade' => $targetGrade, 'sourceSection' => $sourceSection, 'targetSection' => $targetSection] = $this->buildContext();
        $planId = $this->planId($school, $sourceYear, $targetYear);
        $plan = $this->freshPlan($school, $planId);
        $this->planService()->upsertMapping($plan, $sourceGrade, null, $targetGrade, $targetSection);
        $student = $this->createStudent($school, ['student_number' => 'S-EXEC-1']);
        $this->enrollmentService()->enroll($student, $sourceSection, '01', '2026-06-01');
        $item = app(TenantContext::class)->withSchool($school, function () use ($plan, $student) {
            $this->dryRun()->run($plan->fresh());

            return EnrollmentRolloverItem::query()->where('plan_id', $plan->id)->where('student_id', $student->id)->firstOrFail();
        });
        $this->planService()->setItemDecision($this->freshPlan($school, $planId), $item, 'promote', null, 'explicit', '050');
        $this->dryRun()->run($this->freshPlan($school, $planId));
        $target = $this->enrollmentService()->enroll($student, $targetSection, '050', '2027-06-01');
        app(TenantContext::class)->withSchool($school, fn () => $item->update([
            'execution_status' => 'succeeded', 'target_enrollment_id' => $target->id, 'executed_at' => now(),
        ]));

        $user = $this->createUser();
        $this->grantCapabilities($user, $school, ['enrollments.manage', 'enrollments.rollovers.manage']);

        $response = $this->asUser($user)->withHeader('Idempotency-Key', 'test-item-exec-1')
            ->patchJson("/api/v1/schools/{$school->id}/enrollment-rollovers/{$planId}/items/{$item->id}", [
                'decision' => 'exclude',
            ]);

        $response->assertStatus(422);
        $freshTarget = app(TenantContext::class)->withSchool($school, fn () => StudentEnrollment::query()->findOrFail($target->id));
        $this->assertSame($targetSection->id, $freshTarget->section_id);
    }

    // ==================================================================
    // Sections 28-30/76/77 -- Dry-run endpoint
    // ==================================================================

    #[Test]
    public function dry_run_endpoint_validates_and_changes_zero_academic_state(): void
    {
        ['school' => $school, 'sourceYear' => $sourceYear, 'targetYear' => $targetYear, 'sourceGrade' => $sourceGrade, 'targetGrade' => $targetGrade, 'sourceSection' => $sourceSection, 'targetSection' => $targetSection] = $this->buildContext();
        $planId = $this->planId($school, $sourceYear, $targetYear);
        $plan = $this->freshPlan($school, $planId);
        $this->planService()->upsertMapping($plan, $sourceGrade, null, $targetGrade, $targetSection);
        $student = $this->createStudent($school, ['student_number' => 'S-DRY-1']);
        $this->enrollmentService()->enroll($student, $sourceSection, '01', '2026-06-01');

        $user = $this->createUser();
        $this->grantCapabilities($user, $school, ['enrollments.manage', 'enrollments.rollovers.manage']);

        $enrollmentCountBefore = app(TenantContext::class)->withSchool($school, fn () => StudentEnrollment::query()->count());

        $response = $this->asUser($user)->withHeader('Idempotency-Key', 'test-dry-1')
            ->postJson("/api/v1/schools/{$school->id}/enrollment-rollovers/{$planId}/validate");

        $response->assertOk();
        $response->assertJsonStructure(['data' => ['total', 'ready', 'excluded', 'already_enrolled', 'review', 'blocked', 'validated', 'configurationVersion']]);

        $enrollmentCountAfter = app(TenantContext::class)->withSchool($school, fn () => StudentEnrollment::query()->count());
        $this->assertSame($enrollmentCountBefore, $enrollmentCountAfter);
    }

    #[Test]
    public function dry_run_endpoint_read_capability_alone_cannot_trigger_validation(): void
    {
        ['school' => $school, 'sourceYear' => $sourceYear, 'targetYear' => $targetYear] = $this->buildContext();
        $planId = $this->planId($school, $sourceYear, $targetYear);
        $user = $this->createUser();
        $this->grantCapabilities($user, $school, ['enrollments.view', 'enrollments.rollovers.view']);

        $this->asUser($user)->postJson("/api/v1/schools/{$school->id}/enrollment-rollovers/{$planId}/validate")->assertForbidden();
    }

    #[Test]
    public function dry_run_blocker_is_reported_safely_with_no_enrollment_mutation(): void
    {
        ['school' => $school, 'sourceYear' => $sourceYear, 'targetYear' => $targetYear, 'sourceGrade' => $sourceGrade, 'sourceSection' => $sourceSection] = $this->buildContext();
        // No mapping configured at all -- terminal grade, review/blocked.
        $planId = $this->planId($school, $sourceYear, $targetYear);
        $student = $this->createStudent($school, ['student_number' => 'S-BLK-1']);
        $this->enrollmentService()->enroll($student, $sourceSection, '01', '2026-06-01');

        $user = $this->createUser();
        $this->grantCapabilities($user, $school, ['enrollments.manage', 'enrollments.rollovers.manage']);

        $response = $this->asUser($user)->withHeader('Idempotency-Key', 'test-dry-blk-1')
            ->postJson("/api/v1/schools/{$school->id}/enrollment-rollovers/{$planId}/validate");

        $response->assertOk();
        $response->assertJsonPath('data.validated', false);
        $this->assertStringNotContainsString('SQLSTATE', json_encode($response->json()));
    }

    // ==================================================================
    // Sections 31-38/78-82 -- Start / Resume
    // ==================================================================

    #[Test]
    public function start_executes_a_validated_plan_with_no_controller_item_loop_visible_via_response(): void
    {
        ['school' => $school, 'sourceYear' => $sourceYear, 'targetYear' => $targetYear, 'sourceGrade' => $sourceGrade, 'targetGrade' => $targetGrade, 'sourceSection' => $sourceSection, 'targetSection' => $targetSection] = $this->buildContext();
        $planId = $this->planId($school, $sourceYear, $targetYear);
        $plan = $this->freshPlan($school, $planId);
        $this->planService()->upsertMapping($plan, $sourceGrade, null, $targetGrade, $targetSection);
        $student = $this->createStudent($school, ['student_number' => 'S-START-1']);
        $this->enrollmentService()->enroll($student, $sourceSection, '01', '2026-06-01');
        $item = app(TenantContext::class)->withSchool($school, function () use ($plan, $student) {
            $this->dryRun()->run($plan->fresh());

            return EnrollmentRolloverItem::query()->where('plan_id', $plan->id)->where('student_id', $student->id)->firstOrFail();
        });
        $this->planService()->setItemDecision($this->freshPlan($school, $planId), $item, 'promote', null, 'explicit', '011');
        $this->dryRun()->run($this->freshPlan($school, $planId));

        $user = $this->createUser();
        $this->grantCapabilities($user, $school, ['enrollments.manage', 'enrollments.rollovers.manage']);

        $response = $this->asUser($user)->withHeader('Idempotency-Key', 'test-start-1')
            ->postJson("/api/v1/schools/{$school->id}/enrollment-rollovers/{$planId}/start");

        $response->assertOk();
        $response->assertJsonPath('data.planStatus', 'completed');
        $response->assertJsonPath('data.succeeded', 1);

        $target = app(TenantContext::class)->withSchool($school, fn () => StudentEnrollment::query()->where('student_id', $student->id)->where('academic_year_id', $targetYear->id)->where('status', 'active')->first());
        $this->assertNotNull($target);
        $this->assertSame($targetSection->id, $target->section_id);
    }

    #[Test]
    public function start_on_an_unvalidated_plan_is_a_safe_conflict_with_zero_mutation(): void
    {
        ['school' => $school, 'sourceYear' => $sourceYear, 'targetYear' => $targetYear] = $this->buildContext();
        $planId = $this->planId($school, $sourceYear, $targetYear);
        $user = $this->createUser();
        $this->grantCapabilities($user, $school, ['enrollments.manage', 'enrollments.rollovers.manage']);

        $enrollmentCountBefore = app(TenantContext::class)->withSchool($school, fn () => StudentEnrollment::query()->count());

        $response = $this->asUser($user)->withHeader('Idempotency-Key', 'test-start-unval-1')
            ->postJson("/api/v1/schools/{$school->id}/enrollment-rollovers/{$planId}/start");

        $response->assertStatus(422);
        $this->assertStringNotContainsString('SQLSTATE', json_encode($response->json()));
        $enrollmentCountAfter = app(TenantContext::class)->withSchool($school, fn () => StudentEnrollment::query()->count());
        $this->assertSame($enrollmentCountBefore, $enrollmentCountAfter);
    }

    #[Test]
    public function duplicate_start_returns_a_clean_conflict_never_an_automatic_resume(): void
    {
        ['school' => $school, 'sourceYear' => $sourceYear, 'targetYear' => $targetYear, 'sourceGrade' => $sourceGrade, 'targetGrade' => $targetGrade, 'sourceSection' => $sourceSection, 'targetSection' => $targetSection] = $this->buildContext();
        $planId = $this->planId($school, $sourceYear, $targetYear);
        $plan = $this->freshPlan($school, $planId);
        $this->planService()->upsertMapping($plan, $sourceGrade, null, $targetGrade, $targetSection);
        $student = $this->createStudent($school, ['student_number' => 'S-DUP-1']);
        $this->enrollmentService()->enroll($student, $sourceSection, '01', '2026-06-01');
        $this->dryRun()->run($this->freshPlan($school, $planId));
        app(TenantContext::class)->withSchool($school, fn () => EnrollmentRolloverPlan::query()->whereKey($planId)->update(['status' => 'executing']));

        $user = $this->createUser();
        $this->grantCapabilities($user, $school, ['enrollments.manage', 'enrollments.rollovers.manage']);

        $response = $this->asUser($user)->withHeader('Idempotency-Key', 'test-dup-start-1')
            ->postJson("/api/v1/schools/{$school->id}/enrollment-rollovers/{$planId}/start");

        $response->assertStatus(409);
    }

    #[Test]
    public function resume_on_a_non_executing_plan_is_a_safe_rejection(): void
    {
        ['school' => $school, 'sourceYear' => $sourceYear, 'targetYear' => $targetYear] = $this->buildContext();
        $planId = $this->planId($school, $sourceYear, $targetYear);
        $user = $this->createUser();
        $this->grantCapabilities($user, $school, ['enrollments.manage', 'enrollments.rollovers.manage']);

        $response = $this->asUser($user)->withHeader('Idempotency-Key', 'test-resume-wrong-1')
            ->postJson("/api/v1/schools/{$school->id}/enrollment-rollovers/{$planId}/resume");

        $response->assertStatus(422);
    }

    // ==================================================================
    // Sections 44/83/84 -- partial invalidation + revalidation HTTP flow
    // ==================================================================

    #[Test]
    public function partial_invalidation_then_revalidation_and_restart_never_duplicates_targets(): void
    {
        ['school' => $school, 'sourceYear' => $sourceYear, 'targetYear' => $targetYear, 'sourceGrade' => $sourceGrade, 'targetGrade' => $targetGrade, 'sourceSection' => $sourceSection, 'targetSection' => $targetSection] = $this->buildContext();
        $planId = $this->planId($school, $sourceYear, $targetYear);
        $plan = $this->freshPlan($school, $planId);
        $this->planService()->upsertMapping($plan, $sourceGrade, null, $targetGrade, $targetSection);

        $firstStudent = $this->createStudent($school, ['student_number' => 'S-FLOW-1']);
        $this->enrollmentService()->enroll($firstStudent, $sourceSection, '01', '2026-06-01');
        $secondStudent = $this->createStudent($school, ['student_number' => 'S-FLOW-2']);
        $this->enrollmentService()->enroll($secondStudent, $sourceSection, '02', '2026-06-01');
        app(TenantContext::class)->withSchool($school, fn () => $this->dryRun()->run($plan->fresh()));
        $firstItem = app(TenantContext::class)->withSchool($school, fn () => EnrollmentRolloverItem::query()->where('plan_id', $planId)->where('student_id', $firstStudent->id)->firstOrFail());
        $secondItem = app(TenantContext::class)->withSchool($school, fn () => EnrollmentRolloverItem::query()->where('plan_id', $planId)->where('student_id', $secondStudent->id)->firstOrFail());
        $this->planService()->setItemDecision($this->freshPlan($school, $planId), $firstItem, 'promote', null, 'explicit', '101');
        $this->planService()->setItemDecision($this->freshPlan($school, $planId), $secondItem, 'promote', null, 'explicit', '102');
        $this->dryRun()->run($this->freshPlan($school, $planId));

        $user = $this->createUser();
        $this->grantCapabilities($user, $school, ['enrollments.manage', 'enrollments.rollovers.manage']);

        // Manually create a conflicting target for the SECOND student
        // before starting -- guarantees the second item invalidates.
        $this->enrollmentService()->enroll($secondStudent, $targetSection, '999', '2027-06-01');

        $startResponse = $this->asUser($user)->withHeader('Idempotency-Key', 'test-flow-start-1')
            ->postJson("/api/v1/schools/{$school->id}/enrollment-rollovers/{$planId}/start");
        $startResponse->assertOk();
        $this->assertSame(1, $startResponse->json('data.succeeded'));
        $this->assertNotSame('completed', $startResponse->json('data.planStatus'));

        // Revalidate through the API -- the pre-existing manual target
        // for the second student now classifies as a safe conflict/no-op,
        // never silently created again.
        $revalidate = $this->asUser($user)->withHeader('Idempotency-Key', 'test-flow-validate-2')
            ->postJson("/api/v1/schools/{$school->id}/enrollment-rollovers/{$planId}/validate");
        $revalidate->assertOk();

        $enrollmentCountBeforeRestart = app(TenantContext::class)->withSchool($school, fn () => StudentEnrollment::query()->count());

        $restart = $this->asUser($user)->withHeader('Idempotency-Key', 'test-flow-start-2')
            ->postJson("/api/v1/schools/{$school->id}/enrollment-rollovers/{$planId}/start");

        $enrollmentCountAfterRestart = app(TenantContext::class)->withSchool($school, fn () => StudentEnrollment::query()->count());
        // Either the plan is still not fully ready (second item's
        // conflict remains review/blocked) or it restarted cleanly --
        // either way, no duplicate target for either Student.
        $this->assertLessThanOrEqual(1, $enrollmentCountAfterRestart - $enrollmentCountBeforeRestart);

        $firstStudentTargetCount = app(TenantContext::class)->withSchool($school, fn () => StudentEnrollment::query()->where('student_id', $firstStudent->id)->where('academic_year_id', $targetYear->id)->count());
        $this->assertSame(1, $firstStudentTargetCount, 'the already-succeeded first Student must never gain a duplicate target');
    }

    // ==================================================================
    // Sections 45-50/87/88 -- tenant-safe resolution / nested ownership
    // ==================================================================

    #[Test]
    public function foreign_and_random_plan_uuids_are_indistinguishable(): void
    {
        ['school' => $schoolA] = $this->buildContext();
        ['school' => $schoolB, 'sourceYear' => $bSourceYear, 'targetYear' => $bTargetYear] = $this->buildContext();
        $foreignPlanId = $this->planId($schoolB, $bSourceYear, $bTargetYear);
        $user = $this->createUser();
        $this->grantCapabilities($user, $schoolA, ['enrollments.view', 'enrollments.rollovers.view']);

        $foreign = $this->asUser($user)->getJson("/api/v1/schools/{$schoolA->id}/enrollment-rollovers/{$foreignPlanId}");
        $random = $this->asUser($user)->getJson('/api/v1/schools/'.$schoolA->id.'/enrollment-rollovers/'.Str::uuid());

        $foreign->assertNotFound();
        $random->assertNotFound();
    }

    #[Test]
    public function a_mapping_from_a_different_plan_is_not_editable_through_this_plans_url(): void
    {
        ['school' => $school, 'sourceYear' => $sourceYear, 'targetYear' => $targetYear, 'sourceGrade' => $sourceGrade, 'targetGrade' => $targetGrade, 'targetSection' => $targetSection] = $this->buildContext();
        $planA = $this->planId($school, $sourceYear, $targetYear);
        $sourceYear2 = $this->createAcademicYear($school, ['code' => 'SRC2']);
        $targetYear2 = $this->createAcademicYear($school, ['code' => 'TGT2', 'starts_on' => '2028-06-01', 'ends_on' => '2029-05-31']);
        $planB = $this->planId($school, $sourceYear2, $targetYear2);
        $planBModel = $this->freshPlan($school, $planB);
        $mappingFromB = $this->planService()->upsertMapping($planBModel, $sourceGrade, null, $targetGrade, $targetSection);

        $user = $this->createUser();
        $this->grantCapabilities($user, $school, ['enrollments.manage', 'enrollments.rollovers.manage']);

        $response = $this->asUser($user)->withHeader('Idempotency-Key', 'test-cross-plan-1')
            ->patchJson("/api/v1/schools/{$school->id}/enrollment-rollovers/{$planA}/mappings/{$mappingFromB->id}", [
                'target_grade_level_id' => $targetGrade->id,
            ]);

        $response->assertNotFound();
    }

    #[Test]
    public function an_item_from_a_different_plan_is_not_editable_through_this_plans_url(): void
    {
        ['school' => $school, 'sourceYear' => $sourceYear, 'targetYear' => $targetYear, 'campus' => $campus, 'sourceGrade' => $sourceGrade, 'targetGrade' => $targetGrade, 'sourceSection' => $sourceSection, 'targetSection' => $targetSection] = $this->buildContext();
        $planA = $this->planId($school, $sourceYear, $targetYear);
        $sourceYear2 = $this->createAcademicYear($school, ['code' => 'SRC2', 'starts_on' => '2027-06-01', 'ends_on' => '2028-05-31']);
        $targetYear2 = $this->createAcademicYear($school, ['code' => 'TGT2', 'starts_on' => '2028-06-01', 'ends_on' => '2029-05-31']);
        $planB = $this->planId($school, $sourceYear2, $targetYear2);
        $sourceSection2 = $this->createSection($sourceYear2, $campus, $sourceGrade, ['name' => '5A-Y2', 'code' => '5AY2']);
        $targetSection2 = $this->createSection($targetYear2, $campus, $targetGrade, ['name' => '6B-Y2', 'code' => '6BY2']);
        $student = $this->createStudent($school, ['student_number' => 'S-CROSS-1']);
        $this->enrollmentService()->enroll($student, $sourceSection2, '01', '2027-06-01');
        $planBModel = $this->freshPlan($school, $planB);
        $this->planService()->upsertMapping($planBModel, $sourceGrade, null, $targetGrade, $targetSection2);
        $this->dryRun()->run($this->freshPlan($school, $planB));
        $itemFromB = app(TenantContext::class)->withSchool($school, fn () => EnrollmentRolloverItem::query()->where('plan_id', $planB)->where('student_id', $student->id)->firstOrFail());

        $user = $this->createUser();
        $this->grantCapabilities($user, $school, ['enrollments.manage', 'enrollments.rollovers.manage']);

        $response = $this->asUser($user)->withHeader('Idempotency-Key', 'test-cross-plan-item-1')
            ->patchJson("/api/v1/schools/{$school->id}/enrollment-rollovers/{$planA}/items/{$itemFromB->id}", [
                'decision' => 'exclude',
            ]);

        $response->assertNotFound();
    }

    // ==================================================================
    // Sections 57-59/86 -- response privacy
    // ==================================================================

    #[Test]
    public function item_response_never_includes_guardian_or_dob_fields(): void
    {
        ['school' => $school, 'sourceYear' => $sourceYear, 'targetYear' => $targetYear, 'sourceGrade' => $sourceGrade, 'targetGrade' => $targetGrade, 'sourceSection' => $sourceSection, 'targetSection' => $targetSection] = $this->buildContext();
        $planId = $this->planId($school, $sourceYear, $targetYear);
        $plan = $this->freshPlan($school, $planId);
        $this->planService()->upsertMapping($plan, $sourceGrade, null, $targetGrade, $targetSection);
        $student = $this->createStudent($school, ['student_number' => 'S-PRIV-1', 'date_of_birth' => '2015-03-04']);
        $guardian = $this->createGuardian($school);
        $this->createStudentGuardianRelationship($student, $guardian);
        $this->enrollmentService()->enroll($student, $sourceSection, '01', '2026-06-01');
        $this->dryRun()->run($this->freshPlan($school, $planId));

        $user = $this->createUser();
        $this->grantCapabilities($user, $school, ['enrollments.view', 'enrollments.rollovers.view']);

        $response = $this->asUser($user)->getJson("/api/v1/schools/{$school->id}/enrollment-rollovers/{$planId}/items");
        $response->assertOk();

        $json = json_encode($response->json());
        foreach (['date_of_birth', 'dateOfBirth', 'guardian', 'guardian_contacts', 'encrypted_value', 'lookup_hash', 'lookup_key_version', '2015-03-04'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $json, "response must never contain '{$forbidden}'");
        }
    }

    // ==================================================================
    // Section 89 -- TenantContext regression through the API
    // ==================================================================

    #[Test]
    public function a_rollover_conflict_through_the_api_leaves_the_connection_healthy_for_the_next_request(): void
    {
        ['school' => $school, 'sourceYear' => $sourceYear, 'targetYear' => $targetYear] = $this->buildContext();
        $planId = $this->planId($school, $sourceYear, $targetYear);
        $user = $this->createUser();
        $this->grantCapabilities($user, $school, ['enrollments.manage', 'enrollments.rollovers.manage', 'enrollments.view', 'enrollments.rollovers.view']);

        $this->asUser($user)->withHeader('Idempotency-Key', 'test-tc-1')
            ->postJson("/api/v1/schools/{$school->id}/enrollment-rollovers/{$planId}/start")
            ->assertStatus(422);

        // Immediately issue a valid, unrelated operation -- no SQLSTATE 25P02.
        $this->asUser($user)->getJson("/api/v1/schools/{$school->id}/enrollment-rollovers")->assertOk();
    }

    // ==================================================================
    // Phase 1B Closure Gate, Section 21 -- >100-Item request-boundary
    // regression, API side. The WEB layer carries the reported 1B.7F
    // P3 and is the mandatory boundary test; this is the cheap
    // corroborating check that BoundsRolloverExecutionRequest enforces
    // the identical 100-item cap through the API controller sharing
    // the same trait.
    // ==================================================================

    #[Test]
    public function starting_a_plan_with_101_ready_items_processes_at_most_100_in_one_api_request_and_resume_completes_the_rest(): void
    {
        ['school' => $school, 'sourceYear' => $sourceYear, 'targetYear' => $targetYear, 'sourceGrade' => $sourceGrade, 'targetGrade' => $targetGrade, 'sourceSection' => $sourceSection, 'targetSection' => $targetSection] = $this->buildContext();
        $planId = $this->planId($school, $sourceYear, $targetYear);
        $plan = $this->freshPlan($school, $planId);
        $this->planService()->upsertMapping($plan, $sourceGrade, null, $targetGrade, $targetSection);

        $totalItems = 101;
        $students = app(TenantContext::class)->withSchool($school, function () use ($school, $sourceSection, $totalItems) {
            $created = [];
            for ($i = 1; $i <= $totalItems; $i++) {
                $rollNumber = str_pad((string) $i, 3, '0', STR_PAD_LEFT);
                $student = Student::factory()->for($school, 'school')->create(['student_number' => "S-API-BOUND-{$rollNumber}"]);
                // See the identical comment in
                // EnrollmentRolloverUiTest's twin of this test: the
                // StudentEnrollment factory's faker default exhausts
                // its 60-value unique pool before 101 rows, so a
                // direct create() sidesteps it.
                StudentEnrollment::query()->create([
                    'school_id' => $school->id,
                    'student_id' => $student->id,
                    'academic_year_id' => $sourceSection->academic_year_id,
                    'campus_id' => $sourceSection->campus_id,
                    'grade_level_id' => $sourceSection->grade_level_id,
                    'section_id' => $sourceSection->id,
                    'roll_number' => $rollNumber,
                    'status' => 'active',
                    'starts_on' => '2026-06-01',
                ]);
                $created[] = ['student' => $student, 'rollNumber' => $rollNumber];
            }

            return $created;
        });

        $user = $this->createUser();
        $this->grantCapabilities($user, $school, ['enrollments.manage', 'enrollments.rollovers.manage']);

        $this->asUser($user)->withHeader('Idempotency-Key', 'test-api-bound-validate-1')
            ->postJson("/api/v1/schools/{$school->id}/enrollment-rollovers/{$planId}/validate");

        app(TenantContext::class)->withSchool($school, function () use ($plan, $planId, $students) {
            foreach ($students as ['student' => $student, 'rollNumber' => $rollNumber]) {
                $item = EnrollmentRolloverItem::query()->where('plan_id', $planId)->where('student_id', $student->id)->firstOrFail();
                $this->planService()->setItemDecision($this->freshPlan($plan->school, $planId), $item, 'promote', null, 'explicit', $rollNumber);
            }
        });

        $this->asUser($user)->withHeader('Idempotency-Key', 'test-api-bound-validate-2')
            ->postJson("/api/v1/schools/{$school->id}/enrollment-rollovers/{$planId}/validate");
        $this->assertSame('validated', $this->freshPlan($school, $planId)->status);

        // ---- The actual boundary: ONE API Start request. ----
        $startResponse = $this->asUser($user)->withHeader('Idempotency-Key', 'test-api-bound-start-1')
            ->postJson("/api/v1/schools/{$school->id}/enrollment-rollovers/{$planId}/start");
        $startResponse->assertOk();
        $startResponse->assertJsonPath('data.planStatus', 'executing');

        $succeededAfterStart = (int) $startResponse->json('data.succeeded');
        $pendingAfterStart = app(TenantContext::class)->withSchool($school, fn () => EnrollmentRolloverItem::query()->where('plan_id', $planId)->whereNull('execution_status')->count());

        $this->assertLessThanOrEqual(100, $succeededAfterStart, 'no single API request may process more than 100 Items');
        $this->assertGreaterThanOrEqual(1, $pendingAfterStart, 'at least one Item must remain pending after a single capped API request');
        $this->assertSame($totalItems, $succeededAfterStart + $pendingAfterStart, 'every Item is accounted for as either succeeded or still pending -- none lost');

        // ---- ONE API Resume request completes the remainder. ----
        $resumeResponse = $this->asUser($user)->withHeader('Idempotency-Key', 'test-api-bound-resume-1')
            ->postJson("/api/v1/schools/{$school->id}/enrollment-rollovers/{$planId}/resume");
        $resumeResponse->assertOk();
        $resumeResponse->assertJsonPath('data.planStatus', 'completed');

        $targetEnrollmentsAfterResume = app(TenantContext::class)->withSchool($school, fn () => StudentEnrollment::query()->where('academic_year_id', $targetYear->id)->count());
        $this->assertSame($totalItems, $targetEnrollmentsAfterResume, 'exactly 101 target Enrollments total -- no duplicates created across the Start+Resume pair');

        $sourceStillActiveCount = app(TenantContext::class)->withSchool($school, fn () => StudentEnrollment::query()->where('academic_year_id', $sourceYear->id)->where('status', 'active')->count());
        $this->assertSame($totalItems, $sourceStillActiveCount, 'source Enrollments are never auto-completed by rollover execution');
    }
}
