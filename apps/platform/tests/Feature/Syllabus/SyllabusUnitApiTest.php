<?php

namespace Tests\Feature\Syllabus;

use App\Domain\Syllabus\Infrastructure\SyllabusUnit;
use App\Models\SchoolAuditEvent;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Syllabus\Concerns\CreatesSyllabusFixtures;
use Tests\TestCase;

/**
 * Phase 0H.3A -- the four-operation Syllabus API: CRUD-minus-delete,
 * code normalization and case-insensitive uniqueness, lifecycle
 * through PATCH, tenant isolation, audit, and capability allow/deny on
 * every operation.
 */
class SyllabusUnitApiTest extends TestCase
{
    use CreatesSyllabusFixtures;

    private function base(array $w): string
    {
        return "/api/v1/schools/{$w['school']->id}";
    }

    private function as(array $w, ?object $actor = null): static
    {
        return $this->actingAs($actor ?? $w['actor'])->withHeader('X-School-Id', $w['school']->id);
    }

    private function unitsUrl(array $w): string
    {
        return $this->base($w)."/subject-offerings/{$w['offering']->id}/syllabus-units";
    }

    // --- create / read ----------------------------------------------

    #[Test]
    public function a_unit_can_be_created_and_read_back(): void
    {
        $w = $this->syllabusWorld();

        $created = $this->as($w)->postJson($this->unitsUrl($w), [
            'code' => 'U1', 'title' => 'Quadratic Equations', 'sequence' => 1,
        ]);
        $created->assertCreated();

        $data = $created->json('data');
        $this->assertSame('U1', $data['code']);
        $this->assertSame('Quadratic Equations', $data['title']);
        $this->assertSame(1, $data['sequence']);
        $this->assertSame('active', $data['status']);
        $this->assertSame($w['offering']->id, $data['subjectOfferingId']);

        $show = $this->as($w)->getJson($this->base($w)."/syllabus-units/{$data['id']}");
        $show->assertOk();
        $this->assertSame($data['id'], $show->json('data.id'));

        // The projection carries no Student/Employee/curriculum-prose
        // field beyond the unit's own facts.
        $this->assertSame(
            ['id', 'subjectOfferingId', 'code', 'title', 'sequence', 'status'],
            array_keys($data),
        );
    }

    #[Test]
    public function the_index_returns_units_for_that_offering_only_in_deterministic_order(): void
    {
        $w = $this->syllabusWorld();
        $this->createSyllabusUnit($w['offering'], ['code' => 'B1', 'sequence' => 2]);
        $this->createSyllabusUnit($w['offering'], ['code' => 'A9', 'sequence' => 1]);
        // Same sequence as A9 -> tie breaks on upper(code).
        $this->createSyllabusUnit($w['offering'], ['code' => 'A1', 'sequence' => 1]);

        $other = $this->createSubjectOffering(
            $w['year'], $w['campus'], $w['grade'], $this->createSubject($w['school'], ['code' => 'OTH']),
            ['is_required' => true, 'status' => 'active'],
        );
        $this->createSyllabusUnit($other, ['code' => 'Z9', 'sequence' => 1]);

        $response = $this->as($w)->getJson($this->unitsUrl($w));
        $response->assertOk();

        $this->assertSame(['A1', 'A9', 'B1'], array_column($response->json('data'), 'code'));
    }

    // --- code normalization + uniqueness ----------------------------

    #[Test]
    public function a_code_is_normalized_to_uppercase(): void
    {
        $w = $this->syllabusWorld();

        $response = $this->as($w)->postJson($this->unitsUrl($w), [
            'code' => '  u1  ', 'title' => 'Unit One', 'sequence' => 1,
        ]);

        $response->assertCreated();
        $this->assertSame('U1', $response->json('data.code'));
    }

