<?php

namespace Tests\Feature\Examinations;

use App\Models\SchoolAuditEvent;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Examinations\Concerns\CreatesGradeScaleFixtures;
use Tests\TestCase;

/**
 * Phase 0H.4C -- the seven-operation GradeScale API: create/read/
 * update the scale, create/update/delete a band, lifecycle through
 * PATCH `status`, tenant isolation, audit, and capability allow/deny.
 */
class GradeScaleApiTest extends TestCase
{
    use CreatesGradeScaleFixtures;

    private function base(array $w): string
    {
        return "/api/v1/schools/{$w['school']->id}";
    }

    private function as(array $w, ?object $actor = null): static
    {
        return $this->actingAs($actor ?? $w['actor'])->withHeader('X-School-Id', $w['school']->id);
    }

    private function collectionUrl(array $w): string
    {
        return $this->base($w).'/grade-scales';
    }

    // --- create / read ---------------------------------------------------

    #[Test]
    public function a_scale_can_be_created_with_bands_and_read_back(): void
    {
        $w = $this->gradeScaleWorld();

        $created = $this->as($w)->postJson($this->collectionUrl($w), [
            'code' => 'GS1',
            'name' => 'Standard Scale',
            'bands' => [
                ['min_percentage' => '0.00', 'label' => 'F'],
                ['min_percentage' => '50.00', 'label' => 'P'],
            ],
        ]);
        $created->assertCreated();

        $data = $created->json('data');
        $this->assertSame('GS1', $data['code']);
        $this->assertSame('draft', $data['status']);
        $this->assertCount(2, $data['bands']);
        $this->assertSame(['id', 'code', 'name', 'status', 'bands'], array_keys($data));
        $this->assertSame(['id', 'minPercentage', 'label'], array_keys($data['bands'][0]));

        $show = $this->as($w)->getJson($this->base($w)."/grade-scales/{$data['id']}");
        $show->assertOk();
        $this->assertSame($data['id'], $show->json('data.id'));
    }

