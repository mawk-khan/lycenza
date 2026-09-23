<?php

namespace Tests\Feature\LMS;

use App\Domain\LMS\Infrastructure\Assignment;
use App\Models\SchoolAuditEvent;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\LMS\Concerns\CreatesAssignmentFixtures;
use Tests\TestCase;

/**
 * Phase 0I.3 -- the six-operation Assignment API: CRUD-minus-delete,
 * the publish/close action routes, due-date validation, tenant
 * isolation, audit, and capability allow/deny on every operation.
 */
class AssignmentApiTest extends TestCase
{
    use CreatesAssignmentFixtures;

    private function base(array $w): string
    {
        return "/api/v1/schools/{$w['school']->id}";
    }

    private function as(array $w, ?object $actor = null): static
    {
        return $this->actingAs($actor ?? $w['actor'])->withHeader('X-School-Id', $w['school']->id);
    }

    private function assignmentsUrl(array $w): string
    {
        return $this->base($w)."/subject-offerings/{$w['offering']->id}/assignments";
    }

    // --- create / read ----------------------------------------------

    #[Test]
    public function an_assignment_can_be_created_and_read_back(): void
    {
        $w = $this->assignmentWorld();

        $created = $this->as($w)->postJson($this->assignmentsUrl($w), [
            'title' => 'Essay on rivers', 'instructions' => 'Write 500 words.', 'due_on' => '2026-09-15',
        ]);
        $created->assertCreated();

        $data = $created->json('data');
        $this->assertSame('Essay on rivers', $data['title']);
        $this->assertSame('Write 500 words.', $data['instructions']);
        $this->assertSame('2026-09-15', $data['dueOn']);
        $this->assertSame('draft', $data['status']);
        $this->assertSame($w['offering']->id, $data['subjectOfferingId']);

        $show = $this->as($w)->getJson($this->base($w)."/assignments/{$data['id']}");
        $show->assertOk();
        $this->assertSame($data['id'], $show->json('data.id'));

        $this->assertSame(
            ['id', 'subjectOfferingId', 'title', 'instructions', 'dueOn', 'status'],
            array_keys($data),
        );
    }

    #[Test]
    public function creation_defaults_due_on_and_instructions_to_null(): void
    {
        $w = $this->assignmentWorld();

        $response = $this->as($w)->postJson($this->assignmentsUrl($w), ['title' => 'Minimal']);
        $response->assertCreated();

        $this->assertNull($response->json('data.dueOn'));
        $this->assertNull($response->json('data.instructions'));
    }

    #[Test]
    public function a_due_date_outside_the_academic_year_is_rejected_with_422(): void
    {
        $w = $this->assignmentWorld();

        $this->as($w)->postJson($this->assignmentsUrl($w), ['title' => 'X', 'due_on' => '2020-01-01'])
            ->assertStatus(422);

        $this->assertSame(0, $this->inSchool($w['school'], fn () => Assignment::query()->count()));
    }

    #[Test]
    public function the_index_returns_assignments_for_that_offering_only_in_due_date_order(): void
    {
        $w = $this->assignmentWorld();
        $this->createAssignment($w['offering'], ['title' => 'Later', 'due_on' => '2026-10-01']);
        $this->createAssignment($w['offering'], ['title' => 'Earlier', 'due_on' => '2026-09-01']);

        $other = $this->createSubjectOffering(
            $w['year'], $w['campus'], $w['grade'], $this->createSubject($w['school'], ['code' => 'OTH']),
            ['is_required' => true, 'status' => 'active'],
        );
        $this->createAssignment($other, ['title' => 'Elsewhere', 'due_on' => '2026-09-01']);

        $response = $this->as($w)->getJson($this->assignmentsUrl($w));
        $response->assertOk();

        $this->assertSame(['Earlier', 'Later'], array_column($response->json('data'), 'title'));
    }

    // --- update ------------------------------------------------------