    #[Test]
    public function a_case_insensitive_duplicate_code_in_the_same_offering_is_rejected(): void
    {
        $w = $this->syllabusWorld();
        $this->as($w)->postJson($this->unitsUrl($w), ['code' => 'U1', 'title' => 'One', 'sequence' => 1])
            ->assertCreated();

        $this->as($w)->postJson($this->unitsUrl($w), ['code' => 'u1', 'title' => 'Duplicate', 'sequence' => 2])
            ->assertStatus(422)
            ->assertJsonPath('error.status', 422)
            ->assertJsonStructure(['error' => ['errors' => ['code']]]);

        $this->assertSame(1, $this->inSchool($w['school'], fn () => SyllabusUnit::query()->count()));
    }

    #[Test]
    public function the_same_code_is_allowed_under_a_different_offering(): void
    {
        $w = $this->syllabusWorld();
        $this->as($w)->postJson($this->unitsUrl($w), ['code' => 'U1', 'title' => 'One', 'sequence' => 1])
            ->assertCreated();

        $other = $this->createSubjectOffering(
            $w['year'], $w['campus'], $w['grade'], $this->createSubject($w['school'], ['code' => 'OTH']),
            ['is_required' => true, 'status' => 'active'],
        );

        $this->as($w)->postJson(
            $this->base($w)."/subject-offerings/{$other->id}/syllabus-units",
            ['code' => 'U1', 'title' => 'One elsewhere', 'sequence' => 1],
        )->assertCreated();

        $this->assertSame(2, $this->inSchool($w['school'], fn () => SyllabusUnit::query()->count()));
    }

    #[Test]
    public function an_inactive_unit_still_reserves_its_code(): void
    {
        // This is why the entity needs no activate/deactivate command:
        // the unique index is unconditional, so reactivation can never
        // conflict.
        $w = $this->syllabusWorld();
        $this->createSyllabusUnit($w['offering'], ['code' => 'U1', 'status' => 'inactive']);

        $this->as($w)->postJson($this->unitsUrl($w), ['code' => 'u1', 'title' => 'Retry', 'sequence' => 2])
            ->assertStatus(422)
            ->assertJsonPath('error.status', 422)
            ->assertJsonStructure(['error' => ['errors' => ['code']]]);
    }

    // --- update / lifecycle -----------------------------------------

    #[Test]
    public function a_unit_can_be_updated_including_its_status(): void
    {
        $w = $this->syllabusWorld();
        $unit = $this->createSyllabusUnit($w['offering'], ['code' => 'U1', 'sequence' => 1]);

        $response = $this->as($w)->patchJson($this->base($w)."/syllabus-units/{$unit->id}", [
            'code' => 'u2', 'title' => 'Renamed', 'sequence' => 5, 'status' => 'inactive',
        ]);

        $response->assertOk();
        $this->assertSame('U2', $response->json('data.code'));
        $this->assertSame('Renamed', $response->json('data.title'));
        $this->assertSame(5, $response->json('data.sequence'));
        $this->assertSame('inactive', $response->json('data.status'));
    }

    #[Test]
    public function an_update_may_keep_its_own_code(): void
    {
        $w = $this->syllabusWorld();
        $unit = $this->createSyllabusUnit($w['offering'], ['code' => 'U1', 'sequence' => 1]);

        $this->as($w)->patchJson($this->base($w)."/syllabus-units/{$unit->id}", [
            'code' => 'U1', 'sequence' => 3,
        ])->assertOk();
    }

    #[Test]
    public function a_no_op_patch_returns_the_current_representation(): void
    {
        $w = $this->syllabusWorld();
        $unit = $this->createSyllabusUnit($w['offering'], ['code' => 'U1', 'sequence' => 1]);

        $this->as($w)->patchJson($this->base($w)."/syllabus-units/{$unit->id}", [])
            ->assertOk()
            ->assertJsonPath('data.code', 'U1');
    }

    // --- validation --------------------------------------------------

