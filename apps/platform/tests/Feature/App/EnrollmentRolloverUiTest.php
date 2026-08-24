<?php

namespace Tests\Feature\App;

use App\Domain\AcademicStructure\Infrastructure\AcademicYear;
use App\Domain\AcademicStructure\Infrastructure\GradeLevel;
use App\Domain\AcademicStructure\Infrastructure\Section;
use App\Domain\Students\Application\EnrollmentRolloverDryRunService;
use App\Domain\Students\Application\EnrollmentRolloverPlanService;
use App\Domain\Students\Application\StudentEnrollmentService;
use App\Domain\Students\Infrastructure\EnrollmentRolloverItem;
use App\Domain\Students\Infrastructure\EnrollmentRolloverMapping;
use App\Domain\Students\Infrastructure\EnrollmentRolloverPlan;
use App\Domain\Students\Infrastructure\Student;
use App\Domain\Students\Infrastructure\StudentEnrollment;
use App\Models\Campus;
use App\Models\MembershipRoleAssignment;
use App\Models\Role;
use App\Models\School;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 1B.7F: the administrative rollover UI
 * (App\Http\Controllers\App\EnrollmentRolloverController and its
 * Mapping/Item sibling controllers). Backend domain invariants are
 * already proven by the 1B.7A-1B.7E suites (re-run unmodified) --
 * these tests cover the Inertia-specific integration: page rendering,
 * capability-aware props, dual-authorization enforcement via direct
 * requests (never relying on a hidden button), redirect/validation
 * behavior, and that this layer never calls the JSON API over HTTP.
 */
class EnrollmentRolloverUiTest extends TestCase
{
    use CreatesTenancyFixtures;

    private function activate(User $user, School $school): void
    {
        $this->actingAs($user)->post("/app/schools/{$school->id}/activate");
    }