    #[Test]
    public function an_assignment_can_be_updated_but_update_never_changes_status(): void
    {
        $w = $this->assignmentWorld();
        $assignment = $this->createAssignment($w['offering'], ['title' => 'Original']);

        $response = $this->as($w)->patchJson($this->base($w)."/assignments/{$assignment->id}", [
            'title' => 'Renamed', 'due_on' => '2026-09-20', 'status' => 'published',
        ]);

        $response->assertOk();
        $this->assertSame('Renamed', $response->json('data.title'));
        $this->assertSame('2026-09-20', $response->json('data.dueOn'));
        $this->assertSame('draft', $response->json('data.status'), 'PATCH must never accept status.');
    }

    #[Test]
    public function a_no_op_patch_returns_the_current_representation(): void
    {
        $w = $this->assignmentWorld();
        $assignment = $this->createAssignment($w['offering'], ['title' => 'Unchanged']);

        $this->as($w)->patchJson($this->base($w)."/assignments/{$assignment->id}", [])
            ->assertOk()
            ->assertJsonPath('data.title', 'Unchanged');
    }

    // --- validation ----------------------------------------------------

    #[Test]
    public function validation_rejects_bad_input(): void
    {
        $w = $this->assignmentWorld();
        $url = $this->assignmentsUrl($w);

        $this->as($w)->postJson($url, ['instructions' => 'No title'])
            ->assertStatus(422)->assertJsonStructure(['error' => ['errors' => ['title']]]);
        $this->as($w)->postJson($url, ['title' => str_repeat('X', 256)])
            ->assertStatus(422)->assertJsonStructure(['error' => ['errors' => ['title']]]);
        $this->as($w)->postJson($url, ['title' => 'T', 'due_on' => 'not-a-date'])
            ->assertStatus(422)->assertJsonStructure(['error' => ['errors' => ['due_on']]]);

        $this->assertSame(0, $this->inSchool($w['school'], fn () => Assignment::query()->count()));
    }

    // --- lifecycle: publish / close -------------------------------------

    #[Test]
    public function an_assignment_can_be_published_and_closed_through_the_dedicated_routes(): void
    {
        $w = $this->assignmentWorld();
        $assignment = $this->createAssignment($w['offering'], ['status' => Assignment::STATUS_DRAFT, 'due_on' => '2026-09-15']);

        $published = $this->as($w)->postJson($this->base($w)."/assignments/{$assignment->id}/publish");
        $published->assertOk();
        $this->assertSame('published', $published->json('data.status'));

        $closed = $this->as($w)->postJson($this->base($w)."/assignments/{$assignment->id}/close");
        $closed->assertOk();
        $this->assertSame('closed', $closed->json('data.status'));

        $republished = $this->as($w)->postJson($this->base($w)."/assignments/{$assignment->id}/publish");
        $republished->assertOk();
        $this->assertSame('published', $republished->json('data.status'));
    }

    #[Test]
    public function publishing_without_a_due_date_is_rejected_with_422(): void
    {
        $w = $this->assignmentWorld();
        $assignment = $this->createAssignment($w['offering'], ['status' => Assignment::STATUS_DRAFT, 'due_on' => null]);

        $this->as($w)->postJson($this->base($w)."/assignments/{$assignment->id}/publish")
            ->assertStatus(422);
    }

    #[Test]
    public function an_illegal_transition_is_rejected_with_422(): void
    {
        $w = $this->assignmentWorld();
        $assignment = $this->createAssignment($w['offering'], ['status' => Assignment::STATUS_DRAFT]);

        $this->as($w)->postJson($this->base($w)."/assignments/{$assignment->id}/close")
            ->assertStatus(422);
    }

    // --- audit --------------------------------------------------------

    #[Test]
    public function create_and_publish_are_audited_with_bounded_metadata(): void
    {
        $w = $this->assignmentWorld();

        $id = $this->as($w)->postJson($this->assignmentsUrl($w), [
            'title' => 'Secret Assignment', 'due_on' => '2026-09-15',
        ])->json('data.id');

        $created = $this->inSchool($w['school'], fn () => SchoolAuditEvent::query()
            ->where('event_type', 'lms.assignment.created')->firstOrFail());
        $this->assertSame($id, $created->metadata['assignmentId']);
        $this->assertStringNotContainsString('Secret Assignment', json_encode($created->metadata));

        $this->as($w)->postJson($this->base($w)."/assignments/{$id}/publish")->assertOk();

        $published = $this->inSchool($w['school'], fn () => SchoolAuditEvent::query()
            ->where('event_type', 'lms.assignment.published')->firstOrFail());
        $this->assertSame($id, $published->metadata['assignmentId']);
        $this->assertSame('published', $published->metadata['newStatus']);
    }

