<?php

namespace Tests\Feature\App;

use App\Domain\LMS\Infrastructure\LearningContent;
use App\Models\SchoolAuditEvent;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\LMS\Concerns\CreatesLearningContentFixtures;
use Tests\TestCase;

/**
 * Phase 0I.2 -- the session-authenticated administrative Learning
 * Content surface: AcademicYear/SubjectOffering context, ordered
 * content list, create, edit, publish/archive, and the capability
 * boundary.
 */
class LearningContentAdminUiTest extends TestCase
{
    use CreatesLearningContentFixtures;

    private function actor(array $w, ?object $user = null): static
    {
        return $this->actingAs($user ?? $w['actor'])->withHeader('X-School-Id', $w['school']->id);
    }

    #[Test]
    public function the_page_resolves_the_academic_year_and_offering_context(): void
    {
        $w = $this->learningContentWorld();

        $this->actor($w)->get('/app/learning-content')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('App/LMS/Index')
                ->where('filters.academicYearId', $w['year']->id)
                ->where('filters.subjectOfferingId', '')
                ->has('offerings', 1)
                ->where('offerings.0.id', $w['offering']->id)
                ->has('content', 0)
                ->where('statuses', ['draft', 'published', 'archived'])
                ->where('canManage', true)
            );
    }

    #[Test]
    public function selecting_an_offering_lists_its_content_in_order(): void
    {
        $w = $this->learningContentWorld();
        $this->createLearningContent($w['offering'], ['title' => 'B', 'sequence' => 2]);
        $this->createLearningContent($w['offering'], ['title' => 'A', 'sequence' => 1]);

        $this->actor($w)->get("/app/learning-content?subject_offering_id={$w['offering']->id}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('content', 2)
                ->where('content.0.title', 'A')
                ->where('content.1.title', 'B')
                ->where('filters.subjectOfferingId', $w['offering']->id)
            );
    }

    #[Test]
    public function content_can_be_created_edited_published_and_archived_through_the_ui(): void
    {
        $w = $this->learningContentWorld();

        $this->actor($w)->post('/app/learning-content', [
            'subject_offering_id' => $w['offering']->id,
            'title' => 'Chapter 1 reading', 'sequence' => 1,
        ])->assertRedirect();

        $content = $this->inSchool($w['school'], fn () => LearningContent::query()->firstOrFail());
        $this->assertSame(LearningContent::STATUS_DRAFT, $content->status);

        $this->actor($w)->patch("/app/learning-content/{$content->id}", [
            'title' => 'Renamed', 'sequence' => 7,
        ])->assertRedirect();

        $fresh = $this->inSchool($w['school'], fn () => $content->fresh());
        $this->assertSame('Renamed', $fresh->title);
        $this->assertSame(7, $fresh->sequence);
        $this->assertSame(LearningContent::STATUS_DRAFT, $fresh->status, 'The ordinary edit route must never change status.');

        $this->actor($w)->post("/app/learning-content/{$content->id}/publish")->assertRedirect();
        $this->assertSame(LearningContent::STATUS_PUBLISHED, $this->inSchool($w['school'], fn () => $content->fresh()->status));

        $this->actor($w)->post("/app/learning-content/{$content->id}/archive")->assertRedirect();
        $this->assertSame(LearningContent::STATUS_ARCHIVED, $this->inSchool($w['school'], fn () => $content->fresh()->status));

        $this->assertSame(1, $this->inSchool($w['school'], fn () => SchoolAuditEvent::query()
            ->where('event_type', 'lms.learning_content.created')->count()));
        $this->assertSame(1, $this->inSchool($w['school'], fn () => SchoolAuditEvent::query()
            ->where('event_type', 'lms.learning_content.published')->count()));
        $this->assertSame(1, $this->inSchool($w['school'], fn () => SchoolAuditEvent::query()
            ->where('event_type', 'lms.learning_content.archived')->count()));
    }

    #[Test]
    public function an_illegal_transition_through_the_ui_fails_without_changing_state(): void
    {
        $w = $this->learningContentWorld();
        $content = $this->createLearningContent($w['offering'], ['status' => LearningContent::STATUS_DRAFT]);

        $response = $this->actor($w)->post("/app/learning-content/{$content->id}/archive");
        $this->assertContains($response->getStatusCode(), [302, 422]);

        $this->assertSame(LearningContent::STATUS_DRAFT, $this->inSchool($w['school'], fn () => $content->fresh()->status));
    }

    #[Test]
    public function a_member_without_lms_content_view_cannot_reach_the_page(): void
    {
        $w = $this->learningContentWorld();
        $outsider = $this->createUserWithCapabilities($w['school'], ['school.settings.view']);

        $this->actor($w, $outsider)->get('/app/learning-content')->assertForbidden();
        $this->actor($w, $outsider)->post('/app/learning-content', [])->assertForbidden();
    }

    #[Test]
    public function a_view_only_member_sees_the_page_but_cannot_write(): void
    {
        $w = $this->learningContentWorld();
        $content = $this->createLearningContent($w['offering']);
        $viewer = $this->createUserWithCapabilities($w['school'], ['lms.content.view']);

        $this->actor($w, $viewer)->get('/app/learning-content')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('canManage', false));

        $this->actor($w, $viewer)->post('/app/learning-content', [
            'subject_offering_id' => $w['offering']->id, 'title' => 'T',
        ])->assertForbidden();
        $this->actor($w, $viewer)->patch("/app/learning-content/{$content->id}", ['title' => 'T'])->assertForbidden();
        $this->actor($w, $viewer)->post("/app/learning-content/{$content->id}/publish")->assertForbidden();
    }

    #[Test]
    public function another_schools_content_cannot_be_edited_through_the_ui(): void
    {
        $w = $this->learningContentWorld();
        $content = $this->createLearningContent($w['offering'], ['title' => 'Original']);
        $other = $this->learningContentWorld();

        $response = $this->actor($other)->patch("/app/learning-content/{$content->id}", ['title' => 'hijacked']);
        $this->assertContains($response->getStatusCode(), [403, 404]);

        $this->assertSame('Original', $this->inSchool($w['school'], fn () => $content->fresh()->title));
    }
}