    private function grantCapabilities(User $user, School $school, array $capabilities): void
    {
        $membership = $this->createMembership($user, $school);
        $role = Role::query()->create(['key' => 'test_rollover_ui_'.Str::random(8), 'name' => 'Test', 'scope' => 'school', 'is_system' => false]);
        $role->capabilities()->sync($capabilities);
        app(TenantContext::class)->withSchool($school, fn () => MembershipRoleAssignment::query()->create([
            'school_id' => $school->id, 'school_membership_id' => $membership->id, 'role_id' => $role->id,
        ]));
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
    // Section 69 -- Create Plan
    // ==================================================================

    #[Test]
    public function create_plan_derives_school_and_actor_with_no_academic_mutation(): void
    {
        ['school' => $school, 'sourceYear' => $sourceYear, 'targetYear' => $targetYear] = $this->buildContext();
        [$user] = $this->createSchoolAdmin('school_admin');
        $this->grantCapabilities($user, $school, ['enrollments.manage', 'enrollments.rollovers.manage', 'enrollments.view', 'enrollments.rollovers.view']);
        $this->activate($user, $school);

        $before = app(TenantContext::class)->withSchool($school, fn () => StudentEnrollment::query()->count());

        $response = $this->post('/app/enrollment-rollovers', [
            'source_academic_year_id' => $sourceYear->id,
            'target_academic_year_id' => $targetYear->id,
        ]);

        $plan = app(TenantContext::class)->withSchool($school, fn () => EnrollmentRolloverPlan::query()->where('school_id', $school->id)->firstOrFail());
        $response->assertRedirect("/app/enrollment-rollovers/{$plan->id}");
        $this->assertSame('draft', $plan->status);

        $after = app(TenantContext::class)->withSchool($school, fn () => StudentEnrollment::query()->count());
        $this->assertSame($before, $after);
    }

    #[Test]
    public function create_plan_with_a_foreign_year_shows_a_validation_error(): void
    {
        ['school' => $school] = $this->buildContext();
        $user = $this->createUser();
        $this->grantCapabilities($user, $school, ['enrollments.manage', 'enrollments.rollovers.manage', 'enrollments.view', 'enrollments.rollovers.view']);
        $this->activate($user, $school);

        $response = $this->from('/app/enrollment-rollovers/create')->post('/app/enrollment-rollovers', [
            'source_academic_year_id' => (string) Str::uuid(),
            'target_academic_year_id' => (string) Str::uuid(),
        ]);

        $response->assertRedirect('/app/enrollment-rollovers/create');
        $response->assertSessionHasErrors(['source_academic_year_id']);
    }

    // ==================================================================
    // Section 70 -- Principal read-only
    // ==================================================================

    #[Test]
    public function principal_can_view_but_not_manage(): void
    {
        ['school' => $school, 'sourceYear' => $sourceYear, 'targetYear' => $targetYear, 'sourceGrade' => $sourceGrade, 'targetGrade' => $targetGrade, 'targetSection' => $targetSection] = $this->buildContext();
        $planId = $this->planId($school, $sourceYear, $targetYear);
        [$user] = $this->createSchoolAdmin('principal');
        $membership = $this->createMembership($user, $school);
        $this->assignSchoolRole($membership, 'principal');
        $this->activate($user, $school);

        $this->get('/app/enrollment-rollovers')->assertInertia(fn ($page) => $page->component('App/EnrollmentRollovers/Index')->where('canManage', false));
        $this->get("/app/enrollment-rollovers/{$planId}")->assertInertia(fn ($page) => $page->component('App/EnrollmentRollovers/Show')->where('canManage', false));

        // Direct POST requests -- never relying on a hidden button.
        $this->post('/app/enrollment-rollovers', [
            'source_academic_year_id' => $sourceYear->id, 'target_academic_year_id' => $targetYear->id,
        ])->assertForbidden();
        $this->post("/app/enrollment-rollovers/{$planId}/mappings", [
            'source_grade_level_id' => $sourceGrade->id, 'target_grade_level_id' => $targetGrade->id, 'target_section_id' => $targetSection->id,
        ])->assertForbidden();
        $this->post("/app/enrollment-rollovers/{$planId}/validate")->assertForbidden();
        $this->post("/app/enrollment-rollovers/{$planId}/start")->assertForbidden();
    }

    // ==================================================================
    // Sections 71/72 -- dual authorization matrices
    // ==================================================================

    #[Test]
    public function read_pages_require_both_base_and_rollover_view(): void
    {
        ['school' => $school, 'sourceYear' => $sourceYear, 'targetYear' => $targetYear] = $this->buildContext();
        $planId = $this->planId($school, $sourceYear, $targetYear);

        $bothUser = $this->createUser();
        $this->grantCapabilities($bothUser, $school, ['enrollments.view', 'enrollments.rollovers.view']);
        $this->activate($bothUser, $school);
        $this->get('/app/enrollment-rollovers')->assertOk();
        $this->get("/app/enrollment-rollovers/{$planId}")->assertOk();

        $baseOnlyUser = $this->createUser();
        $this->grantCapabilities($baseOnlyUser, $school, ['enrollments.view']);
        $this->activate($baseOnlyUser, $school);
        $this->get('/app/enrollment-rollovers')->assertForbidden();

        $rolloverOnlyUser = $this->createUser();
        $this->grantCapabilities($rolloverOnlyUser, $school, ['enrollments.rollovers.view']);
        $this->activate($rolloverOnlyUser, $school);
        $this->get('/app/enrollment-rollovers')->assertForbidden();
    }

    #[Test]
    public function mutation_routes_require_both_base_and_rollover_manage(): void
    {
        ['school' => $school, 'sourceYear' => $sourceYear, 'targetYear' => $targetYear] = $this->buildContext();

        $bothUser = $this->createUser();
        $this->grantCapabilities($bothUser, $school, ['enrollments.manage', 'enrollments.rollovers.manage', 'enrollments.view', 'enrollments.rollovers.view']);
        $this->activate($bothUser, $school);
        $this->post('/app/enrollment-rollovers', [
            'source_academic_year_id' => $sourceYear->id, 'target_academic_year_id' => $targetYear->id,
        ])->assertRedirect();

        $baseOnlyUser = $this->createUser();
        $this->grantCapabilities($baseOnlyUser, $school, ['enrollments.manage']);
        $this->activate($baseOnlyUser, $school);
        $this->post('/app/enrollment-rollovers', [
            'source_academic_year_id' => $sourceYear->id, 'target_academic_year_id' => $targetYear->id,
        ])->assertForbidden();

        $rolloverOnlyUser = $this->createUser();
        $this->grantCapabilities($rolloverOnlyUser, $school, ['enrollments.rollovers.manage']);
        $this->activate($rolloverOnlyUser, $school);
        $this->post('/app/enrollment-rollovers', [
            'source_academic_year_id' => $sourceYear->id, 'target_academic_year_id' => $targetYear->id,
        ])->assertForbidden();

        $readPairUser = $this->createUser();
        $this->grantCapabilities($readPairUser, $school, ['enrollments.view', 'enrollments.rollovers.view']);
        $this->activate($readPairUser, $school);
        $this->post('/app/enrollment-rollovers', [
            'source_academic_year_id' => $sourceYear->id, 'target_academic_year_id' => $targetYear->id,
        ])->assertForbidden();
    }

    // ==================================================================
    // Section 73/74 -- Mapping UI, no delete route
    // ==================================================================

    #[Test]
    public function mapping_create_and_update_delegate_to_the_plan_service_via_web(): void
    {
        ['school' => $school, 'sourceYear' => $sourceYear, 'targetYear' => $targetYear, 'sourceGrade' => $sourceGrade, 'targetGrade' => $targetGrade, 'targetSection' => $targetSection, 'campus' => $campus] = $this->buildContext();
        $anotherTargetSection = $this->createSection($targetYear, $campus, $targetGrade, ['name' => '6C', 'code' => '6C']);
        $planId = $this->planId($school, $sourceYear, $targetYear);
        [$user] = $this->createSchoolAdmin('school_admin');
        $this->grantCapabilities($user, $school, ['enrollments.manage', 'enrollments.rollovers.manage', 'enrollments.view', 'enrollments.rollovers.view']);
        $this->activate($user, $school);

        $this->post("/app/enrollment-rollovers/{$planId}/mappings", [
            'source_grade_level_id' => $sourceGrade->id, 'source_section_id' => null,
            'target_grade_level_id' => $targetGrade->id, 'target_section_id' => $targetSection->id,
        ])->assertRedirect("/app/enrollment-rollovers/{$planId}");

        $mapping = app(TenantContext::class)->withSchool($school, fn () => EnrollmentRolloverMapping::query()->where('plan_id', $planId)->firstOrFail());
        $versionAfterCreate = $this->freshPlan($school, $planId)->configuration_version;

        $this->patch("/app/enrollment-rollovers/{$planId}/mappings/{$mapping->id}", [
            'target_grade_level_id' => $targetGrade->id, 'target_section_id' => $anotherTargetSection->id,
        ])->assertRedirect("/app/enrollment-rollovers/{$planId}");

        $versionAfterUpdate = $this->freshPlan($school, $planId)->configuration_version;
        $this->assertGreaterThan($versionAfterCreate, $versionAfterUpdate);
    }

    #[Test]
    public function no_mapping_delete_route_exists(): void
    {
        ['school' => $school, 'sourceYear' => $sourceYear, 'targetYear' => $targetYear] = $this->buildContext();
        $planId = $this->planId($school, $sourceYear, $targetYear);
        [$user] = $this->createSchoolAdmin('school_admin');
        $this->grantCapabilities($user, $school, ['enrollments.manage', 'enrollments.rollovers.manage', 'enrollments.view', 'enrollments.rollovers.view']);
        $this->activate($user, $school);

        $this->delete("/app/enrollment-rollovers/{$planId}/mappings/".Str::uuid())->assertStatus(405);
    }

    // ==================================================================
    // Sections 75/76 -- Item configuration
    // ==================================================================

    #[Test]
    public function item_configuration_preserves_roll_number_text_and_ignores_protected_fields(): void
    {
        ['school' => $school, 'sourceYear' => $sourceYear, 'targetYear' => $targetYear, 'sourceGrade' => $sourceGrade, 'targetGrade' => $targetGrade, 'sourceSection' => $sourceSection, 'targetSection' => $targetSection] = $this->buildContext();
        $planId = $this->planId($school, $sourceYear, $targetYear);
        $plan = $this->freshPlan($school, $planId);
        $this->planService()->upsertMapping($plan, $sourceGrade, null, $targetGrade, $targetSection);
        $student = $this->createStudent($school, ['student_number' => 'S-ITEM-1']);
        $this->enrollmentService()->enroll($student, $sourceSection, '01', '2026-06-01');
        $this->dryRun()->run($this->freshPlan($school, $planId));
        $item = app(TenantContext::class)->withSchool($school, fn () => EnrollmentRolloverItem::query()->where('plan_id', $planId)->where('student_id', $student->id)->firstOrFail());

        [$user] = $this->createSchoolAdmin('school_admin');
        $this->grantCapabilities($user, $school, ['enrollments.manage', 'enrollments.rollovers.manage', 'enrollments.view', 'enrollments.rollovers.view']);
        $this->activate($user, $school);

        $this->patch("/app/enrollment-rollovers/{$planId}/items/{$item->id}", [
            'decision' => 'promote',
            'roll_number_strategy' => 'explicit',
            'target_roll_number' => '007',
            'student_id' => (string) Str::uuid(),
            'source_enrollment_id' => (string) Str::uuid(),
            'target_enrollment_id' => (string) Str::uuid(),
            'execution_status' => 'succeeded',
            'validation_result' => 'ready',
            'configuration_version' => 999,
        ])->assertRedirect("/app/enrollment-rollovers/{$planId}");

        $fresh = app(TenantContext::class)->withSchool($school, fn () => $item->fresh());
        $this->assertSame('007', $fresh->target_roll_number);
        $this->assertSame($student->id, $fresh->student_id);
        $this->assertNull($fresh->target_enrollment_id);
    }

    #[Test]
    public function an_already_executed_item_is_rejected_through_web(): void
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

        [$user] = $this->createSchoolAdmin('school_admin');
        $this->grantCapabilities($user, $school, ['enrollments.manage', 'enrollments.rollovers.manage', 'enrollments.view', 'enrollments.rollovers.view']);
        $this->activate($user, $school);

        $response = $this->from("/app/enrollment-rollovers/{$planId}")->patch("/app/enrollment-rollovers/{$planId}/items/{$item->id}", [
            'decision' => 'exclude',
        ]);
        $response->assertSessionHasErrors(['decision']);

        $freshTarget = app(TenantContext::class)->withSchool($school, fn () => StudentEnrollment::query()->findOrFail($target->id));
        $this->assertSame($targetSection->id, $freshTarget->section_id);
    }

