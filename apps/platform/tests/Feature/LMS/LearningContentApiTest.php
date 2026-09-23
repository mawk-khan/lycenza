<?php

namespace Tests\Feature\LMS;

use App\Domain\LMS\Infrastructure\LearningContent;
use App\Models\SchoolAuditEvent;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\LMS\Concerns\CreatesLearningContentFixtures;
use Tests\TestCase;

/**
 * Phase 0I.2 -- the six-operation Learning Content API: CRUD-minus-
 * delete, the publish/archive action routes, tenant isolation, audit,
 * and capability allow/deny on every operation.
 */
class LearningContentApiTest extends TestCase
{
    use CreatesLearningContentFixtures;

    private function base(array $w): string
    {
        return "/api/v1/schools/{$w['school']->id}";
    }

    private function as(array $w, ?object $actor = null): static
    {
        return $this->actingAs($actor ?? $w['actor'])->withHeader('X-School-Id', $w['school']->id);
    }

    private function contentUrl(array $w): string
    {
        return $this->base($w)."/subject-offerings/{$w['offering']->id}/learning-content";
    }

    // --- create / read ----------------------------------------------

    #[Test]
    public function content_can_be_created_and_read_back(): void
    {
        $w = $this->learningContentWorld();

        $created = $this->as($w)->postJson($this->contentUrl($w), [
            'title' => 'Chapter 1 reading', 'description' => 'A short introduction.', 'sequence' => 1,
        ]);
        $created->assertCreated();

        $data = $created->json('data');
        $this->assertSame('Chapter 1 reading', $data['title']);
        $this->assertSame('A short introduction.', $data['description']);
        $this->assertSame(1, $data['sequence']);
        $this->assertSame('draft', $data['status']);
        $this->assertSame($w['offering']->id, $data['subjectOfferingId']);

        $show = $this->as($w)->getJson($this->base($w)."/learning-content/{$data['id']}");
        $show->assertOk();
        $this->assertSame($data['id'], $show->json('data.id'));

        $this->assertSame(
            ['id', 'subjectOfferingId', 'title', 'description', 'sequence', 'status'],
            array_keys($data),
        );
    }

    #[Test]
    public function creation_defaults_sequence_to_zero_and_description_to_null(): void
    {
        $w = $this->learningContentWorld();

        $response = $this->as($w)->postJson($this->contentUrl($w), ['title' => 'Minimal']);
        $response->assertCreated();

        $this->assertSame(0, $response->json('data.sequence'));
        $this->assertNull($response->json('data.description'));
    }

    #[Test]
    public function the_index_returns_content_for_that_offering_only_in_deterministic_order(): void
    {
        $w = $this->learningContentWorld();
        $this->createLearningContent($w['offering'], ['title' => 'B', 'sequence' => 2]);
        $this->createLearningContent($w['offering'], ['title' => 'A', 'sequence' => 1]);

        $other = $this->createSubjectOffering(
            $w['year'], $w['campus'], $w['grade'], $this->createSubject($w['school'], ['code' => 'OTH']),
            ['is_required' => true, 'status' => 'active'],
        );
        $this->createLearningContent($other, ['title' => 'Z', 'sequence' => 1]);

        $response = $this->as($w)->getJson($this->contentUrl($w));
        $response->assertOk();

        $this->assertSame(['A', 'B'], array_column($response->json('data'), 'title'));
    }

    // --- update ------------------------------------------------------

    #[Test]
    public function content_can_be_updated_but_update_never_changes_status(): void
    {
        $w = $this->learningContentWorld();
        $content = $this->createLearningContent($w['offering'], ['title' => 'Original']);

        $response = $this->as($w)->patchJson($this->base($w)."/learning-content/{$content->id}", [
            'title' => 'Renamed', 'sequence' => 5, 'status' => 'published',
        ]);

        $response->assertOk();
        $this->assertSame('Renamed', $response->json('data.title'));
        $this->assertSame(5, $response->json('data.sequence'));
        $this->assertSame('draft', $response->json('data.status'), 'PATCH must never accept status.');
    }

