<?php

namespace Tests\Feature\App;

use App\Domain\Students\Application\EnrollmentRolloverPlanService;
use App\Domain\Students\Application\Exceptions\InvalidRolloverMappingSectionException;
use App\Domain\Students\Infrastructure\EnrollmentRolloverMapping;
use App\Domain\Students\Infrastructure\EnrollmentRolloverPlan;
use App\Models\School;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * UAT-C1 (UAT-YR-04): a rollover mapping's Section must be a Section of
 * that side's Grade in that side's Academic Year. Local UAT saved a Grade 6
 * Section as the default for target Grade 7 without error; every promoted
 * Student was then blocked with a misleading "decision does not match the
 * mapping". Now the mapping itself is refused, on every write path, and the
 * page offers only the selected Grade's Sections.
 */
class EnrollmentRolloverMappingSectionTest extends TestCase
{
    use CreatesTenancyFixtures;

    private const array MANAGE = ['enrollments.view', 'enrollments.manage', 'enrollments.rollovers.view', 'enrollments.rollovers.manage'];

    /** @return array<string, mixed> */
    private function context(): array
    {
        $school = $this->createSchool();
        $campus = $this->createCampus($school);
        $sourceYear = $this->createAcademicYear($school, ['code' => 'SRC', 'starts_on' => '2026-06-01', 'ends_on' => '2027-04-30']);
        $targetYear = $this->createAcademicYear($school, ['code' => 'TGT', 'starts_on' => '2027-06-01', 'ends_on' => '2028-04-30']);
        $grade6 = $this->createGradeLevel($school, ['name' => 'Grade 6', 'code' => 'G6', 'sequence' => 6]);
        $grade7 = $this->createGradeLevel($school, ['name' => 'Grade 7', 'code' => 'G7', 'sequence' => 7]);

        return [
            'school' => $school,
            'grade6' => $grade6,
            'grade7' => $grade7,
            'source6A' => $this->createSection($sourceYear, $campus, $grade6, ['name' => 'A', 'code' => 'A']),
            'source7A' => $this->createSection($sourceYear, $campus, $grade7, ['name' => 'A', 'code' => 'A']),
            'target6A' => $this->createSection($targetYear, $campus, $grade6, ['name' => 'A', 'code' => 'A']),
            'target7A' => $this->createSection($targetYear, $campus, $grade7, ['name' => 'A', 'code' => 'A']),
            'plan' => app(EnrollmentRolloverPlanService::class)->createDraft($school, $sourceYear, $targetYear),
        ];
    }

    private function manager(School $school): User
    {
        $user = $this->createUserWithCapabilities($school, self::MANAGE);
        $this->actingAs($user)->post("/app/schools/{$school->id}/activate");

        return $user;
    }

    private function mappings(School $school, EnrollmentRolloverPlan $plan): int
    {
        return app(TenantContext::class)->withSchool($school, fn () => EnrollmentRolloverMapping::query()->where('plan_id', $plan->id)->count());
    }

    private function store(array $c, string $sourceGrade, ?string $sourceSection, string $targetGrade, ?string $targetSection)
    {
        return $this->post("/app/enrollment-rollovers/{$c['plan']->id}/mappings", [
            'source_grade_level_id' => $sourceGrade, 'source_section_id' => $sourceSection,
            'target_grade_level_id' => $targetGrade, 'target_section_id' => $targetSection,
        ]);
    }

    #[Test]
    public function a_target_section_of_the_target_grade_and_year_is_accepted(): void
    {
        $c = $this->context();
        $this->manager($c['school']);

        $this->store($c, $c['grade6']->id, null, $c['grade7']->id, $c['target7A']->id)->assertSessionHasNoErrors();
        $this->assertSame(1, $this->mappings($c['school'], $c['plan']));
    }

    #[Test]
    public function the_uat_case_a_target_section_of_another_grade_is_refused_at_mapping_time(): void
    {
        $c = $this->context();
        $this->manager($c['school']);

        // Grade 6 -> Grade 7 with the Grade 6 Section of the target year: the UAT mistake.
        $response = $this->store($c, $c['grade6']->id, null, $c['grade7']->id, $c['target6A']->id);

        $this->assertNotSame(500, $response->getStatusCode());
        $response->assertSessionHasErrors(['target_section_id' => "The target Section must be a Section of the target Grade in the rollover plan's target Academic Year."]);
        $this->assertSame(0, $this->mappings($c['school'], $c['plan']));
    }