    // ==================================================================
    // Sections 77-79 -- dry-run UI
    // ==================================================================

    #[Test]
    public function running_validation_populates_items_with_zero_academic_mutation(): void
    {
        ['school' => $school, 'sourceYear' => $sourceYear, 'targetYear' => $targetYear, 'sourceGrade' => $sourceGrade, 'targetGrade' => $targetGrade, 'sourceSection' => $sourceSection, 'targetSection' => $targetSection] = $this->buildContext();
        $planId = $this->planId($school, $sourceYear, $targetYear);
        $plan = $this->freshPlan($school, $planId);
        $this->planService()->upsertMapping($plan, $sourceGrade, null, $targetGrade, $targetSection);
        $student = $this->createStudent($school, ['student_number' => 'S-DRY-1']);
        $this->enrollmentService()->enroll($student, $sourceSection, '01', '2026-06-01');

        [$user] = $this->createSchoolAdmin('school_admin');
        $this->grantCapabilities($user, $school, ['enrollments.manage', 'enrollments.rollovers.manage', 'enrollments.view', 'enrollments.rollovers.view']);
        $this->activate($user, $school);

        $before = app(TenantContext::class)->withSchool($school, fn () => StudentEnrollment::query()->count());
        $this->post("/app/enrollment-rollovers/{$planId}/validate")->assertRedirect("/app/enrollment-rollovers/{$planId}");
        $after = app(TenantContext::class)->withSchool($school, fn () => StudentEnrollment::query()->count());
        $this->assertSame($before, $after);

        $this->get("/app/enrollment-rollovers/{$planId}")->assertInertia(fn ($page) => $page
            ->component('App/EnrollmentRollovers/Show')
            ->has('items.data', 1)
        );
    }

