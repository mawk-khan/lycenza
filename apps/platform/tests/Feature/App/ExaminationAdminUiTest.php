<?php

namespace Tests\Feature\App;

use App\Domain\Examinations\Infrastructure\Examination;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Examinations\Concerns\CreatesExaminationFixtures;
use Tests\TestCase;

/**
 * Phase 0H.4A -- the session-authenticated administrative Examination
 * surface: AcademicYear context, ordered window list, create, edit
 * (including status), and the capability boundary.
 */
class ExaminationAdminUiTest extends TestCase
{
    use CreatesExaminationFixtures;

    private function actor(array $w, ?object $user = null): static
    {
        return $this->actingAs($user ?? $w['actor'])->withHeader('X-School-Id', $w['school']->id);
    }

    private function reload(array $w, string $id): Examination
    {
        return $this->inExaminationSchool($w['school'], fn () => Examination::query()->findOrFail($id));
    }

    #[Test]
    public function the_page_defaults_to_the_active_academic_year(): void
    {
        $w = $this->examinationWorld();

        $this->actor($w)->get('/app/examinations')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('App/Examinations/Index')
                ->where('filters.academicYearId', $w['year']->id)
                ->has('academicYears', 1)
                ->has('examinations', 0)
                ->where('statuses', ['active', 'inactive'])
                ->where('canManage', true)
            );
    }

    #[Test]
    public function the_listing_is_year_specific_and_chronologically_ordered(): void
    {
        $w = $this->examinationWorld();
        $this->createExamination($w['year'], [
            'code' => 'B1', 'starts_on' => $this->today()->addDays(30)->toDateString(),
            'ends_on' => $this->today()->addDays(35)->toDateString(),
        ]);
        $this->createExamination($w['year'], [
            'code' => 'A1', 'starts_on' => $this->today()->addDays(10)->toDateString(),
            'ends_on' => $this->today()->addDays(15)->toDateString(),
        ]);

        // Another year's examination must not appear.
        $otherYear = $this->createAcademicYear($w['school'], [
            'code' => 'AY-NEXT', 'status' => 'draft',
            'starts_on' => $this->today()->addMonths(7)->toDateString(),
            'ends_on' => $this->today()->addMonths(18)->toDateString(),
        ]);
        $this->createExamination($otherYear, [
            'code' => 'Z9',
            'starts_on' => $this->today()->addMonths(8)->toDateString(),
            'ends_on' => $this->today()->addMonths(9)->toDateString(),
        ]);

        $this->actor($w)->get("/app/examinations?academic_year_id={$w['year']->id}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('examinations', 2)
                ->where('examinations.0.code', 'A1')
                ->where('examinations.1.code', 'B1')
            );
    }

    #[Test]
    public function an_examination_can_be_created_and_edited_through_the_ui(): void
    {
        $w = $this->examinationWorld();
        $startsOn = $this->today()->addDays(10)->toDateString();
        $endsOn = $this->today()->addDays(20)->toDateString();

        $this->actor($w)->post('/app/examinations', [
            'academic_year_id' => $w['year']->id,
            'code' => 'mid1',
            'name' => 'Mid-Term Examination',
            'starts_on' => $startsOn,
            'ends_on' => $endsOn,
        ])->assertRedirect();

        $examination = $this->inExaminationSchool($w['school'], fn () => Examination::query()->firstOrFail());
        $this->assertSame('MID1', $examination->code);
        $this->assertSame($startsOn, $examination->starts_on->toDateString());

        $newEnd = $this->today()->addDays(25)->toDateString();
        $this->actor($w)->patch("/app/examinations/{$examination->id}", [
            'name' => 'Renamed', 'ends_on' => $newEnd, 'status' => 'inactive',
        ])->assertRedirect();

        $fresh = $this->reload($w, $examination->id);
        $this->assertSame('Renamed', $fresh->name);
        $this->assertSame($newEnd, $fresh->ends_on->toDateString());
        // `status` is edited through the ordinary update -- there is no
        // activate/deactivate control.
        $this->assertSame('inactive', $fresh->status);
    }

    #[Test]
    public function a_domain_failure_surfaces_as_an_ordinary_form_error(): void
    {
        $w = $this->examinationWorld();

        $this->actor($w)->from('/app/examinations')
            ->post('/app/examinations', [
                'academic_year_id' => $w['year']->id,
                'code' => 'BAD1',
                'name' => 'Outside the year',
                'starts_on' => $w['year']->starts_on->subDay()->toDateString(),
                'ends_on' => $this->today()->toDateString(),
            ])
            ->assertRedirect('/app/examinations')
            ->assertSessionHasErrors('starts_on');

        $this->assertSame(0, $this->inExaminationSchool($w['school'], fn () => Examination::query()->count()));
    }

    #[Test]
    public function a_member_without_the_view_capability_cannot_reach_the_page(): void
    {
        $w = $this->examinationWorld();
        // Holds the parent AcademicYear's own capabilities only -- they
        // must not imply Examination access.
        $outsider = $this->createUserWithCapabilities($w['school'], ['academics.years.view', 'academics.years.manage']);

        $this->actor($w, $outsider)->get('/app/examinations')->assertForbidden();
    }

    #[Test]
    public function a_view_only_member_sees_the_page_but_cannot_write(): void
    {
        $w = $this->examinationWorld();
        $viewer = $this->createUserWithCapabilities($w['school'], ['examinations.definitions.view']);
        $examination = $this->createExamination($w['year']);

        $this->actor($w, $viewer)->get('/app/examinations')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('canManage', false));

        $this->actor($w, $viewer)->post('/app/examinations', [
            'academic_year_id' => $w['year']->id,
            'code' => 'NEW1', 'name' => 'Nope',
            'starts_on' => $this->today()->addDays(10)->toDateString(),
            'ends_on' => $this->today()->addDays(11)->toDateString(),
        ])->assertForbidden();

        $this->actor($w, $viewer)->patch("/app/examinations/{$examination->id}", ['name' => 'x'])
            ->assertForbidden();
    }

    #[Test]
    public function another_schools_examination_cannot_be_mutated_through_the_ui(): void
    {
        $w = $this->examinationWorld();
        $other = $this->examinationWorld();
        $foreign = $this->createExamination($other['year']);

        $this->actor($w)->patch("/app/examinations/{$foreign->id}", ['name' => 'hijacked'])
            ->assertNotFound();

        // ...and School B's row is untouched.
        $this->assertNotSame('hijacked', $this->inExaminationSchool(
            $other['school'], fn () => Examination::query()->findOrFail($foreign->id)->name,
        ));
    }
}