    #[Test]
    public function a_case_variant_duplicate_code_is_rejected(): void
    {
        $w = $this->gradeScaleWorld();
        $this->as($w)->postJson($this->collectionUrl($w), ['code' => 'GS1', 'name' => 'First'])->assertCreated();

        // Caught by the controller's Rule::unique() (normalized to
        // uppercase before validation, exactly like every other coded
        // entity -- CLAUDE.md rule 74) as an ordinary validation error,
        // never reaching the service's own DuplicateGradeScaleCodeException
        // translation -- that path is exercised directly in
        // GradeScaleServiceTest, where it protects the genuine
        // concurrent-create race the validation layer cannot close.
        $this->as($w)->postJson($this->collectionUrl($w), ['code' => 'gs1', 'name' => 'Second'])
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['errors' => ['code']]]);
    }

    #[Test]
    public function the_index_lists_scales_with_their_bands(): void
    {
        $w = $this->gradeScaleWorld();
        $scale = $this->createGradeScale($w['school'], ['code' => 'GS1']);
        $this->createGradeBand($scale, ['min_percentage' => '0.00']);

        $response = $this->as($w)->getJson($this->collectionUrl($w));
        $response->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertCount(1, $response->json('data.0.bands'));
    }

    // --- validation --------------------------------------------------------

    #[Test]
    public function validation_rejects_missing_and_malformed_input(): void
    {
        $w = $this->gradeScaleWorld();

        $this->as($w)->postJson($this->collectionUrl($w), [])
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['errors' => ['code', 'name']]]);

        $this->as($w)->postJson($this->collectionUrl($w), [
            'code' => 'GS1', 'name' => 'X', 'bands' => [['min_percentage' => '150.00', 'label' => 'Y']],
        ])->assertStatus(422)->assertJsonStructure(['error' => ['errors' => ['bands.0.min_percentage']]]);
    }

    // --- lifecycle -----------------------------------------------------------

    #[Test]
    public function a_complete_scale_can_be_activated_then_deactivated_then_reactivated(): void
    {
        $w = $this->gradeScaleWorld();
        $id = $this->as($w)->postJson($this->collectionUrl($w), [
            'code' => 'GS1', 'name' => 'S', 'bands' => [['min_percentage' => '0.00', 'label' => 'F']],
        ])->json('data.id');

        $this->as($w)->patchJson($this->base($w)."/grade-scales/{$id}", ['status' => 'active'])
            ->assertOk()->assertJsonPath('data.status', 'active');

        $this->as($w)->patchJson($this->base($w)."/grade-scales/{$id}", ['status' => 'inactive'])
            ->assertOk()->assertJsonPath('data.status', 'inactive');

        $this->as($w)->patchJson($this->base($w)."/grade-scales/{$id}", ['status' => 'active'])
            ->assertOk()->assertJsonPath('data.status', 'active');
    }

    #[Test]
    public function activation_without_a_floor_band_is_rejected(): void
    {
        $w = $this->gradeScaleWorld();
        $id = $this->as($w)->postJson($this->collectionUrl($w), ['code' => 'GS1', 'name' => 'S'])->json('data.id');

        $this->as($w)->patchJson($this->base($w)."/grade-scales/{$id}", ['status' => 'active'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'GRADE_SCALE_INCOMPLETE');
    }

    #[Test]
    public function a_no_op_transition_is_rejected(): void
    {
        $w = $this->gradeScaleWorld();
        $id = $this->as($w)->postJson($this->collectionUrl($w), ['code' => 'GS1', 'name' => 'S'])->json('data.id');

        $this->as($w)->patchJson($this->base($w)."/grade-scales/{$id}", ['status' => 'draft'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'GRADE_SCALE_ILLEGAL_TRANSITION');
    }

    #[Test]
    public function code_cannot_be_changed_via_update(): void
    {
        $w = $this->gradeScaleWorld();
        $id = $this->as($w)->postJson($this->collectionUrl($w), ['code' => 'GS1', 'name' => 'S'])->json('data.id');

        $response = $this->as($w)->patchJson($this->base($w)."/grade-scales/{$id}", ['code' => 'GS2', 'name' => 'Renamed']);
        $response->assertOk();

        $this->assertSame('GS1', $response->json('data.code'));
        $this->assertSame('Renamed', $response->json('data.name'));
    }

    // --- bands --------------------------------------------------------------

    #[Test]
    public function bands_can_be_added_updated_and_removed_while_draft(): void
    {
        $w = $this->gradeScaleWorld();
        $scaleId = $this->as($w)->postJson($this->collectionUrl($w), ['code' => 'GS1', 'name' => 'S'])->json('data.id');

        $band = $this->as($w)->postJson($this->base($w)."/grade-scales/{$scaleId}/bands", [
            'min_percentage' => '0.00', 'label' => 'F',
        ])->assertCreated()->json('data');

        $this->as($w)->patchJson($this->base($w)."/grade-scales/{$scaleId}/bands/{$band['id']}", ['label' => 'Fail'])
            ->assertOk()->assertJsonPath('data.label', 'Fail');

        $this->as($w)->deleteJson($this->base($w)."/grade-scales/{$scaleId}/bands/{$band['id']}")
            ->assertStatus(204);
    }

    #[Test]
    public function band_mutation_is_rejected_once_the_scale_is_no_longer_draft(): void
    {
        $w = $this->gradeScaleWorld();
        $scale = $this->createGradeScale($w['school'], ['status' => 'active']);
        $band = $this->createGradeBand($scale, ['min_percentage' => '0.00']);

        $this->as($w)->postJson($this->base($w)."/grade-scales/{$scale->id}/bands", [
            'min_percentage' => '10.00', 'label' => 'X',
        ])->assertStatus(422)->assertJsonPath('error.code', 'GRADE_SCALE_BAND_NOT_MUTABLE');

        $this->as($w)->patchJson($this->base($w)."/grade-scales/{$scale->id}/bands/{$band->id}", ['label' => 'Y'])
            ->assertStatus(422)->assertJsonPath('error.code', 'GRADE_SCALE_BAND_NOT_MUTABLE');

        $this->as($w)->deleteJson($this->base($w)."/grade-scales/{$scale->id}/bands/{$band->id}")
            ->assertStatus(422)->assertJsonPath('error.code', 'GRADE_SCALE_BAND_NOT_MUTABLE');
    }

    #[Test]
    public function a_duplicate_threshold_is_rejected(): void
    {
        $w = $this->gradeScaleWorld();
        $scale = $this->createGradeScale($w['school']);
        $this->createGradeBand($scale, ['min_percentage' => '50.00']);

        $this->as($w)->postJson($this->base($w)."/grade-scales/{$scale->id}/bands", [
            'min_percentage' => '50.00', 'label' => 'X',
        ])->assertStatus(422)->assertJsonPath('error.code', 'GRADE_SCALE_BAND_DUPLICATE_THRESHOLD');
    }

    // --- malicious payload injection -----------------------------------------

    #[Test]
    public function context_and_identity_injection_on_create_is_inert(): void
    {
        $w = $this->gradeScaleWorld();
        $other = $this->gradeScaleWorld();

        $created = $this->as($w)->postJson($this->collectionUrl($w), [
            'id' => 'attacker-id',
            'school_id' => $other['school']->id,
            'code' => 'GS1',
            'name' => 'S',
            'status' => 'active',
        ]);
        $created->assertCreated();

        $this->assertNotSame('attacker-id', $created->json('data.id'));
        $this->assertSame('draft', $created->json('data.status'));
    }

    #[Test]
    public function code_and_identity_injection_on_update_is_inert(): void
    {
        $w = $this->gradeScaleWorld();
        $other = $this->gradeScaleWorld();
        $id = $this->as($w)->postJson($this->collectionUrl($w), ['code' => 'GS1', 'name' => 'S'])->json('data.id');

        $response = $this->as($w)->patchJson($this->base($w)."/grade-scales/{$id}", [
            'code' => 'HACKED',
            'school_id' => $other['school']->id,
            'name' => 'Renamed',
        ]);
        $response->assertOk();

        $this->assertSame('GS1', $response->json('data.code'));
        $this->assertSame('Renamed', $response->json('data.name'));
    }

    // --- tenant isolation ------------------------------------------------------

    #[Test]
    public function another_schools_scale_is_not_reachable(): void
    {
        $w = $this->gradeScaleWorld();
        $other = $this->gradeScaleWorld();
        $foreign = $this->createGradeScale($other['school']);

        $this->as($w)->getJson($this->base($w)."/grade-scales/{$foreign->id}")->assertNotFound();
        $this->as($w)->patchJson($this->base($w)."/grade-scales/{$foreign->id}", ['name' => 'X'])->assertNotFound();
    }

    // --- authorization -----------------------------------------------------------

    #[Test]
    public function view_capability_alone_cannot_write(): void
    {
        $w = $this->gradeScaleWorld();
        $viewer = $this->createUserWithCapabilities($w['school'], ['examinations.grade_scales.view']);
        $scale = $this->createGradeScale($w['school']);

        $this->as($w, $viewer)->getJson($this->collectionUrl($w))->assertOk();
        $this->as($w, $viewer)->getJson($this->base($w)."/grade-scales/{$scale->id}")->assertOk();

        $this->as($w, $viewer)->postJson($this->collectionUrl($w), ['code' => 'GS2', 'name' => 'S'])->assertForbidden();
        $this->as($w, $viewer)->patchJson($this->base($w)."/grade-scales/{$scale->id}", ['name' => 'X'])->assertForbidden();
    }

    #[Test]
    public function unrelated_examinations_capabilities_grant_no_grade_scale_access(): void
    {
        $w = $this->gradeScaleWorld();
        $outsider = $this->createUserWithCapabilities($w['school'], [
            'examinations.definitions.view', 'examinations.definitions.manage',
            'examinations.papers.view', 'examinations.papers.manage',
        ]);
        $scale = $this->createGradeScale($w['school']);

        $this->as($w, $outsider)->getJson($this->collectionUrl($w))->assertForbidden();
        $this->as($w, $outsider)->postJson($this->collectionUrl($w), ['code' => 'GS2', 'name' => 'S'])->assertForbidden();
        $this->as($w, $outsider)->getJson($this->base($w)."/grade-scales/{$scale->id}")->assertForbidden();
    }

    // --- surface shape ---------------------------------------------------------

    #[Test]
    public function there_is_no_grade_scale_delete_route_and_exactly_seven_operations(): void
    {
        $w = $this->gradeScaleWorld();
        $scale = $this->createGradeScale($w['school']);

        $this->as($w)->deleteJson($this->base($w)."/grade-scales/{$scale->id}")->assertStatus(405);

        $routes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($r) => str_contains($r->uri(), 'grade-scales') && str_starts_with($r->uri(), 'api/'))
            ->flatMap(fn ($r) => array_map(fn ($m) => $m.' /'.$r->uri(), array_values(array_diff($r->methods(), ['HEAD']))))
            ->values();

        $this->assertCount(7, $routes, 'The GradeScale API is exactly seven operations: '.$routes->implode(', '));
        $this->assertSame(1, $routes->filter(fn ($r) => str_starts_with($r, 'DELETE '))->count(),
            'Exactly one DELETE route: GradeBand removal.');
    }

    // --- audit -----------------------------------------------------------------

    #[Test]
    public function audit_metadata_is_bounded_and_never_carries_name_or_label_values(): void
    {
        $w = $this->gradeScaleWorld();

        $id = $this->as($w)->postJson($this->collectionUrl($w), ['code' => 'GS1', 'name' => 'Secret Name'])->json('data.id');

        $created = $this->inGradeScaleSchool($w['school'], fn () => SchoolAuditEvent::query()
            ->where('event_type', 'examinations.grade_scale.created')->firstOrFail());
        $this->assertSame($id, $created->metadata['scaleId']);
        $this->assertSame('GS1', $created->metadata['code']);
        $this->assertStringNotContainsString('Secret Name', json_encode($created->metadata));

        $this->as($w)->postJson($this->base($w)."/grade-scales/{$id}/bands", [
            'min_percentage' => '0.00', 'label' => 'Confidential Label',
        ])->assertCreated();

        $bandEvent = $this->inGradeScaleSchool($w['school'], fn () => SchoolAuditEvent::query()
            ->where('event_type', 'examinations.grade_scale.updated')->latest('occurred_at')->firstOrFail());
        $this->assertStringNotContainsString('Confidential Label', json_encode($bandEvent->metadata));

        $this->as($w)->patchJson($this->base($w)."/grade-scales/{$id}", ['status' => 'active'])->assertOk();

        $activated = $this->inGradeScaleSchool($w['school'], fn () => SchoolAuditEvent::query()
            ->where('event_type', 'examinations.grade_scale.activated')->firstOrFail());
        $this->assertSame($id, $activated->metadata['scaleId']);
        $this->assertSame('draft', $activated->metadata['priorStatus']);
    }
}