    #[Test]
    public function a_blocked_plan_shows_blocked_results_and_cannot_start(): void
    {
        ['school' => $school, 'sourceYear' => $sourceYear, 'targetYear' => $targetYear, 'sourceSection' => $sourceSection] = $this->buildContext();
        // No mapping configured -- terminal grade -> review/blocked.
        $planId = $this->planId($school, $sourceYear, $targetYear);
        $student = $this->createStudent($school, ['student_number' => 'S-BLK-1']);
        $this->enrollmentService()->enroll($student, $sourceSection, '01', '2026-06-01');

        [$user] = $this->createSchoolAdmin('school_admin');
        $this->grantCapabilities($user, $school, ['enrollments.manage', 'enrollments.rollovers.manage', 'enrollments.view', 'enrollments.rollovers.view']);
        $this->activate($user, $school);

        $this->post("/app/enrollment-rollovers/{$planId}/validate");

        $this->get("/app/enrollment-rollovers/{$planId}")->assertInertia(fn ($page) => $page
            ->where('plan.status', 'draft')
            ->where('plan.validationSummary.review', 1)
        );

        $before = app(TenantContext::class)->withSchool($school, fn () => StudentEnrollment::query()->count());
        $this->post("/app/enrollment-rollovers/{$planId}/start")->assertStatus(302);
        $after = app(TenantContext::class)->withSchool($school, fn () => StudentEnrollment::query()->count());
        $this->assertSame($before, $after, 'starting a non-validated plan must create zero Enrollments');
    }

    #[Test]
    public function a_fully_ready_plan_shows_accurate_counts_and_never_labels_already_enrolled_as_new(): void
    {
        ['school' => $school, 'sourceYear' => $sourceYear, 'targetYear' => $targetYear, 'sourceGrade' => $sourceGrade, 'targetGrade' => $targetGrade, 'sourceSection' => $sourceSection, 'targetSection' => $targetSection] = $this->buildContext();
        $planId = $this->planId($school, $sourceYear, $targetYear);
        $plan = $this->freshPlan($school, $planId);
        $this->planService()->upsertMapping($plan, $sourceGrade, null, $targetGrade, $targetSection);

        $readyStudent = $this->createStudent($school, ['student_number' => 'S-READY-1']);
        $this->enrollmentService()->enroll($readyStudent, $sourceSection, '01', '2026-06-01');
        $alreadyEnrolledStudent = $this->createStudent($school, ['student_number' => 'S-ALREADY-1']);
        $this->enrollmentService()->enroll($alreadyEnrolledStudent, $sourceSection, '02', '2026-06-01');
        $this->enrollmentService()->enroll($alreadyEnrolledStudent, $targetSection, '070', '2027-06-01');

        [$user] = $this->createSchoolAdmin('school_admin');
        $this->grantCapabilities($user, $school, ['enrollments.manage', 'enrollments.rollovers.manage', 'enrollments.view', 'enrollments.rollovers.view']);
        $this->activate($user, $school);

        $this->post("/app/enrollment-rollovers/{$planId}/validate");
        $readyItem = app(TenantContext::class)->withSchool($school, fn () => EnrollmentRolloverItem::query()->where('plan_id', $planId)->where('student_id', $readyStudent->id)->firstOrFail());
        $alreadyItem = app(TenantContext::class)->withSchool($school, fn () => EnrollmentRolloverItem::query()->where('plan_id', $planId)->where('student_id', $alreadyEnrolledStudent->id)->firstOrFail());
        $this->patch("/app/enrollment-rollovers/{$planId}/items/{$readyItem->id}", ['decision' => 'promote', 'roll_number_strategy' => 'explicit', 'target_roll_number' => '101']);
        $this->patch("/app/enrollment-rollovers/{$planId}/items/{$alreadyItem->id}", ['decision' => 'promote', 'roll_number_strategy' => 'explicit', 'target_roll_number' => '070']);
        $this->post("/app/enrollment-rollovers/{$planId}/validate");

        $this->get("/app/enrollment-rollovers/{$planId}")->assertInertia(fn ($page) => $page
            ->where('plan.status', 'validated')
            ->where('plan.validationSummary.ready', 1)
            ->where('plan.validationSummary.already_enrolled', 1)
        );
    }

    // ==================================================================
    // Sections 80-84 -- Start/Resume
    // ==================================================================