    // --- authorization / isolation ------------------------------------

    #[Test]
    public function every_operation_denies_an_actor_without_the_capability(): void
    {
        $w = $this->assignmentWorld();
        $assignment = $this->createAssignment($w['offering']);
        $outsider = $this->createUserWithCapabilities($w['school'], ['school.settings.view']);

        $this->as($w, $outsider)->getJson($this->assignmentsUrl($w))->assertForbidden();
        $this->as($w, $outsider)->postJson($this->assignmentsUrl($w), ['title' => 'T'])->assertForbidden();
        $this->as($w, $outsider)->getJson($this->base($w)."/assignments/{$assignment->id}")->assertForbidden();
        $this->as($w, $outsider)->patchJson($this->base($w)."/assignments/{$assignment->id}", ['title' => 'T'])->assertForbidden();
        $this->as($w, $outsider)->postJson($this->base($w)."/assignments/{$assignment->id}/publish")->assertForbidden();
        $this->as($w, $outsider)->postJson($this->base($w)."/assignments/{$assignment->id}/close")->assertForbidden();
    }

    #[Test]
    public function a_view_only_actor_can_read_but_not_write(): void
    {
        $w = $this->assignmentWorld();
        $assignment = $this->createAssignment($w['offering']);
        $viewer = $this->createUserWithCapabilities($w['school'], ['lms.assignments.view']);

        $this->as($w, $viewer)->getJson($this->assignmentsUrl($w))->assertOk();
        $this->as($w, $viewer)->getJson($this->base($w)."/assignments/{$assignment->id}")->assertOk();
        $this->as($w, $viewer)->postJson($this->assignmentsUrl($w), ['title' => 'T'])->assertForbidden();
        $this->as($w, $viewer)->patchJson($this->base($w)."/assignments/{$assignment->id}", ['title' => 'T'])->assertForbidden();
        $this->as($w, $viewer)->postJson($this->base($w)."/assignments/{$assignment->id}/publish")->assertForbidden();
    }

    #[Test]
    public function the_seeded_admin_roles_hold_the_lms_assignments_capabilities(): void
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
                ->postJson("/api/v1/schools/{$school->id}/subject-offerings/{$offering->id}/assignments", [
                    'title' => 'Assignment',
                ])->assertCreated();
        }
    }

    #[Test]
    public function another_schools_assignment_and_offering_are_not_reachable(): void
    {
        $w = $this->assignmentWorld();
        $assignment = $this->createAssignment($w['offering']);
        $other = $this->assignmentWorld();

        $this->as($other)->getJson($this->base($other)."/assignments/{$assignment->id}")->assertNotFound();

        $this->as($other)->getJson(
            $this->base($other)."/subject-offerings/{$w['offering']->id}/assignments",
        )->assertNotFound();

        $cross = $this->as($other)->getJson($this->base($w)."/assignments/{$assignment->id}");
        $this->assertContains($cross->getStatusCode(), [403, 404]);
    }

    // --- route surface -------------------------------------------------

    #[Test]
    public function exactly_six_assignment_routes_exist_with_no_delete_route(): void
    {
        $routes = collect(Route::getRoutes())
            ->filter(function ($route) {
                $action = $route->getAction('controller');

                return is_string($action) && str_contains($action, 'LMS\Http\Controllers\AssignmentController');
            })
            ->flatMap(fn ($route) => collect($route->methods())
                ->reject(fn ($m) => $m === 'HEAD')
                ->map(fn ($m) => $m.' /'.$route->uri()))
            ->sort()->values()->all();

        $this->assertCount(6, $routes, 'Exactly six Assignment API routes: '.implode(', ', $routes));

        foreach ($routes as $route) {
            $this->assertStringNotContainsStringIgnoringCase('DELETE', $route);
        }
    }
}
