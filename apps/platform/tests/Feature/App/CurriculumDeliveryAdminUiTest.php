<?php

namespace Tests\Feature\App;

use App\Domain\CurriculumDelivery\Infrastructure\CurriculumDelivery;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\CurriculumDelivery\Concerns\CreatesCurriculumDeliveryFixtures;
use Tests\TestCase;

/**
 * Phase 0H.3B -- the session-authenticated administrative Curriculum
 * Delivery surface: AcademicYear -> required SubjectOffering -> Section
 * context, the not-started projection, start/complete/reopen/correct,
 * and the capability boundary.
 */
class CurriculumDeliveryAdminUiTest extends TestCase
{
    use CreatesCurriculumDeliveryFixtures;

    private function actor(array $w, ?object $user = null): static
    {
        return $this->actingAs($user ?? $w['actor'])->withHeader('X-School-Id', $w['school']->id);
    }

    private function reload(array $w, string $id): CurriculumDelivery
    {
        return $this->inDeliverySchool($w['school'], fn () => CurriculumDelivery::query()->findOrFail($id));
    }

    private function pageUrl(array $w): string
    {
        return "/app/syllabus-delivery?subject_offering_id={$w['offering']->id}&section_id={$w['section']->id}";
    }

    #[Test]
    public function the_page_resolves_the_academic_year_context(): void
    {
        $w = $this->deliveryWorld();

        $this->actor($w)->get('/app/syllabus-delivery')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('App/CurriculumDelivery/Index')
                // Defaults to the School's ACTIVE AcademicYear.
                ->where('filters.academicYearId', $w['year']->id)
                ->where('filters.subjectOfferingId', '')
                ->where('filters.sectionId', '')
                ->has('offerings', 1)
                ->where('offerings.0.id', $w['offering']->id)
                // Sections only appear once an Offering is chosen.
                ->has('sections', 0)
                ->has('rows', 0)
                ->where('canManage', true)
            );
    }

    #[Test]
    public function only_required_offerings_are_selectable(): void
    {
        $w = $this->deliveryWorld();
        // An elective in the same year -- it must not be offered, since
        // it has no Section-wide cohort.
        $this->createSubjectOffering(
            $w['year'], $w['campus'], $w['grade'], $this->createSubject($w['school'], ['code' => 'ELEC']),
            ['is_required' => false, 'status' => 'active'],
        );

        $this->actor($w)->get('/app/syllabus-delivery')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('offerings', 1)
                ->where('offerings.0.id', $w['offering']->id)
            );
    }

    #[Test]
    public function sections_are_filtered_to_the_offerings_own_context(): void
    {
        $w = $this->deliveryWorld();
        // Same School and year, but a different GradeLevel -- the
        // composite FKs would reject a delivery for it, so the page must
        // never offer it.
        $otherGrade = $this->createGradeLevel($w['school'], ['code' => 'G9', 'sequence' => 9]);
        $this->createSection($w['year'], $w['campus'], $otherGrade, ['code' => 'Z']);
        $sameContext = $this->createSection($w['year'], $w['campus'], $w['grade'], ['code' => 'B']);

        $this->actor($w)->get("/app/syllabus-delivery?subject_offering_id={$w['offering']->id}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('sections', 2)
                ->where('sections.0.id', $w['section']->id)
                ->where('sections.1.id', $sameContext->id)
                // No Section chosen yet, so there is nothing to show.
                ->has('rows', 0)
            );
    }

    #[Test]
    public function units_are_listed_in_catalogue_order_with_a_not_started_projection(): void
    {
        $w = $this->deliveryWorld();
        $b1 = $this->createSyllabusUnitFor($w['offering'], ['code' => 'B1', 'sequence' => 2]);
        $this->createSyllabusUnitFor($w['offering'], ['code' => 'A1', 'sequence' => 1]);
        // An INACTIVE unit is not deliverable and must not appear.
        $this->createSyllabusUnitFor($w['offering'], ['code' => 'OLD', 'sequence' => 3, 'status' => 'inactive']);

        $this->createDelivery($w['offering'], $w['section'], $b1);

        $this->actor($w)->get($this->pageUrl($w))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('rows', 3)
                // sequence, then upper(code): A1(1), U1(1), B1(2).
                ->where('rows.0.code', 'A1')
                ->where('rows.1.code', 'U1')
                ->where('rows.2.code', 'B1')
                // Absence of a delivery row IS "not started" -- nothing
                // is pre-seeded.
                ->where('rows.0.state', 'not_started')
                ->where('rows.0.deliveryId', null)
                ->where('rows.0.startedOn', null)
                ->where('rows.2.state', 'in_progress')
            );

        // ...and that projection wrote nothing.
        $this->assertSame(1, $this->inDeliverySchool(
            $w['school'], fn () => CurriculumDelivery::query()->count(),
        ));
    }

    #[Test]
    public function a_delivery_can_be_started_completed_reopened_and_corrected_through_the_ui(): void
    {
        $w = $this->deliveryWorld();
        $startedOn = $this->today()->subDays(5)->toDateString();

        $this->actor($w)->post('/app/syllabus-delivery', [
            'subject_offering_id' => $w['offering']->id,
            'section_id' => $w['section']->id,
            'syllabus_unit_id' => $w['unit']->id,
            'started_on' => $startedOn,
        ])->assertRedirect();

        $delivery = $this->inDeliverySchool($w['school'], fn () => CurriculumDelivery::query()->firstOrFail());
        $this->assertSame('in_progress', $delivery->status);
        $this->assertSame($startedOn, $delivery->started_on->toDateString());

        $completedOn = $this->today()->subDay()->toDateString();
        $this->actor($w)->post("/app/syllabus-delivery/{$delivery->id}/transition", [
            'expected_status' => 'in_progress', 'new_status' => 'completed', 'completed_on' => $completedOn,
        ])->assertRedirect();

        $this->assertSame('completed', $this->reload($w, $delivery->id)->status);

        $this->actor($w)->post("/app/syllabus-delivery/{$delivery->id}/transition", [
            'expected_status' => 'completed', 'new_status' => 'in_progress',
        ])->assertRedirect();

        $reopened = $this->reload($w, $delivery->id);
        $this->assertSame('in_progress', $reopened->status);
        $this->assertNull($reopened->completed_on);

        $corrected = $this->today()->subDays(8)->toDateString();
        $this->actor($w)->patch("/app/syllabus-delivery/{$delivery->id}", ['started_on' => $corrected])
            ->assertRedirect();

        $this->assertSame($corrected, $this->reload($w, $delivery->id)->started_on->toDateString());
    }

    #[Test]
    public function a_stale_expected_status_is_refused_through_the_ui(): void
    {
        $w = $this->deliveryWorld();
        $delivery = $this->createDelivery($w['offering'], $w['section'], $w['unit'], [
            'status' => 'completed', 'completed_on' => $this->today()->subDay()->toDateString(),
        ]);

        // On the session-authenticated surface a domain refusal is an
        // ordinary form error (the established Attendance convention),
        // not a raw 409 -- the /api surface is where the real status
        // code is asserted (CurriculumDeliveryApiTest).
        $this->actor($w)->from('/app/syllabus-delivery')
            ->post("/app/syllabus-delivery/{$delivery->id}/transition", [
                'expected_status' => 'in_progress', 'new_status' => 'completed',
                'completed_on' => $this->today()->toDateString(),
            ])
            ->assertRedirect('/app/syllabus-delivery')
            ->assertSessionHasErrors('new_status');

        // ...and the record is untouched.
        $this->assertSame('completed', $this->reload($w, $delivery->id)->status);
    }

    #[Test]
    public function a_member_without_the_view_capability_cannot_reach_the_page(): void
    {
        $w = $this->deliveryWorld();
        // Holds the SYLLABUS pair only -- curating the catalogue must
        // not imply the right to see delivery.
        $outsider = $this->createUserWithCapabilities($w['school'], ['syllabus.view', 'syllabus.manage']);

        $this->actor($w, $outsider)->get('/app/syllabus-delivery')->assertForbidden();
    }

    #[Test]
    public function a_view_only_member_sees_the_page_but_cannot_write(): void
    {
        $w = $this->deliveryWorld();
        $viewer = $this->createUserWithCapabilities($w['school'], ['curriculum.delivery.view']);
        $delivery = $this->createDelivery($w['offering'], $w['section'], $w['unit']);

        $this->actor($w, $viewer)->get('/app/syllabus-delivery')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('canManage', false));

        $this->actor($w, $viewer)->post('/app/syllabus-delivery', [
            'subject_offering_id' => $w['offering']->id,
            'section_id' => $w['section']->id,
            'syllabus_unit_id' => $w['unit']->id,
            'started_on' => $this->today()->toDateString(),
        ])->assertForbidden();

        $this->actor($w, $viewer)->patch("/app/syllabus-delivery/{$delivery->id}", [
            'started_on' => $this->today()->toDateString(),
        ])->assertForbidden();

        $this->actor($w, $viewer)->post("/app/syllabus-delivery/{$delivery->id}/transition", [
            'expected_status' => 'in_progress', 'new_status' => 'completed',
            'completed_on' => $this->today()->toDateString(),
        ])->assertForbidden();
    }

    #[Test]
    public function another_schools_delivery_cannot_be_mutated_through_the_ui(): void
    {
        $w = $this->deliveryWorld();
        $other = $this->deliveryWorld();
        $foreign = $this->createDelivery($other['offering'], $other['section'], $other['unit']);

        $this->actor($w)->patch("/app/syllabus-delivery/{$foreign->id}", [
            'started_on' => $this->today()->toDateString(),
        ])->assertNotFound();

        $this->actor($w)->post("/app/syllabus-delivery/{$foreign->id}/transition", [
            'expected_status' => 'in_progress', 'new_status' => 'completed',
            'completed_on' => $this->today()->toDateString(),
        ])->assertNotFound();

        // ...and School B's row is untouched.
        $this->assertSame('in_progress', $this->inDeliverySchool(
            $other['school'], fn () => CurriculumDelivery::query()->findOrFail($foreign->id)->status,
        ));
    }
}