    #[Test]
    public function starting_a_validated_plan_delegates_to_the_execution_service_with_no_controller_item_loop(): void
    {
        ['school' => $school, 'sourceYear' => $sourceYear, 'targetYear' => $targetYear, 'sourceGrade' => $sourceGrade, 'targetGrade' => $targetGrade, 'sourceSection' => $sourceSection, 'targetSection' => $targetSection] = $this->buildContext();
        $planId = $this->planId($school, $sourceYear, $targetYear);
        $plan = $this->freshPlan($school, $planId);
        $this->planService()->upsertMapping($plan, $sourceGrade, null, $targetGrade, $targetSection);
        $student = $this->createStudent($school, ['student_number' => 'S-START-1']);
        $this->enrollmentService()->enroll($student, $sourceSection, '01', '2026-06-01');

        [$user] = $this->createSchoolAdmin('school_admin');
        $this->grantCapabilities($user, $school, ['enrollments.manage', 'enrollments.rollovers.manage', 'enrollments.view', 'enrollments.rollovers.view']);
        $this->activate($user, $school);

        $this->post("/app/enrollment-rollovers/{$planId}/validate");
        $item = app(TenantContext::class)->withSchool($school, fn () => EnrollmentRolloverItem::query()->where('plan_id', $planId)->where('student_id', $student->id)->firstOrFail());
        $this->patch("/app/enrollment-rollovers/{$planId}/items/{$item->id}", ['decision' => 'promote', 'roll_number_strategy' => 'explicit', 'target_roll_number' => '011']);
        $this->post("/app/enrollment-rollovers/{$planId}/validate");

        $this->post("/app/enrollment-rollovers/{$planId}/start")->assertRedirect("/app/enrollment-rollovers/{$planId}");

        $target = app(TenantContext::class)->withSchool($school, fn () => StudentEnrollment::query()->where('student_id', $student->id)->where('academic_year_id', $targetYear->id)->first());
        $this->assertNotNull($target);
        $this->assertSame($targetSection->id, $target->section_id);
        $this->assertSame('completed', $this->freshPlan($school, $planId)->status);
    }

    #[Test]
    public function duplicate_start_shows_a_safe_conflict_and_never_auto_resumes(): void
    {
        ['school' => $school, 'sourceYear' => $sourceYear, 'targetYear' => $targetYear] = $this->buildContext();
        $planId = $this->planId($school, $sourceYear, $targetYear);
        app(TenantContext::class)->withSchool($school, fn () => EnrollmentRolloverPlan::query()->whereKey($planId)->update(['status' => 'executing']));

        [$user] = $this->createSchoolAdmin('school_admin');
        $this->grantCapabilities($user, $school, ['enrollments.manage', 'enrollments.rollovers.manage', 'enrollments.view', 'enrollments.rollovers.view']);
        $this->activate($user, $school);

        $response = $this->from("/app/enrollment-rollovers/{$planId}")->post("/app/enrollment-rollovers/{$planId}/start");
        $response->assertSessionHasErrors(['execution']);
        $this->assertSame('executing', $this->freshPlan($school, $planId)->status);
    }

    #[Test]
    public function resume_processes_pending_items_and_preserves_prior_targets(): void
    {
        ['school' => $school, 'sourceYear' => $sourceYear, 'targetYear' => $targetYear, 'sourceGrade' => $sourceGrade, 'targetGrade' => $targetGrade, 'sourceSection' => $sourceSection, 'targetSection' => $targetSection] = $this->buildContext();
        $planId = $this->planId($school, $sourceYear, $targetYear);
        $plan = $this->freshPlan($school, $planId);
        $this->planService()->upsertMapping($plan, $sourceGrade, null, $targetGrade, $targetSection);

        $firstStudent = $this->createStudent($school, ['student_number' => 'S-RESUME-1']);
        $this->enrollmentService()->enroll($firstStudent, $sourceSection, '01', '2026-06-01');
        $secondStudent = $this->createStudent($school, ['student_number' => 'S-RESUME-2']);
        $this->enrollmentService()->enroll($secondStudent, $sourceSection, '02', '2026-06-01');

        [$user] = $this->createSchoolAdmin('school_admin');
        $this->grantCapabilities($user, $school, ['enrollments.manage', 'enrollments.rollovers.manage', 'enrollments.view', 'enrollments.rollovers.view']);
        $this->activate($user, $school);

        $this->post("/app/enrollment-rollovers/{$planId}/validate");
        $firstItem = app(TenantContext::class)->withSchool($school, fn () => EnrollmentRolloverItem::query()->where('plan_id', $planId)->where('student_id', $firstStudent->id)->firstOrFail());
        $secondItem = app(TenantContext::class)->withSchool($school, fn () => EnrollmentRolloverItem::query()->where('plan_id', $planId)->where('student_id', $secondStudent->id)->firstOrFail());
        $this->patch("/app/enrollment-rollovers/{$planId}/items/{$firstItem->id}", ['decision' => 'promote', 'roll_number_strategy' => 'explicit', 'target_roll_number' => '101']);
        $this->patch("/app/enrollment-rollovers/{$planId}/items/{$secondItem->id}", ['decision' => 'promote', 'roll_number_strategy' => 'explicit', 'target_roll_number' => '102']);
        $this->post("/app/enrollment-rollovers/{$planId}/validate");

        // Manufacture an "interrupted" state directly at the DB level
        // (equivalent to the first Item having already succeeded via a
        // prior request that hit the server's per-request item cap) --
        // proves the web resume() action correctly continues, without
        // the expense of creating >100 real Items to hit the exact cap.
        app(TenantContext::class)->withSchool($school, fn () => EnrollmentRolloverPlan::query()->whereKey($planId)->update(['status' => 'executing', 'execution_started_at' => now()]));
        $existingTarget = $this->enrollmentService()->enroll($firstStudent, $targetSection, '101', '2027-06-01');
        app(TenantContext::class)->withSchool($school, fn () => EnrollmentRolloverItem::query()->whereKey($firstItem->id)->update([
            'execution_status' => 'succeeded', 'target_enrollment_id' => $existingTarget->id, 'executed_at' => now(),
        ]));

        $this->get("/app/enrollment-rollovers/{$planId}")->assertInertia(fn ($page) => $page
            ->where('plan.status', 'executing')
            ->where('plan.executionSummary.succeeded', 1)
            ->where('plan.executionSummary.pending', 1)
        );

        $this->post("/app/enrollment-rollovers/{$planId}/resume")->assertRedirect("/app/enrollment-rollovers/{$planId}");

        $this->assertSame('completed', $this->freshPlan($school, $planId)->status);
        $firstTargetCount = app(TenantContext::class)->withSchool($school, fn () => StudentEnrollment::query()->where('student_id', $firstStudent->id)->where('academic_year_id', $targetYear->id)->count());
        $this->assertSame(1, $firstTargetCount, 'the already-succeeded first Student must never gain a duplicate target');
        $secondTarget = app(TenantContext::class)->withSchool($school, fn () => StudentEnrollment::query()->where('student_id', $secondStudent->id)->where('academic_year_id', $targetYear->id)->first());
        $this->assertNotNull($secondTarget);
    }