    #[Test]
    public function validation_rejects_bad_input(): void
    {
        $w = $this->syllabusWorld();
        $url = $this->unitsUrl($w);

        $this->as($w)->postJson($url, ['title' => 'No code', 'sequence' => 1])
            ->assertStatus(422)->assertJsonStructure(['error' => ['errors' => ['code']]]);
        $this->as($w)->postJson($url, ['code' => 'U1', 'sequence' => 1])
            ->assertStatus(422)->assertJsonStructure(['error' => ['errors' => ['title']]]);
        $this->as($w)->postJson($url, ['code' => 'U1', 'title' => 'T'])
            ->assertStatus(422)->assertJsonStructure(['error' => ['errors' => ['sequence']]]);
        $this->as($w)->postJson($url, ['code' => 'U1', 'title' => 'T', 'sequence' => -1])
            ->assertStatus(422)->assertJsonStructure(['error' => ['errors' => ['sequence']]]);
        $this->as($w)->postJson($url, ['code' => str_repeat('X', 65), 'title' => 'T', 'sequence' => 1])
            ->assertStatus(422)->assertJsonStructure(['error' => ['errors' => ['code']]]);
        $this->as($w)->postJson($url, ['code' => 'U1', 'title' => str_repeat('X', 256), 'sequence' => 1])
            ->assertStatus(422)->assertJsonStructure(['error' => ['errors' => ['title']]]);
        $this->as($w)->postJson($url, ['code' => 'U1', 'title' => 'T', 'sequence' => 1, 'status' => 'archived'])
            ->assertStatus(422)->assertJsonStructure(['error' => ['errors' => ['status']]]);

        $this->assertSame(0, $this->inSchool($w['school'], fn () => SyllabusUnit::query()->count()));
    }

    // --- elective support -------------------------------------------

    #[Test]
    public function an_elective_offering_supports_syllabus_units_too(): void
    {
        $w = $this->syllabusWorld(['is_required' => false]);

        $this->as($w)->postJson($this->unitsUrl($w), ['code' => 'E1', 'title' => 'Elective unit', 'sequence' => 1])
            ->assertCreated();
    }

    // --- audit --------------------------------------------------------

    #[Test]
    public function create_and_update_are_audited_with_bounded_metadata(): void
    {
        $w = $this->syllabusWorld();

        $id = $this->as($w)->postJson($this->unitsUrl($w), [
            'code' => 'U1', 'title' => 'Secret Curriculum Title', 'sequence' => 1,
        ])->json('data.id');

        $created = $this->inSchool($w['school'], fn () => SchoolAuditEvent::query()
            ->where('event_type', 'syllabus.unit.created')->firstOrFail());

        $this->assertSame($id, $created->metadata['unitId']);
        $this->assertSame($w['offering']->id, $created->metadata['subjectOfferingId']);
        $this->assertSame('U1', $created->metadata['code']);
        $this->assertSame(1, $created->metadata['sequence']);
        $this->assertStringNotContainsString('Secret Curriculum Title', json_encode($created->metadata));

        $this->as($w)->patchJson($this->base($w)."/syllabus-units/{$id}", [
            'title' => 'Another Secret Title', 'sequence' => 4, 'status' => 'inactive',
        ])->assertOk();

        $updated = $this->inSchool($w['school'], fn () => SchoolAuditEvent::query()
            ->where('event_type', 'syllabus.unit.updated')->firstOrFail());

        $this->assertSame($id, $updated->metadata['unitId']);
        $this->assertContains('title', $updated->metadata['changedFields']);
        $this->assertSame(1, $updated->metadata['before']['sequence']);
        $this->assertSame(4, $updated->metadata['after']['sequence']);
        $this->assertSame('inactive', $updated->metadata['after']['status']);

        // The title CHANGED, but its value must never be recorded.
        $encoded = json_encode($updated->metadata);
        $this->assertStringNotContainsString('Another Secret Title', $encoded);
        $this->assertArrayNotHasKey('title', $updated->metadata['before']);
        $this->assertArrayNotHasKey('title', $updated->metadata['after']);
    }

    // --- authorization / isolation ------------------------------------

    #[Test]
    public function every_operation_denies_an_actor_without_the_capability(): void
    {
        $w = $this->syllabusWorld();
        $unit = $this->createSyllabusUnit($w['offering'], ['code' => 'U1']);
        $outsider = $this->createUserWithCapabilities($w['school'], ['school.settings.view']);

        $this->as($w, $outsider)->getJson($this->unitsUrl($w))->assertForbidden();
        $this->as($w, $outsider)->postJson($this->unitsUrl($w), ['code' => 'X', 'title' => 'T', 'sequence' => 1])->assertForbidden();
        $this->as($w, $outsider)->getJson($this->base($w)."/syllabus-units/{$unit->id}")->assertForbidden();
        $this->as($w, $outsider)->patchJson($this->base($w)."/syllabus-units/{$unit->id}", ['title' => 'T'])->assertForbidden();
    }

