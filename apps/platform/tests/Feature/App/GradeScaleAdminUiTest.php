<?php

namespace Tests\Feature\App;

use App\Domain\Examinations\Infrastructure\GradeScale;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Examinations\Concerns\CreatesGradeScaleFixtures;
use Tests\TestCase;

/**
 * Phase 0H.4C -- the session-authenticated administrative GradeScale
 * surface: scale + band listing, creation (with optional bands),
 * draft-only band mutation, name edits, lifecycle transitions, and
 * the capability boundary. School-only -- no Examination context.
 */
class GradeScaleAdminUiTest extends TestCase
{
    use CreatesGradeScaleFixtures;

    private function actor(array $w, ?object $user = null): static
    {
        return $this->actingAs($user ?? $w['actor'])->withHeader('X-School-Id', $w['school']->id);
    }

    private function url(): string
    {
        return '/app/examinations/grade-scales';
    }

    private function reload(array $w, string $id): GradeScale
    {
        return $this->inGradeScaleSchool($w['school'], fn () => GradeScale::query()->findOrFail($id));
    }

    #[Test]
    public function the_page_shows_an_empty_scale_list(): void
    {
        $w = $this->gradeScaleWorld();

        $this->actor($w)->get($this->url())
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('App/Examinations/GradeScales/Index')
                ->has('gradeScales', 0)
                ->where('statuses', ['draft', 'active', 'inactive'])
                ->where('canManage', true)
            );
    }

    #[Test]
    public function a_scale_can_be_created_with_bands_through_the_ui(): void
    {
        $w = $this->gradeScaleWorld();

        $this->actor($w)->post($this->url(), [
            'code' => 'GS1',
            'name' => 'Standard Scale',
            'bands' => [
                ['min_percentage' => '0.00', 'label' => 'F'],
                ['min_percentage' => '50.00', 'label' => 'P'],
            ],
        ])->assertRedirect();

        $scale = $this->inGradeScaleSchool($w['school'], fn () => GradeScale::query()->with('bands')->firstOrFail());
        $this->assertSame('GS1', $scale->code);
        $this->assertCount(2, $scale->bands);
    }

    #[Test]
    public function bands_can_be_added_edited_and_removed_while_draft(): void
    {
        $w = $this->gradeScaleWorld();
        $scale = $this->createGradeScale($w['school']);

        $this->actor($w)->post("{$this->url()}/{$scale->id}/bands", [
            'min_percentage' => '0.00', 'label' => 'F',
        ])->assertRedirect();

        $band = $this->inGradeScaleSchool($w['school'], fn () => $scale->bands()->firstOrFail());

        $this->actor($w)->patch("{$this->url()}/{$scale->id}/bands/{$band->id}", ['label' => 'Fail'])
            ->assertRedirect();
        $this->assertSame('Fail', $this->inGradeScaleSchool($w['school'], fn () => $band->refresh())->label);

        $this->actor($w)->delete("{$this->url()}/{$scale->id}/bands/{$band->id}")
            ->assertRedirect();
        $this->assertSame(0, $this->inGradeScaleSchool($w['school'], fn () => $scale->bands()->count()));
    }

    #[Test]
    public function a_name_edit_is_accepted_at_any_lifecycle_stage(): void
    {
        $w = $this->gradeScaleWorld();
        $scale = $this->createGradeScale($w['school'], ['status' => 'active', 'name' => 'Old']);

        $this->actor($w)->patch("{$this->url()}/{$scale->id}", ['name' => 'New'])
            ->assertRedirect();

        $this->assertSame('New', $this->reload($w, $scale->id)->name);
    }

    #[Test]
    public function the_lifecycle_can_be_driven_end_to_end_through_the_ui(): void
    {
        $w = $this->gradeScaleWorld();
        $scale = $this->createGradeScale($w['school']);
        $this->createGradeBand($scale, ['min_percentage' => '0.00']);

        $this->actor($w)->patch("{$this->url()}/{$scale->id}", ['status' => 'active'])->assertRedirect();
        $this->assertSame('active', $this->reload($w, $scale->id)->status);

        $this->actor($w)->patch("{$this->url()}/{$scale->id}", ['status' => 'inactive'])->assertRedirect();
        $this->assertSame('inactive', $this->reload($w, $scale->id)->status);

        $this->actor($w)->patch("{$this->url()}/{$scale->id}", ['status' => 'active'])->assertRedirect();
        $this->assertSame('active', $this->reload($w, $scale->id)->status);
    }

    #[Test]
    public function an_activation_denial_surfaces_as_an_ordinary_form_error(): void
    {
        $w = $this->gradeScaleWorld();
        $scale = $this->createGradeScale($w['school']);

        $this->actor($w)->from($this->url())
            ->patch("{$this->url()}/{$scale->id}", ['status' => 'active'])
            ->assertRedirect($this->url())
            ->assertSessionHasErrors('status');

        $this->assertSame('draft', $this->reload($w, $scale->id)->status);
    }

    #[Test]
    public function a_member_without_the_view_capability_cannot_reach_the_page(): void
    {
        $w = $this->gradeScaleWorld();
        $outsider = $this->createUserWithCapabilities($w['school'], [
            'examinations.definitions.view', 'examinations.definitions.manage',
        ]);

        $this->actor($w, $outsider)->get($this->url())->assertForbidden();
    }

    #[Test]
    public function a_view_only_member_sees_the_page_but_cannot_write(): void
    {
        $w = $this->gradeScaleWorld();
        $viewer = $this->createUserWithCapabilities($w['school'], ['examinations.grade_scales.view']);
        $scale = $this->createGradeScale($w['school']);

        $this->actor($w, $viewer)->get($this->url())
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('canManage', false));

        $this->actor($w, $viewer)->post($this->url(), ['code' => 'GS2', 'name' => 'X'])->assertForbidden();
        $this->actor($w, $viewer)->patch("{$this->url()}/{$scale->id}", ['name' => 'X'])->assertForbidden();
    }

    #[Test]
    public function another_schools_scale_cannot_be_mutated_through_the_ui(): void
    {
        $w = $this->gradeScaleWorld();
        $other = $this->gradeScaleWorld();
        $foreign = $this->createGradeScale($other['school']);

        $this->actor($w)->patch("{$this->url()}/{$foreign->id}", ['name' => 'Hijacked'])
            ->assertNotFound();

        $this->assertNotSame('Hijacked', $this->inGradeScaleSchool(
            $other['school'], fn () => GradeScale::query()->findOrFail($foreign->id)->name,
        ));
    }

    #[Test]
    public function no_student_or_marks_data_is_present_on_the_page(): void
    {
        $w = $this->gradeScaleWorld();
        $scale = $this->createGradeScale($w['school']);
        $this->createGradeBand($scale, ['min_percentage' => '0.00']);

        $this->actor($w)->get($this->url())
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('gradeScales.0', fn (AssertableInertia $s) => $s
                    ->hasAll(['id', 'code', 'name', 'status', 'bands'])
                    ->missing('studentId')
                    ->missing('marks')
                    ->missing('schoolId')
                )
            );
    }
}