    #[Test]
    public function resume_on_a_non_executing_plan_is_a_safe_rejection(): void
    {
        ['school' => $school, 'sourceYear' => $sourceYear, 'targetYear' => $targetYear] = $this->buildContext();
        $planId = $this->planId($school, $sourceYear, $targetYear);
        [$user] = $this->createSchoolAdmin('school_admin');
        $this->grantCapabilities($user, $school, ['enrollments.manage', 'enrollments.rollovers.manage', 'enrollments.view', 'enrollments.rollovers.view']);
        $this->activate($user, $school);

        $response = $this->from("/app/enrollment-rollovers/{$planId}")->post("/app/enrollment-rollovers/{$planId}/resume");
        $response->assertSessionHasErrors(['execution']);
    }

    // ==================================================================
    // Sections 85/86 -- partial invalidation + revalidation web flow
    // ==================================================================

    #[Test]
    public function partial_invalidation_then_revalidation_and_restart_never_duplicates_targets(): void
    {
        ['school' => $school, 'sourceYear' => $sourceYear, 'targetYear' => $targetYear, 'sourceGrade' => $sourceGrade, 'targetGrade' => $targetGrade, 'sourceSection' => $sourceSection, 'targetSection' => $targetSection] = $this->buildContext();
        $planId = $this->planId($school, $sourceYear, $targetYear);
        $plan = $this->freshPlan($school, $planId);
        $this->planService()->upsertMapping($plan, $sourceGrade, null, $targetGrade, $targetSection);

        $firstStudent = $this->createStudent($school, ['student_number' => 'S-INV-1']);
        $this->enrollmentService()->enroll($firstStudent, $sourceSection, '01', '2026-06-01');
        $secondStudent = $this->createStudent($school, ['student_number' => 'S-INV-2']);
        $this->enrollmentService()->enroll($secondStudent, $sourceSection, '02', '2026-06-01');

        [$user] = $this->createSchoolAdmin('school_admin');
        $this->grantCapabilities($user, $school, ['enrollments.manage', 'enrollments.rollovers.manage', 'enrollments.view', 'enrollments.rollovers.view']);
        $this->activate($user, $school);

        $this->post("/app/enrollment-rollovers/{$planId}/validate");
        $firstItem = app(TenantContext::class)->withSchool($school, fn () => EnrollmentRolloverItem::query()->where('plan_id', $planId)->where('student_id', $firstStudent->id)->firstOrFail());
        $secondItem = app(TenantContext::class)->withSchool($school, fn () => EnrollmentRolloverItem::query()->where('plan_id', $planId)->where('student_id', $secondStudent->id)->firstOrFail());
        $this->patch("/app/enrollment-rollovers/{$planId}/items/{$firstItem->id}", ['decision' => 'promote', 'roll_number_strategy' => 'explicit', 'target_roll_number' => '101']);
        $this->patch("/app/enrollment-rollovers/{$planId}/items/{$secondItem->id}", ['decision' => 'promote', 'roll_number_strategy' => 'explicit', 'target_roll_number' => '102']);
        $this->post("/app/enrollment-rollovers/{$planId}/validate");

        // Manually create a conflicting target for the SECOND student --
        // guarantees that Student's execution invalidates the Plan.
        $this->enrollmentService()->enroll($secondStudent, $targetSection, '999', '2027-06-01');

        $this->post("/app/enrollment-rollovers/{$planId}/start")->assertRedirect("/app/enrollment-rollovers/{$planId}");

        $this->get("/app/enrollment-rollovers/{$planId}")->assertInertia(fn ($page) => $page
            ->where('plan.status', 'draft')
            ->where('plan.executionSummary.succeeded', 1)
        );

        $this->post("/app/enrollment-rollovers/{$planId}/validate");

        $firstTargetCountBefore = app(TenantContext::class)->withSchool($school, fn () => StudentEnrollment::query()->where('student_id', $firstStudent->id)->where('academic_year_id', $targetYear->id)->count());
        $this->post("/app/enrollment-rollovers/{$planId}/start");
        $firstTargetCountAfter = app(TenantContext::class)->withSchool($school, fn () => StudentEnrollment::query()->where('student_id', $firstStudent->id)->where('academic_year_id', $targetYear->id)->count());

        $this->assertSame(1, $firstTargetCountBefore);
        $this->assertSame(1, $firstTargetCountAfter, 'the already-succeeded first Student must never gain a duplicate target after revalidation/restart');
    }