    #[Test]
    public function a_view_only_actor_can_read_but_not_write(): void
    {
        $w = $this->syllabusWorld();
        $unit = $this->createSyllabusUnit($w['offering'], ['code' => 'U1']);
        $viewer = $this->createUserWithCapabilities($w['school'], ['syllabus.view']);

        $this->as($w, $viewer)->getJson($this->unitsUrl($w))->assertOk();
        $this->as($w, $viewer)->getJson($this->base($w)."/syllabus-units/{$unit->id}")->assertOk();
        $this->as($w, $viewer)->postJson($this->unitsUrl($w), ['code' => 'X', 'title' => 'T', 'sequence' => 1])->assertForbidden();
        $this->as($w, $viewer)->patchJson($this->base($w)."/syllabus-units/{$unit->id}", ['title' => 'T'])->assertForbidden();
    }

    #[Test]
    public function the_seeded_admin_roles_hold_the_syllabus_capabilities(): void
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
                ->postJson("/api/v1/schools/{$school->id}/subject-offerings/{$offering->id}/syllabus-units", [
                    'code' => 'U1', 'title' => 'Unit', 'sequence' => 1,
                ])->assertCreated();
        }
    }

    #[Test]
    public function another_schools_unit_and_offering_are_not_reachable(): void
    {
        $w = $this->syllabusWorld();
        $unit = $this->createSyllabusUnit($w['offering'], ['code' => 'U1']);
        $other = $this->syllabusWorld();

        // School B's actor, School B's URL, School A's unit id.
        $this->as($other)->getJson($this->base($other)."/syllabus-units/{$unit->id}")->assertNotFound();

        // School B's actor cannot address School A's Offering either.
        $this->as($other)->getJson(
            $this->base($other)."/subject-offerings/{$w['offering']->id}/syllabus-units",
        )->assertNotFound();

        // And cannot act inside School A's URL at all.
        $cross = $this->as($other)->getJson($this->base($w)."/syllabus-units/{$unit->id}");
        $this->assertContains($cross->getStatusCode(), [403, 404]);
        $this->assertStringNotContainsString('U1', $cross->getContent());
    }

    // --- route surface -------------------------------------------------

    #[Test]
    public function exactly_four_syllabus_routes_exist_with_no_delete_or_lifecycle_route(): void
    {
        $routes = collect(Route::getRoutes())
            ->filter(function ($route) {
                $action = $route->getAction('controller');

                return is_string($action) && str_contains($action, 'Syllabus\Http\Controllers\SyllabusUnitController');
            })
            ->flatMap(fn ($route) => collect($route->methods())
                ->reject(fn ($m) => $m === 'HEAD')
                ->map(fn ($m) => $m.' /'.$route->uri()))
            ->sort()->values()->all();

        $this->assertCount(4, $routes, 'Exactly four Syllabus API routes: '.implode(', ', $routes));

        foreach ($routes as $route) {
            $this->assertStringNotContainsStringIgnoringCase('DELETE', $route);
            $this->assertStringNotContainsStringIgnoringCase('activate', $route);
            $this->assertStringNotContainsStringIgnoringCase('deactivate', $route);
            $this->assertStringNotContainsStringIgnoringCase('reorder', $route);
        }
    }

    #[Test]
    public function the_module_introduces_no_service_lock_or_event(): void
    {
        // Binding architecture guardrails: a thin controller, no
        // Application service, no locking, no domain event.
        $this->assertDirectoryDoesNotExist(base_path('app/Domain/Syllabus/Application'));

        $source = file_get_contents(
            base_path('app/Domain/Syllabus/Http/Controllers/SyllabusUnitController.php'),
        );
        foreach (['lockForUpdate', 'sharedLock', 'TenantLock', 'event(', 'Idempotency'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $source);
        }
    }
}