    #[Test]
    public function a_no_op_patch_returns_the_current_representation(): void
    {
        $w = $this->learningContentWorld();
        $content = $this->createLearningContent($w['offering'], ['title' => 'Unchanged']);

        $this->as($w)->patchJson($this->base($w)."/learning-content/{$content->id}", [])
            ->assertOk()
            ->assertJsonPath('data.title', 'Unchanged');
    }

    // --- validation ----------------------------------------------------

    #[Test]
    public function validation_rejects_bad_input(): void
    {
        $w = $this->learningContentWorld();
        $url = $this->contentUrl($w);

        $this->as($w)->postJson($url, ['description' => 'No title'])
            ->assertStatus(422)->assertJsonStructure(['error' => ['errors' => ['title']]]);
        $this->as($w)->postJson($url, ['title' => str_repeat('X', 256)])
            ->assertStatus(422)->assertJsonStructure(['error' => ['errors' => ['title']]]);
        $this->as($w)->postJson($url, ['title' => 'T', 'sequence' => -1])
            ->assertStatus(422)->assertJsonStructure(['error' => ['errors' => ['sequence']]]);

        $this->assertSame(0, $this->inSchool($w['school'], fn () => LearningContent::query()->count()));
    }

    // --- lifecycle: publish / archive -------------------------------------

    #[Test]
    public function content_can_be_published_and_archived_through_the_dedicated_routes(): void
    {
        $w = $this->learningContentWorld();
        $content = $this->createLearningContent($w['offering'], ['status' => LearningContent::STATUS_DRAFT]);

        $published = $this->as($w)->postJson($this->base($w)."/learning-content/{$content->id}/publish");
        $published->assertOk();
        $this->assertSame('published', $published->json('data.status'));

        $archived = $this->as($w)->postJson($this->base($w)."/learning-content/{$content->id}/archive");
        $archived->assertOk();
        $this->assertSame('archived', $archived->json('data.status'));

        $republished = $this->as($w)->postJson($this->base($w)."/learning-content/{$content->id}/publish");
        $republished->assertOk();
        $this->assertSame('published', $republished->json('data.status'));
    }

    #[Test]
    public function an_illegal_transition_is_rejected_with_422(): void
    {
        $w = $this->learningContentWorld();
        $content = $this->createLearningContent($w['offering'], ['status' => LearningContent::STATUS_DRAFT]);

        $this->as($w)->postJson($this->base($w)."/learning-content/{$content->id}/archive")
            ->assertStatus(422);
    }

    // --- audit --------------------------------------------------------

    #[Test]
    public function create_and_publish_are_audited_with_bounded_metadata(): void
    {
        $w = $this->learningContentWorld();

        $id = $this->as($w)->postJson($this->contentUrl($w), [
            'title' => 'Secret Instructional Content', 'sequence' => 1,
        ])->json('data.id');

        $created = $this->inSchool($w['school'], fn () => SchoolAuditEvent::query()
            ->where('event_type', 'lms.learning_content.created')->firstOrFail());
        $this->assertSame($id, $created->metadata['learningContentId']);
        $this->assertStringNotContainsString('Secret Instructional Content', json_encode($created->metadata));

        $this->as($w)->postJson($this->base($w)."/learning-content/{$id}/publish")->assertOk();

        $published = $this->inSchool($w['school'], fn () => SchoolAuditEvent::query()
            ->where('event_type', 'lms.learning_content.published')->firstOrFail());
        $this->assertSame($id, $published->metadata['learningContentId']);
        $this->assertSame('published', $published->metadata['newStatus']);
    }

    // --- authorization / isolation ------------------------------------