    // ==================================================================
    // Section 87 -- Completed Plan history
    // ==================================================================

    #[Test]
    public function a_completed_plan_is_read_only_with_no_start_or_resume(): void
    {
        ['school' => $school, 'sourceYear' => $sourceYear, 'targetYear' => $targetYear] = $this->buildContext();
        $planId = $this->planId($school, $sourceYear, $targetYear);
        app(TenantContext::class)->withSchool($school, fn () => EnrollmentRolloverPlan::query()->whereKey($planId)->update(['status' => 'completed', 'completed_at' => now()]));

        [$user] = $this->createSchoolAdmin('school_admin');
        $this->grantCapabilities($user, $school, ['enrollments.manage', 'enrollments.rollovers.manage', 'enrollments.view', 'enrollments.rollovers.view']);
        $this->activate($user, $school);

        $this->get("/app/enrollment-rollovers/{$planId}")->assertInertia(fn ($page) => $page->where('plan.status', 'completed'));

        $this->from("/app/enrollment-rollovers/{$planId}")->post("/app/enrollment-rollovers/{$planId}/start")->assertSessionHasErrors(['execution']);
        $this->from("/app/enrollment-rollovers/{$planId}")->post("/app/enrollment-rollovers/{$planId}/resume")->assertSessionHasErrors(['execution']);
    }

    // ==================================================================
    // Sections 88/89 -- tenant isolation / nested ownership
    // ==================================================================

    #[Test]
    public function a_foreign_school_plan_is_not_found(): void
    {
        ['school' => $schoolA] = $this->buildContext();
        ['school' => $schoolB, 'sourceYear' => $bSourceYear, 'targetYear' => $bTargetYear] = $this->buildContext();
        $foreignPlanId = $this->planId($schoolB, $bSourceYear, $bTargetYear);

        $user = $this->createUser();
        $this->grantCapabilities($user, $schoolA, ['enrollments.view', 'enrollments.rollovers.view']);
        $this->activate($user, $schoolA);

        $this->get("/app/enrollment-rollovers/{$foreignPlanId}")->assertNotFound();
        $this->get('/app/enrollment-rollovers/'.Str::uuid())->assertNotFound();
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

        [$user] = $this->createSchoolAdmin('school_admin');
        $this->grantCapabilities($user, $school, ['enrollments.manage', 'enrollments.rollovers.manage', 'enrollments.view', 'enrollments.rollovers.view']);
        $this->activate($user, $school);

        $this->patch("/app/enrollment-rollovers/{$planA}/mappings/{$mappingFromB->id}", [
            'target_grade_level_id' => $targetGrade->id,
        ])->assertNotFound();
    }

    // ==================================================================
    // Section 91 -- privacy props
    // ==================================================================

    #[Test]
    public function props_never_include_guardian_or_dob_fields(): void
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

        [$user] = $this->createSchoolAdmin('school_admin');
        $this->grantCapabilities($user, $school, ['enrollments.view', 'enrollments.rollovers.view']);
        $this->activate($user, $school);

