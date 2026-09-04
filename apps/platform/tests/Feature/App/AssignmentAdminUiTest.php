<?php

namespace Tests\Feature\App;

use App\Domain\LMS\Infrastructure\Assignment;
use App\Models\SchoolAuditEvent;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\LMS\Concerns\CreatesAssignmentFixtures;
use Tests\TestCase;

/**
 * Phase 0I.3 -- the session-authenticated administrative Assignment
 * surface: AcademicYear/SubjectOffering context, due-date-ordered
 * list, create, edit, publish/close, and the capability boundary.
 */
class AssignmentAdminUiTest extends TestCase
{
    use CreatesAssignmentFixtures;

    private function actor(array $w, ?object $user = null): static
    {
        return $this->actingAs($user ?? $w['actor'])->withHeader('X-School-Id', $w['school']->id);
    }

    #[Test]
    public function the_page_resolves_the_academic_year_and_offering_context(): void
    {
        $w = $this->assignmentWorld();

        $this->actor($w)->get('/app/assignments')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('App/LMS/Assignments/Index')
                ->where('filters.academicYearId', $w['year']->id)
                ->where('filters.subjectOfferingId', '')
                ->has('offerings', 1)
                ->where('offerings.0.id', $w['offering']->id)
                ->has('assignments', 0)
                ->where('statuses', ['draft', 'published', 'closed'])
                ->where('canManage', true)
            );
    }

    #[Test]
    public function selecting_an_offering_lists_its_assignments_in_due_date_order(): void
    {
        $w = $this->assignmentWorld();
        $this->createAssignment($w['offering'], ['title' => 'Later', 'due_on' => '2026-10-01']);
        $this->createAssignment($w['offering'], ['title' => 'Earlier', 'due_on' => '2026-09-01']);

        $this->actor($w)->get("/app/assignments?subject_offering_id={$w['offering']->id}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('assignments', 2)
                ->where('assignments.0.title', 'Earlier')
                ->where('assignments.1.title', 'Later')
                ->where('filters.subjectOfferingId', $w['offering']->id)
            );
    }

    #[Test]
    public function an_assignment_can_be_created_edited_published_and_closed_through_the_ui(): void
    {
        $w = $this->assignmentWorld();

        $this->actor($w)->post('/app/assignments', [
            'subject_offering_id' => $w['offering']->id,
            'title' => 'Essay on rivers', 'due_on' => '2026-09-15',
        ])->assertRedirect();

        $assignment = $this->inSchool($w['school'], fn () => Assignment::query()->firstOrFail());
        $this->assertSame(Assignment::STATUS_DRAFT, $assignment->status);

        $this->actor($w)->patch("/app/assignments/{$assignment->id}", [
            'title' => 'Renamed', 'due_on' => '2026-09-20',
        ])->assertRedirect();

        $fresh = $this->inSchool($w['school'], fn () => $assignment->fresh());
        $this->assertSame('Renamed', $fresh->title);
        $this->assertSame('2026-09-20', $fresh->due_on->toDateString());
        $this->assertSame(Assignment::STATUS_DRAFT, $fresh->status, 'The ordinary edit route must never change status.');

        $this->actor($w)->post("/app/assignments/{$assignment->id}/publish")->assertRedirect();
        $this->assertSame(Assignment::STATUS_PUBLISHED, $this->inSchool($w['school'], fn () => $assignment->fresh()->status));

        $this->actor($w)->post("/app/assignments/{$assignment->id}/close")->assertRedirect();
        $this->assertSame(Assignment::STATUS_CLOSED, $this->inSchool($w['school'], fn () => $assignment->fresh()->status));

        $this->assertSame(1, $this->inSchool($w['school'], fn () => SchoolAuditEvent::query()
            ->where('event_type', 'lms.assignment.created')->count()));
        $this->assertSame(1, $this->inSchool($w['school'], fn () => SchoolAuditEvent::query()
            ->where('event_type', 'lms.assignment.published')->count()));
        $this->assertSame(1, $this->inSchool($w['school'], fn () => SchoolAuditEvent::query()
            ->where('event_type', 'lms.assignment.closed')->count()));
    }

    #[Test]
    public function publishing_without_a_due_date_fails_without_changing_state(): void
    {
        $w = $this->assignmentWorld();
        $assignment = $this->createAssignment($w['offering'], ['status' => Assignment::STATUS_DRAFT, 'due_on' => null]);

        $response = $this->actor($w)->post("/app/assignments/{$assignment->id}/publish");
        $this->assertContains($response->getStatusCode(), [302, 422]);

        $this->assertSame(Assignment::STATUS_DRAFT, $this->inSchool($w['school'], fn () => $assignment->fresh()->status));
    }

    #[Test]
    public function an_illegal_transition_through_the_ui_fails_without_changing_state(): void
    {
        $w = $this->assignmentWorld();
        $assignment = $this->createAssignment($w['offering'], ['status' => Assignment::STATUS_DRAFT]);

        $response = $this->actor($w)->post("/app/assignments/{$assignment->id}/close");
        $this->assertContains($response->getStatusCode(), [302, 422]);

        $this->assertSame(Assignment::STATUS_DRAFT, $this->inSchool($w['school'], fn () => $assignment->fresh()->status));
    }

    #[Test]
    public function a_member_without_lms_assignments_view_cannot_reach_the_page(): void
    {
        $w = $this->assignmentWorld();
        $outsider = $this->createUserWithCapabilities($w['school'], ['school.settings.view']);

        $this->actor($w, $outsider)->get('/app/assignments')->assertForbidden();
        $this->actor($w, $outsider)->post('/app/assignments', [])->assertForbidden();
    }

    #[Test]
    public function a_view_only_member_sees_the_page_but_cannot_write(): void
    {
        $w = $this->assignmentWorld();
        $assignment = $this->createAssignment($w['offering']);
        $viewer = $this->createUserWithCapabilities($w['school'], ['lms.assignments.view']);

        $this->actor($w, $viewer)->get('/app/assignments')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('canManage', false));

        $this->actor($w, $viewer)->post('/app/assignments', [
            'subject_offering_id' => $w['offering']->id, 'title' => 'T',
        ])->assertForbidden();
        $this->actor($w, $viewer)->patch("/app/assignments/{$assignment->id}", ['title' => 'T'])->assertForbidden();
        $this->actor($w, $viewer)->post("/app/assignments/{$assignment->id}/publish")->assertForbidden();
    }

    #[Test]
    public function another_schools_assignment_cannot_be_edited_through_the_ui(): void
    {
        $w = $this->assignmentWorld();
        $assignment = $this->createAssignment($w['offering'], ['title' => 'Original']);
        $other = $this->assignmentWorld();

        $response = $this->actor($other)->patch("/app/assignments/{$assignment->id}", ['title' => 'hijacked']);
        $this->assertContains($response->getStatusCode(), [403, 404]);

        $this->assertSame('Original', $this->inSchool($w['school'], fn () => $assignment->fresh()->title));
    }
}
