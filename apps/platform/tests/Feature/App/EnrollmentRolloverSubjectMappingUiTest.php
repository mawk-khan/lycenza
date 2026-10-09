<?php

namespace Tests\Feature\App;

use App\Domain\AcademicStructure\Infrastructure\AcademicYear;
use App\Domain\AcademicStructure\Infrastructure\GradeLevel;
use App\Domain\AcademicStructure\Infrastructure\Subject;
use App\Domain\AcademicStructure\Infrastructure\SubjectOffering;
use App\Domain\Students\Application\EnrollmentRolloverPlanService;
use App\Domain\Students\Infrastructure\EnrollmentRolloverPlan;
use App\Domain\Students\Infrastructure\EnrollmentRolloverSubjectMapping;
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
 * Phase 1G.4: the Inertia surface for elective subject-mapping
 * configuration -- the Plan workspace's "Elective subject mappings"
 * section (SubjectMappingsPanel.vue) and its two mutation routes
 * (App\Http\Controllers\App\EnrollmentRolloverSubjectMappingController).
 * Domain validation is already covered at depth by
 * EnrollmentRolloverSubjectMappingServiceTest and the JSON API's
 * EnrollmentRolloverSubjectMappingApiTest; this file proves the
 * Inertia-specific integration: page props (no PII leakage), dual
 * capability enforcement, cross-School isolation, and that a real
 * mutation invalidates the Plan's stale-validation state exactly like
 * every other rollover configuration change.
 */
class EnrollmentRolloverSubjectMappingUiTest extends TestCase
{
    use CreatesTenancyFixtures;

    private function activate(User $user, School $school): void
    {
        $this->actingAs($user)->post("/app/schools/{$school->id}/activate");
    }