        $this->get("/app/enrollment-rollovers/{$planId}")->assertInertia(fn ($page) => $page
            ->missing('items.data.0.student.dateOfBirth')
            ->missing('items.data.0.student.date_of_birth')
            ->missing('items.data.0.student.guardian')
            ->missing('items.data.0.student.guardian_contacts')
            ->missing('items.data.0.student.encrypted_value')
            ->missing('items.data.0.student.lookup_hash')
            ->missing('items.data.0.student.lookup_key_version')
        );
    }

    // ==================================================================
    // Section 95 -- TenantContext regression
    // ==================================================================

    #[Test]
    public function a_rollover_conflict_through_web_leaves_the_connection_healthy_for_the_next_request(): void
    {
        ['school' => $school, 'sourceYear' => $sourceYear, 'targetYear' => $targetYear] = $this->buildContext();
        $planId = $this->planId($school, $sourceYear, $targetYear);
        [$user] = $this->createSchoolAdmin('school_admin');
        $this->grantCapabilities($user, $school, ['enrollments.manage', 'enrollments.rollovers.manage', 'enrollments.view', 'enrollments.rollovers.view']);
        $this->activate($user, $school);

        $this->post("/app/enrollment-rollovers/{$planId}/start")->assertStatus(302);

        $this->get('/app/enrollment-rollovers')->assertOk();
    }

    // ==================================================================
    // Phase 1B Closure Gate, Section 21 -- >100-Item request-boundary
    // regression (closes the 1B.7F P3: BoundsRolloverExecutionRequest's
    // 100-item-per-request cap was previously proven only at the
    // service layer / by manufacturing an already-interrupted DB state
    // -- never by a real request that actually crosses the boundary
    // through the WEB HTTP layer this trait is reportedly shared with).
    // ==================================================================

    #[Test]
    public function starting_a_plan_with_101_ready_items_processes_at_most_100_in_one_web_request_and_resume_completes_the_rest(): void
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
                $student = Student::factory()->for($school, 'school')->create(['student_number' => "S-BOUND-{$rollNumber}"]);
                // Not createStudentEnrollment()/the StudentEnrollment
                // factory here: its faker default
                // (fake()->unique()->numberBetween(1, 60)) is evaluated
                // eagerly before our explicit `roll_number` override
                // applies, and exhausts its 60-value unique pool well
                // before 101 rows -- an existing factory-only quirk,
                // irrelevant to production, not worth touching for one
                // test. A direct create() with every field this
                // Enrollment's five parents require sidesteps it.
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

        [$user] = $this->createSchoolAdmin('school_admin');
        $this->grantCapabilities($user, $school, ['enrollments.manage', 'enrollments.rollovers.manage', 'enrollments.view', 'enrollments.rollovers.view']);
        $this->activate($user, $school);

        // Populate Items (all `undecided` -> `review`).
        $this->post("/app/enrollment-rollovers/{$planId}/validate");

        // Decide every Item directly through the service (fixture setup,
        // not the HTTP boundary under test here) so each is `promote`
        // with its own distinct, explicit target Roll Number.
        app(TenantContext::class)->withSchool($school, function () use ($plan, $planId, $students) {
            foreach ($students as ['student' => $student, 'rollNumber' => $rollNumber]) {
                $item = EnrollmentRolloverItem::query()->where('plan_id', $planId)->where('student_id', $student->id)->firstOrFail();
                $this->planService()->setItemDecision($this->freshPlan($plan->school, $planId), $item, 'promote', null, 'explicit', $rollNumber);
            }
        });

        // Revalidate now that every Item has a decision -- all 101
        // should classify as `ready`.
        $this->post("/app/enrollment-rollovers/{$planId}/validate");
        $this->assertSame('validated', $this->freshPlan($school, $planId)->status);
        $readyCount = app(TenantContext::class)->withSchool($school, fn () => EnrollmentRolloverItem::query()->where('plan_id', $planId)->where('validation_result', 'ready')->count());
        $this->assertSame($totalItems, $readyCount, 'fixture setup must produce exactly 101 ready Items before the boundary is exercised');

        // ---- The actual boundary: ONE web Start request. ----
        $this->post("/app/enrollment-rollovers/{$planId}/start")->assertRedirect("/app/enrollment-rollovers/{$planId}");

        $planAfterStart = $this->freshPlan($school, $planId);
        $succeededAfterStart = app(TenantContext::class)->withSchool($school, fn () => EnrollmentRolloverItem::query()->where('plan_id', $planId)->where('execution_status', 'succeeded')->count());
        $pendingAfterStart = app(TenantContext::class)->withSchool($school, fn () => EnrollmentRolloverItem::query()->where('plan_id', $planId)->whereNull('execution_status')->count());
        $targetEnrollmentsAfterStart = app(TenantContext::class)->withSchool($school, fn () => StudentEnrollment::query()->where('academic_year_id', $targetYear->id)->count());

        $this->assertSame('executing', $planAfterStart->status, 'the Plan must remain executing, never silently finalize, when work remains');
        $this->assertLessThanOrEqual(100, $succeededAfterStart, 'no single web request may process more than 100 Items');
        $this->assertGreaterThanOrEqual(1, $pendingAfterStart, 'at least one Item must remain pending after a single capped request');
        $this->assertSame($succeededAfterStart, $targetEnrollmentsAfterStart, 'exactly one target Enrollment per succeeded Item, no more');
        $this->assertSame($totalItems, $succeededAfterStart + $pendingAfterStart, 'every Item is accounted for as either succeeded or still pending -- none lost');

        // The Show page must reflect this honestly (no fabricated
        // completion, Resume is the correct next action) -- and no
        // automatic second execution request is ever made by this test
        // or by the page itself.
        $this->get("/app/enrollment-rollovers/{$planId}")->assertInertia(fn ($page) => $page
            ->where('plan.status', 'executing')
            ->where('plan.executionSummary.succeeded', $succeededAfterStart)
            ->where('plan.executionSummary.pending', $pendingAfterStart)
        );

        // ---- ONE web Resume request completes the remainder. ----
        $this->post("/app/enrollment-rollovers/{$planId}/resume")->assertRedirect("/app/enrollment-rollovers/{$planId}");

        $planAfterResume = $this->freshPlan($school, $planId);
        $this->assertSame('completed', $planAfterResume->status, 'no blockers exist, so the Plan must reach completed after Resume finishes the remaining Items');

        $succeededAfterResume = app(TenantContext::class)->withSchool($school, fn () => EnrollmentRolloverItem::query()->where('plan_id', $planId)->where('execution_status', 'succeeded')->count());
        $this->assertSame($totalItems, $succeededAfterResume, 'every Item must have succeeded once Resume drains the remainder');

        $targetEnrollmentsAfterResume = app(TenantContext::class)->withSchool($school, fn () => StudentEnrollment::query()->where('academic_year_id', $targetYear->id)->count());
        $this->assertSame($totalItems, $targetEnrollmentsAfterResume, 'exactly 101 target Enrollments total -- no duplicates created across the Start+Resume pair');

        foreach ($students as ['student' => $student, 'rollNumber' => $rollNumber]) {
            $targetCount = app(TenantContext::class)->withSchool($school, fn () => StudentEnrollment::query()->where('student_id', $student->id)->where('academic_year_id', $targetYear->id)->count());
            $this->assertSame(1, $targetCount, "Student {$rollNumber} must have exactly one target Enrollment, never a duplicate");
        }

        $sourceStillActiveCount = app(TenantContext::class)->withSchool($school, fn () => StudentEnrollment::query()->where('academic_year_id', $sourceYear->id)->where('status', 'active')->count());
        $this->assertSame($totalItems, $sourceStillActiveCount, 'source Enrollments are never auto-completed by rollover execution -- this remains explicitly deferred scope');
    }
}