    #[Test]
    public function every_operation_denies_an_actor_without_the_capability(): void
    {
        $w = $this->learningContentWorld();
        $content = $this->createLearningContent($w['offering']);
        $outsider = $this->createUserWithCapabilities($w['school'], ['school.settings.view']);

        $this->as($w, $outsider)->getJson($this->contentUrl($w))->assertForbidden();
        $this->as($w, $outsider)->postJson($this->contentUrl($w), ['title' => 'T'])->assertForbidden();
        $this->as($w, $outsider)->getJson($this->base($w)."/learning-content/{$content->id}")->assertForbidden();
        $this->as($w, $outsider)->patchJson($this->base($w)."/learning-content/{$content->id}", ['title' => 'T'])->assertForbidden();
        $this->as($w, $outsider)->postJson($this->base($w)."/learning-content/{$content->id}/publish")->assertForbidden();
        $this->as($w, $outsider)->postJson($this->base($w)."/learning-content/{$content->id}/archive")->assertForbidden();
    }

    #[Test]
    public function a_view_only_actor_can_read_but_not_write(): void
    {
        $w = $this->learningContentWorld();
        $content = $this->createLearningContent($w['offering']);
        $viewer = $this->createUserWithCapabilities($w['school'], ['lms.content.view']);

        $this->as($w, $viewer)->getJson($this->contentUrl($w))->assertOk();
        $this->as($w, $viewer)->getJson($this->base($w)."/learning-content/{$content->id}")->assertOk();
        $this->as($w, $viewer)->postJson($this->contentUrl($w), ['title' => 'T'])->assertForbidden();
        $this->as($w, $viewer)->patchJson($this->base($w)."/learning-content/{$content->id}", ['title' => 'T'])->assertForbidden();
        $this->as($w, $viewer)->postJson($this->base($w)."/learning-content/{$content->id}/publish")->assertForbidden();
    }

    #[Test]
    public function the_seeded_admin_roles_hold_the_lms_content_capabilities(): void
    {
        foreach (['school_admin', 'principal'] as $roleKey) {
            [$user, $school] = $this->createSchoolAdmin($roleKey);
            $campus = $this->createCampus($school);
            $year = $this->createAcademicYear($school, ['status' => 'active']);
            $grade = $this->createGradeLevel($school);
            $offering = $this->createSubjectOffering($year, $campus, $grade, $this->createSubject($school), [
                'is_required' => true, 'status' => 'active',
            ]);

            $this->actingAs($user)->withHeader('X-School-Id', $school->id)
                ->postJson("/api/v1/schools/{$school->id}/subject-offerings/{$offering->id}/learning-content", [
                    'title' => 'Resource',
                ])->assertCreated();
        }
    }

    #[Test]
    public function another_schools_content_and_offering_are_not_reachable(): void
    {
        $w = $this->learningContentWorld();
        $content = $this->createLearningContent($w['offering']);
        $other = $this->learningContentWorld();

        $this->as($other)->getJson($this->base($other)."/learning-content/{$content->id}")->assertNotFound();

        $this->as($other)->getJson(
            $this->base($other)."/subject-offerings/{$w['offering']->id}/learning-content",
        )->assertNotFound();

        $cross = $this->as($other)->getJson($this->base($w)."/learning-content/{$content->id}");
        $this->assertContains($cross->getStatusCode(), [403, 404]);
    }

    // --- route surface -------------------------------------------------

    #[Test]
    public function exactly_six_learning_content_routes_exist_with_no_delete_route(): void
    {
        $routes = collect(Route::getRoutes())
            ->filter(function ($route) {
                $action = $route->getAction('controller');

                return is_string($action) && str_contains($action, 'LMS\Http\Controllers\LearningContentController');
            })
            ->flatMap(fn ($route) => collect($route->methods())
                ->reject(fn ($m) => $m === 'HEAD')
                ->map(fn ($m) => $m.' /'.$route->uri()))
            ->sort()->values()->all();

        $this->assertCount(6, $routes, 'Exactly six Learning Content API routes: '.implode(', ', $routes));

        foreach ($routes as $route) {
            $this->assertStringNotContainsStringIgnoringCase('DELETE', $route);
        }
    }
}
