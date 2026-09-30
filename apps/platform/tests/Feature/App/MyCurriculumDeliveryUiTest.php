<?php

namespace Tests\Feature\App;

use App\Domain\CurriculumDelivery\Infrastructure\CurriculumDelivery;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTeacherDeliveryFixtures;
use Tests\Concerns\CreatesTeachingAssignmentFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * TCH.3 -- "My Curriculum Delivery", the owned teacher page: only the
 * teacher's own classes, only the coverage within their dates, writes
 * through the same service, and the dashboard link driven by the
 * capability, never the role key.
 */
class MyCurriculumDeliveryUiTest extends TestCase
{
    use CreatesTeacherDeliveryFixtures, CreatesTeachingAssignmentFixtures, CreatesTenancyFixtures;

    private function actor(array $w, User $user): static
    {
        return $this->actingAs($user)->withHeader('X-School-Id', $w['school']->id);
    }

    #[Test]
    public function a_teacher_sees_only_their_classes_and_the_selected_class_units(): void
    {
        $w = $this->teacherWorld();
        [$user, $employee] = $this->teacher($w);
        [, $other] = $this->teacher($w);
        $this->own($w, $employee);
        $this->own($w, $other, section: $w['sectionB']);
        $this->deliveryRow($w, $w['units'][0], '2026-05-01', '2026-05-20');

        $this->actor($w, $user)->get('/app/my-curriculum-delivery')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('App/CurriculumDelivery/Mine')
                ->where('eligible', true)
                ->has('contexts', 1)
                ->where('contexts.0.sectionId', $w['section']->id)
                ->where('selected', null)
                ->has('rows', 0));

        $this->actor($w, $user)->get("/app/my-curriculum-delivery?section_id={$w['section']->id}&subject_offering_id={$w['offering']->id}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('rows', 3)
                ->where('rows.0.state', 'completed')
                ->where('rows.1.state', 'not_started'));

        // Another teacher's class is never selected, and nothing loads.
        $this->actor($w, $user)->get("/app/my-curriculum-delivery?section_id={$w['sectionB']->id}&subject_offering_id={$w['offering']->id}")
            ->assertInertia(fn (AssertableInertia $page) => $page->where('selected', null)->has('rows', 0));
    }

    #[Test]
    public function a_teacher_starts_and_completes_through_the_page_and_errors_are_form_errors(): void
    {
        $w = $this->teacherWorld();
        [$user, $employee] = $this->teacher($w);
        $this->own($w, $employee, '2026-06-01', '2026-08-31');

        $this->actor($w, $user)->post('/app/my-curriculum-delivery', [
            'section_id' => $w['section']->id, 'subject_offering_id' => $w['offering']->id,
            'syllabus_unit_id' => $w['units'][0]->id, 'started_on' => '2026-06-10',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $delivery = app(TenantContext::class)->withSchool($w['school'], fn () => CurriculumDelivery::query()->firstOrFail());

        $this->actor($w, $user)->post("/app/my-curriculum-delivery/{$delivery->id}/transition", [
            'expected_status' => 'in_progress', 'new_status' => 'completed', 'completed_on' => '2026-09-05',
        ])->assertSessionHasErrors('new_status');

        $this->actor($w, $user)->post("/app/my-curriculum-delivery/{$delivery->id}/transition", [
            'expected_status' => 'in_progress', 'new_status' => 'completed', 'completed_on' => '2026-08-20',
        ])->assertSessionHasNoErrors();

        $this->actor($w, $user)->post('/app/my-curriculum-delivery', [
            'section_id' => $w['sectionB']->id, 'subject_offering_id' => $w['offering']->id,
            'syllabus_unit_id' => $w['units'][1]->id, 'started_on' => '2026-06-10',
        ])->assertNotFound();
    }

    #[Test]
    public function a_capability_holder_who_is_not_an_eligible_employee_sees_an_empty_page_and_cannot_write(): void
    {
        $w = $this->teacherWorld();
        [$user] = $this->teacher($w, linked: false);

        $this->actor($w, $user)->get('/app/my-curriculum-delivery')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('eligible', false)->has('contexts', 0)->has('rows', 0));

        $this->actor($w, $user)->post('/app/my-curriculum-delivery', [
            'section_id' => $w['section']->id, 'subject_offering_id' => $w['offering']->id,
            'syllabus_unit_id' => $w['units'][0]->id, 'started_on' => '2026-06-10',
        ])->assertForbidden();
    }

    #[Test]
    public function the_page_and_link_follow_the_capability_not_the_role(): void
    {
        $w = $this->teacherWorld();
        [$teacher] = $this->teacher($w);
        [$noRole] = $this->teacher($w, roleKey: null);

        $this->actor($w, $teacher)->get('/app')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('nav.canUseMyCurriculumDelivery', true)
            ->where('nav.canViewTeachingAssignments', false));
        $this->actor($w, $noRole)->get('/app')->assertInertia(fn (AssertableInertia $page) => $page->where('nav.canUseMyCurriculumDelivery', false));
        $this->actor($w, $noRole)->get('/app/my-curriculum-delivery')->assertForbidden();

        // The Tier 1 page stays administrative.
        $this->actor($w, $teacher)->get('/app/syllabus-delivery')->assertForbidden();
        $this->actor($w, $teacher)->get('/app/teaching-assignments')->assertForbidden();
    }
}