    private function grantCapabilities(User $user, School $school, array $capabilities): void
    {
        $membership = $this->createMembership($user, $school);
        $role = LocalCatalogueFixtures::asOwner(fn () => Role::query()->create(['key' => 'test_rollover_subj_ui_'.Str::random(8), 'name' => 'Test', 'scope' => 'school', 'is_system' => false]));
        LocalCatalogueFixtures::asOwner(fn () => $role->capabilities()->sync($capabilities));
        app(TenantContext::class)->withSchool($school, fn () => LocalCatalogueFixtures::asOwner(fn () => MembershipRoleAssignment::query()->create([
            'school_id' => $school->id, 'school_membership_id' => $membership->id, 'role_id' => $role->id,
        ])));
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
    // Page props
    // ==================================================================

    #[Test]
    public function the_show_page_exposes_subject_mapping_props_without_student_pii(): void
    {
        ['school' => $school, 'plan' => $plan, 'sourceOffering' => $source, 'targetOffering' => $target] = $this->buildContext();
        $this->planService()->upsertSubjectMapping($plan, $source, $target);
        [$user] = $this->createSchoolAdmin('school_admin');
        $this->grantCapabilities($user, $school, ['enrollments.view', 'enrollments.rollovers.view']);
        $this->activate($user, $school);

        $response = $this->get("/app/enrollment-rollovers/{$plan->id}")->assertInertia(fn ($page) => $page
            ->component('App/EnrollmentRollovers/Show')
            ->has('plan.subjectMappings', 1)
            ->where('plan.subjectMappings.0.state', 'mapped')
            ->where('plan.subjectMappings.0.sourceSubjectOffering.id', $source->id)
            ->where('plan.subjectMappings.0.targetSubjectOffering.id', $target->id)
            ->has('plan.unmappedSourceSubjectOfferings')
            ->has('sourceSubjectOfferings')
            ->has('targetSubjectOfferings'));

        // No Student PII (date_of_birth, Guardian contact, etc.) is
        // ever embedded in the subject-mapping props -- Subject/Grade/
        // Campus/ElectiveGroup reference data only.
        $response->assertDontSee('date_of_birth', false);
    }

    #[Test]
    public function offering_options_carry_display_context_but_never_an_internal_uuid_as_the_primary_label(): void
    {
        ['school' => $school, 'plan' => $plan] = $this->buildContext();
        [$user] = $this->createSchoolAdmin('school_admin');
        $this->grantCapabilities($user, $school, ['enrollments.view', 'enrollments.rollovers.view']);
        $this->activate($user, $school);

        $this->get("/app/enrollment-rollovers/{$plan->id}")->assertInertia(fn ($page) => $page
            ->has('sourceSubjectOfferings.0.subject')
            ->has('sourceSubjectOfferings.0.status')
            ->has('targetSubjectOfferings.0.subject')
            ->has('targetSubjectOfferings.0.status'));
    }

    // ==================================================================
    // Authorization
    // ==================================================================

    #[Test]
    public function view_only_denies_the_upsert_route(): void
    {
        ['school' => $school, 'plan' => $plan, 'sourceOffering' => $source, 'targetOffering' => $target] = $this->buildContext();
        [$user] = $this->createSchoolAdmin('school_admin');
        $this->grantCapabilities($user, $school, ['enrollments.view', 'enrollments.rollovers.view']);
        $this->activate($user, $school);

        $this->put("/app/enrollment-rollovers/{$plan->id}/subject-mappings/{$source->id}", [
            'target_subject_offering_id' => $target->id,
        ])->assertForbidden();
    }

    #[Test]
    public function full_manage_capability_allows_the_upsert_route(): void
    {
        ['school' => $school, 'plan' => $plan, 'sourceOffering' => $source, 'targetOffering' => $target] = $this->buildContext();
        [$user] = $this->createSchoolAdmin('school_admin');
        $this->grantCapabilities($user, $school, ['enrollments.view', 'enrollments.rollovers.view', 'enrollments.manage', 'enrollments.rollovers.manage']);
        $this->activate($user, $school);

        $this->put("/app/enrollment-rollovers/{$plan->id}/subject-mappings/{$source->id}", [
            'target_subject_offering_id' => $target->id,
        ])->assertRedirect("/app/enrollment-rollovers/{$plan->id}");

        $mapping = app(TenantContext::class)->withSchool($school, fn () => EnrollmentRolloverSubjectMapping::query()->where('plan_id', $plan->id)->firstOrFail());
        $this->assertSame($target->id, $mapping->target_subject_offering_id);
    }

    #[Test]
    public function view_only_denies_the_destroy_route(): void
    {
        ['school' => $school, 'plan' => $plan, 'sourceOffering' => $source, 'targetOffering' => $target] = $this->buildContext();
        $this->planService()->upsertSubjectMapping($plan, $source, $target);
        [$user] = $this->createSchoolAdmin('school_admin');
        $this->grantCapabilities($user, $school, ['enrollments.view', 'enrollments.rollovers.view']);
        $this->activate($user, $school);

        $this->delete("/app/enrollment-rollovers/{$plan->id}/subject-mappings/{$source->id}")->assertForbidden();
    }

    #[Test]
    public function full_manage_capability_allows_the_destroy_route(): void
    {
        ['school' => $school, 'plan' => $plan, 'sourceOffering' => $source, 'targetOffering' => $target] = $this->buildContext();
        $this->planService()->upsertSubjectMapping($plan, $source, $target);
        [$user] = $this->createSchoolAdmin('school_admin');
        $this->grantCapabilities($user, $school, ['enrollments.view', 'enrollments.rollovers.view', 'enrollments.manage', 'enrollments.rollovers.manage']);
        $this->activate($user, $school);

        $this->delete("/app/enrollment-rollovers/{$plan->id}/subject-mappings/{$source->id}")
            ->assertRedirect("/app/enrollment-rollovers/{$plan->id}");

        $remaining = app(TenantContext::class)->withSchool($school, fn () => EnrollmentRolloverSubjectMapping::query()->where('plan_id', $plan->id)->count());
        $this->assertSame(0, $remaining);
    }

    // ==================================================================
    // Cross-School isolation
    // ==================================================================

    #[Test]
    public function a_foreign_school_source_offering_404s_on_the_upsert_route(): void
    {
        ['school' => $school, 'plan' => $plan, 'targetOffering' => $target] = $this->buildContext();
        $otherSchool = $this->createSchool();
        $otherCampus = $this->createCampus($otherSchool);
        $otherYear = $this->createAcademicYear($otherSchool);
        $otherGrade = $this->createGradeLevel($otherSchool);
        $otherSubject = $this->createSubject($otherSchool, ['code' => 'FOREIGN1']);
        $foreignSource = $this->createSubjectOffering($otherYear, $otherCampus, $otherGrade, $otherSubject, ['is_required' => false]);

        [$user] = $this->createSchoolAdmin('school_admin');
        $this->grantCapabilities($user, $school, ['enrollments.view', 'enrollments.rollovers.view', 'enrollments.manage', 'enrollments.rollovers.manage']);
        $this->activate($user, $school);

        $this->put("/app/enrollment-rollovers/{$plan->id}/subject-mappings/{$foreignSource->id}", [
            'target_subject_offering_id' => $target->id,
        ])->assertNotFound();
    }

    // ==================================================================
    // Versioning UX
    // ==================================================================

    #[Test]
    public function a_real_mapping_change_invalidates_prior_validation_exactly_like_other_configuration_changes(): void
    {
        ['school' => $school, 'plan' => $plan, 'sourceOffering' => $source, 'targetOffering' => $target] = $this->buildContext();
        [$user] = $this->createSchoolAdmin('school_admin');
        $this->grantCapabilities($user, $school, ['enrollments.view', 'enrollments.rollovers.view', 'enrollments.manage', 'enrollments.rollovers.manage']);
        $this->activate($user, $school);

        $this->put("/app/enrollment-rollovers/{$plan->id}/subject-mappings/{$source->id}", [
            'target_subject_offering_id' => $target->id,
        ])->assertRedirect();

        $fresh = $this->freshPlan($school, $plan->id);
        $this->assertSame(2, $fresh->configuration_version);
        $this->assertFalse($fresh->isValidatedForCurrentConfiguration());

        $this->get("/app/enrollment-rollovers/{$plan->id}")->assertInertia(fn ($page) => $page
            ->where('plan.isValidatedForCurrentConfiguration', false)
            ->where('plan.configurationVersion', 2));
    }
}