    #[Test]
    public function a_target_section_of_the_right_grade_but_the_source_year_is_refused(): void
    {
        $c = $this->context();
        $this->manager($c['school']);

        $this->store($c, $c['grade6']->id, null, $c['grade7']->id, $c['source7A']->id)->assertSessionHasErrors('target_section_id');
        $this->assertSame(0, $this->mappings($c['school'], $c['plan']));
    }

    #[Test]
    public function a_source_section_of_another_grade_is_refused(): void
    {
        $c = $this->context();
        $this->manager($c['school']);

        $this->store($c, $c['grade6']->id, $c['source7A']->id, $c['grade7']->id, $c['target7A']->id)->assertSessionHasErrors('source_section_id');
        $this->assertSame(0, $this->mappings($c['school'], $c['plan']));
    }

    #[Test]
    public function another_schools_section_is_refused(): void
    {
        $c = $this->context();
        $other = $this->context();
        $this->manager($c['school']);

        $this->store($c, $c['grade6']->id, null, $c['grade7']->id, $other['target7A']->id)->assertSessionHasErrors('target_section_id');
        $this->assertSame(0, $this->mappings($c['school'], $c['plan']));
    }

    #[Test]
    public function editing_a_mapping_to_a_wrong_grade_section_is_refused_and_leaves_it_unchanged(): void
    {
        $c = $this->context();
        $this->manager($c['school']);
        $mapping = app(EnrollmentRolloverPlanService::class)->upsertMapping($c['plan'], $c['grade6'], null, $c['grade7'], $c['target7A']);

        $this->patch("/app/enrollment-rollovers/{$c['plan']->id}/mappings/{$mapping->id}", [
            'target_grade_level_id' => $c['grade7']->id, 'target_section_id' => $c['target6A']->id,
        ])->assertSessionHasErrors('target_section_id');

        $this->assertSame($c['target7A']->id, app(TenantContext::class)->withSchool($c['school'], fn () => $mapping->fresh()->target_section_id));
    }

    #[Test]
    public function the_service_refuses_a_tampered_wrong_grade_section_directly(): void
    {
        $c = $this->context();

        $this->expectException(InvalidRolloverMappingSectionException::class);
        app(EnrollmentRolloverPlanService::class)->upsertMapping($c['plan'], $c['grade6'], null, $c['grade7'], $c['target6A']);
    }

    #[Test]
    public function the_api_refuses_a_wrong_grade_section_with_a_422(): void
    {
        $c = $this->context();
        $user = $this->createUserWithCapabilities($c['school'], self::MANAGE);

        $this->withHeader('Authorization', 'Bearer '.$user->createToken('t')->plainTextToken)
            ->withHeader('Idempotency-Key', 'test-wrong-grade')
            ->postJson("/api/v1/schools/{$c['school']->id}/enrollment-rollovers/{$c['plan']->id}/mappings", [
                'source_grade_level_id' => $c['grade6']->id, 'source_section_id' => null,
                'target_grade_level_id' => $c['grade7']->id, 'target_section_id' => $c['target6A']->id,
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'INVALID_ROLLOVER_MAPPING_SECTION');

        $this->assertSame(0, $this->mappings($c['school'], $c['plan']));
    }

    #[Test]
    public function the_page_offers_each_years_sections_with_their_grade_for_grade_scoped_choice(): void
    {
        $c = $this->context();
        $this->manager($c['school']);

        $props = $this->get("/app/enrollment-rollovers/{$c['plan']->id}")->assertOk()->viewData('page')['props'];

        $target = collect($props['targetSections'])->keyBy('id');
        $this->assertEqualsCanonicalizing([$c['target6A']->id, $c['target7A']->id], $target->keys()->all());
        $this->assertSame($c['grade7']->id, $target[$c['target7A']->id]['gradeLevelId']);
        $this->assertEqualsCanonicalizing([$c['source6A']->id, $c['source7A']->id], collect($props['sourceSections'])->pluck('id')->all());
    }

    #[Test]
    public function configuring_a_mapping_still_requires_the_rollover_manage_capability(): void
    {
        $c = $this->context();
        $viewer = $this->createUserWithCapabilities($c['school'], ['enrollments.view', 'enrollments.rollovers.view']);
        $this->actingAs($viewer)->post("/app/schools/{$c['school']->id}/activate");

        $this->store($c, $c['grade6']->id, null, $c['grade7']->id, $c['target7A']->id)->assertForbidden();
        $this->assertSame(0, $this->mappings($c['school'], $c['plan']));
    }
}
