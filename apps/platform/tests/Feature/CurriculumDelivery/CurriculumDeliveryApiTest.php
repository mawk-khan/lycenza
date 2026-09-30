<?php

namespace Tests\Feature\CurriculumDelivery;

use App\Domain\CurriculumDelivery\Infrastructure\CurriculumDelivery;
use App\Models\SchoolAuditEvent;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\CurriculumDelivery\Concerns\CreatesCurriculumDeliveryFixtures;
use Tests\TestCase;

/**
 * Phase 0H.3B -- the five-operation Curriculum Delivery API: start,
 * list, show, correct dates, transition. Covers ordering, filters,
 * validation, tenant isolation, parent mismatch, elective rejection,
 * the PATCH status refusal, the absence of a delete route, and
 * capability allow/deny on every operation.
 */
class CurriculumDeliveryApiTest extends TestCase
{
    use CreatesCurriculumDeliveryFixtures;

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
        return $this->base($w)."/subject-offerings/{$w['offering']->id}/curriculum-deliveries";
    }

    // --- start / read ------------------------------------------------

    #[Test]
    public function a_delivery_can_be_started_and_read_back(): void
    {
        $w = $this->deliveryWorld();
        $startedOn = $this->today()->subDays(3)->toDateString();

        $created = $this->as($w)->postJson($this->collectionUrl($w), [
            'section_id' => $w['section']->id,
            'syllabus_unit_id' => $w['unit']->id,
            'started_on' => $startedOn,
        ]);
        $created->assertCreated();

        $data = $created->json('data');
        $this->assertSame($w['section']->id, $data['sectionId']);
        $this->assertSame($w['offering']->id, $data['subjectOfferingId']);
        $this->assertSame($w['unit']->id, $data['syllabusUnitId']);
        $this->assertSame($startedOn, $data['startedOn']);
        $this->assertNull($data['completedOn']);
        // A delivery is never born completed.
        $this->assertSame('in_progress', $data['status']);

        $show = $this->as($w)->getJson($this->base($w)."/curriculum-deliveries/{$data['id']}");
        $show->assertOk();
        $this->assertSame($data['id'], $show->json('data.id'));

        // The projection exposes the fact and nothing else -- no
        // teacher, no Student, no structural integrity pins beyond the
        // Offering the client already navigated.
        $this->assertSame(
            ['id', 'sectionId', 'subjectOfferingId', 'syllabusUnitId', 'startedOn', 'completedOn', 'status'],
            array_keys($data),
        );
    }

    #[Test]
    public function the_index_orders_by_the_syllabus_catalogue_order_and_scopes_to_the_offering(): void
    {
        $w = $this->deliveryWorld();
        // Catalogue order is sequence, then upper(code) -- NOT creation
        // order and nothing stored on the delivery row itself.
        $b1 = $this->createSyllabusUnitFor($w['offering'], ['code' => 'B1', 'sequence' => 2]);
        $a9 = $this->createSyllabusUnitFor($w['offering'], ['code' => 'A9', 'sequence' => 1]);
        $a1 = $this->createSyllabusUnitFor($w['offering'], ['code' => 'A1', 'sequence' => 1]);

        foreach ([$b1, $a9, $a1] as $unit) {
            $this->createDelivery($w['offering'], $w['section'], $unit);
        }

        // A delivery under a different Offering must not leak in.
        $otherOffering = $this->createSubjectOffering(
            $w['year'], $w['campus'], $w['grade'], $this->createSubject($w['school'], ['code' => 'OTH']),
            ['is_required' => true, 'status' => 'active'],
        );
        $otherUnit = $this->createSyllabusUnitFor($otherOffering, ['code' => 'Z9']);
        $this->createDelivery($otherOffering, $w['section'], $otherUnit);

        $response = $this->as($w)->getJson($this->collectionUrl($w));
        $response->assertOk();

        $this->assertSame(
            [$a1->id, $a9->id, $b1->id],
            array_column($response->json('data'), 'syllabusUnitId'),
        );
    }

    #[Test]
    public function the_index_filters_by_section_and_status(): void
    {
        $w = $this->deliveryWorld();
        $sectionB = $this->createSection($w['year'], $w['campus'], $w['grade'], ['code' => 'B']);
        $unit2 = $this->createSyllabusUnitFor($w['offering'], ['code' => 'U2', 'sequence' => 2]);

        $this->createDelivery($w['offering'], $w['section'], $w['unit']);
        $this->createDelivery($w['offering'], $sectionB, $w['unit']);
        $this->createDelivery($w['offering'], $w['section'], $unit2, [
            'status' => 'completed', 'completed_on' => $this->today()->subDay()->toDateString(),
        ]);

        $bySection = $this->as($w)->getJson($this->collectionUrl($w)."?section_id={$w['section']->id}");
        $bySection->assertOk();
        $this->assertCount(2, $bySection->json('data'));

        $byStatus = $this->as($w)->getJson($this->collectionUrl($w).'?status=completed');
        $byStatus->assertOk();
        $this->assertCount(1, $byStatus->json('data'));
        $this->assertSame($unit2->id, $byStatus->json('data.0.syllabusUnitId'));

        $this->as($w)->getJson($this->collectionUrl($w).'?status=not_started')
            ->assertStatus(422);
    }

    // --- structural rejections ---------------------------------------

    #[Test]
    public function an_elective_offering_is_rejected(): void
    {
        $w = $this->deliveryWorld(offeringAttributes: ['is_required' => false]);

        $this->as($w)->postJson($this->collectionUrl($w), [
            'section_id' => $w['section']->id,
            'syllabus_unit_id' => $w['unit']->id,
            'started_on' => $this->today()->toDateString(),
        ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'CURRICULUM_DELIVERY_REQUIRED_OFFERING_ONLY');

        $this->assertSame(0, CurriculumDelivery::query()->withoutGlobalScopes()->count());
    }

    #[Test]
    public function a_syllabus_unit_from_another_offering_is_rejected_without_writing_a_row(): void
    {
        $w = $this->deliveryWorld();
        $scienceOffering = $this->createSubjectOffering(
            $w['year'], $w['campus'], $w['grade'], $this->createSubject($w['school'], ['code' => 'SCI']),
            ['is_required' => true, 'status' => 'active'],
        );
        $scienceUnit = $this->createSyllabusUnitFor($scienceOffering, ['code' => 'S1']);

        $this->as($w)->postJson($this->collectionUrl($w), [
            'section_id' => $w['section']->id,
            'syllabus_unit_id' => $scienceUnit->id,
            'started_on' => $this->today()->toDateString(),
        ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'CURRICULUM_DELIVERY_CONTEXT_MISMATCH');

        // Rejected BEFORE any write -- no stray row is left behind.
        $this->assertSame(0, CurriculumDelivery::query()->withoutGlobalScopes()->count());
    }

    #[Test]
    public function a_section_from_a_different_grade_level_is_rejected(): void
    {
        $w = $this->deliveryWorld();
        $otherGrade = $this->createGradeLevel($w['school'], ['code' => 'G9', 'sequence' => 9]);
        $foreignSection = $this->createSection($w['year'], $w['campus'], $otherGrade, ['code' => 'B']);

        $this->as($w)->postJson($this->collectionUrl($w), [
            'section_id' => $foreignSection->id,
            'syllabus_unit_id' => $w['unit']->id,
            'started_on' => $this->today()->toDateString(),
        ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'CURRICULUM_DELIVERY_CONTEXT_MISMATCH');
    }

    #[Test]
    public function a_duplicate_delivery_for_the_same_section_and_unit_is_rejected(): void
    {
        $w = $this->deliveryWorld();
        $this->createDelivery($w['offering'], $w['section'], $w['unit']);

        $this->as($w)->postJson($this->collectionUrl($w), [
            'section_id' => $w['section']->id,
            'syllabus_unit_id' => $w['unit']->id,
            'started_on' => $this->today()->toDateString(),
        ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'CURRICULUM_DELIVERY_DUPLICATE');
    }

    #[Test]
    public function validation_rejects_missing_and_malformed_input(): void
    {
        $w = $this->deliveryWorld();

        $this->as($w)->postJson($this->collectionUrl($w), [])
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['errors' => ['section_id', 'syllabus_unit_id', 'started_on']]]);

        $this->as($w)->postJson($this->collectionUrl($w), [
            'section_id' => $w['section']->id,
            'syllabus_unit_id' => $w['unit']->id,
            'started_on' => '15-06-2026',
        ])->assertStatus(422)->assertJsonStructure(['error' => ['errors' => ['started_on']]]);
    }

    // --- correction ---------------------------------------------------

    #[Test]
    public function dates_can_be_corrected_and_status_is_never_accepted(): void
    {
        $w = $this->deliveryWorld();
        $delivery = $this->createDelivery($w['offering'], $w['section'], $w['unit']);
        $corrected = $this->today()->subDays(20)->toDateString();

        $response = $this->as($w)->patchJson($this->base($w)."/curriculum-deliveries/{$delivery->id}", [
            'started_on' => $corrected,
            // Deliberately smuggled: PATCH must ignore it entirely --
            // state changes go through the transition operation.
            'status' => 'completed',
        ]);
        $response->assertOk();
        $this->assertSame($corrected, $response->json('data.startedOn'));
        $this->assertSame('in_progress', $response->json('data.status'));
        $this->assertNull($response->json('data.completedOn'));
    }

    #[Test]
    public function a_completion_date_cannot_be_set_on_an_in_progress_delivery_through_patch(): void
    {
        $w = $this->deliveryWorld();
        $delivery = $this->createDelivery($w['offering'], $w['section'], $w['unit']);

        $this->as($w)->patchJson($this->base($w)."/curriculum-deliveries/{$delivery->id}", [
            'completed_on' => $this->today()->toDateString(),
        ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'CURRICULUM_DELIVERY_COMPLETION_DATE_NOT_ALLOWED');
    }

    // --- transition ---------------------------------------------------

    #[Test]
    public function a_delivery_can_be_completed_and_reopened(): void
    {
        $w = $this->deliveryWorld();
        $delivery = $this->createDelivery($w['offering'], $w['section'], $w['unit']);
        $url = $this->base($w)."/curriculum-deliveries/{$delivery->id}/transition";
        $completedOn = $this->today()->subDay()->toDateString();

        $complete = $this->as($w)->postJson($url, [
            'expected_status' => 'in_progress',
            'new_status' => 'completed',
            'completed_on' => $completedOn,
        ]);
        $complete->assertOk();
        $this->assertSame('completed', $complete->json('data.status'));
        $this->assertSame($completedOn, $complete->json('data.completedOn'));

        $reopen = $this->as($w)->postJson($url, [
            'expected_status' => 'completed',
            'new_status' => 'in_progress',
        ]);
        $reopen->assertOk();
        $this->assertSame('in_progress', $reopen->json('data.status'));
        // Reopening always clears the completion date so the database
        // biconditional can never be violated.
        $this->assertNull($reopen->json('data.completedOn'));
    }

    #[Test]
    public function a_stale_expected_status_is_refused_with_a_conflict(): void
    {
        $w = $this->deliveryWorld();
        $delivery = $this->createDelivery($w['offering'], $w['section'], $w['unit'], [
            'status' => 'completed', 'completed_on' => $this->today()->subDay()->toDateString(),
        ]);

        $this->as($w)->postJson($this->base($w)."/curriculum-deliveries/{$delivery->id}/transition", [
            'expected_status' => 'in_progress',
            'new_status' => 'completed',
            'completed_on' => $this->today()->toDateString(),
        ])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'CURRICULUM_DELIVERY_STATUS_CHANGED');
    }

    #[Test]
    public function completing_requires_a_completion_date(): void
    {
        $w = $this->deliveryWorld();
        $delivery = $this->createDelivery($w['offering'], $w['section'], $w['unit']);

        $this->as($w)->postJson($this->base($w)."/curriculum-deliveries/{$delivery->id}/transition", [
            'expected_status' => 'in_progress',
            'new_status' => 'completed',
        ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'CURRICULUM_DELIVERY_COMPLETION_DATE_REQUIRED');
    }

    // --- tenant isolation ---------------------------------------------

    #[Test]
    public function another_schools_delivery_is_not_reachable(): void
    {
        $w = $this->deliveryWorld();
        $other = $this->deliveryWorld();
        $foreign = $this->createDelivery($other['offering'], $other['section'], $other['unit']);

        $this->as($w)->getJson($this->base($w)."/curriculum-deliveries/{$foreign->id}")->assertNotFound();

        $this->as($w)->patchJson($this->base($w)."/curriculum-deliveries/{$foreign->id}", [
            'started_on' => $this->today()->toDateString(),
        ])->assertNotFound();

        $this->as($w)->postJson($this->base($w)."/curriculum-deliveries/{$foreign->id}/transition", [
            'expected_status' => 'in_progress', 'new_status' => 'completed',
            'completed_on' => $this->today()->toDateString(),
        ])->assertNotFound();

        // ...and the foreign Offering's collection is a 404, not an
        // empty list that would imply it exists.
        $this->as($w)->getJson($this->base($w)."/subject-offerings/{$other['offering']->id}/curriculum-deliveries")
            ->assertNotFound();
    }

    // --- authorization -------------------------------------------------

    #[Test]
    public function view_capability_alone_cannot_write(): void
    {
        $w = $this->deliveryWorld();
        $viewer = $this->createUserWithCapabilities($w['school'], ['curriculum.delivery.view']);
        $delivery = $this->createDelivery($w['offering'], $w['section'], $w['unit']);

        // Allowed.
        $this->as($w, $viewer)->getJson($this->collectionUrl($w))->assertOk();
        $this->as($w, $viewer)->getJson($this->base($w)."/curriculum-deliveries/{$delivery->id}")->assertOk();

        // Denied.
        $this->as($w, $viewer)->postJson($this->collectionUrl($w), [
            'section_id' => $w['section']->id, 'syllabus_unit_id' => $w['unit']->id,
            'started_on' => $this->today()->toDateString(),
        ])->assertForbidden();
        $this->as($w, $viewer)->patchJson($this->base($w)."/curriculum-deliveries/{$delivery->id}", [
            'started_on' => $this->today()->toDateString(),
        ])->assertForbidden();
        $this->as($w, $viewer)->postJson($this->base($w)."/curriculum-deliveries/{$delivery->id}/transition", [
            'expected_status' => 'in_progress', 'new_status' => 'completed',
            'completed_on' => $this->today()->toDateString(),
        ])->assertForbidden();
    }

    #[Test]
    public function a_member_with_neither_capability_is_denied_on_every_operation(): void
    {
        $w = $this->deliveryWorld();
        // Deliberately holds the SYLLABUS pair: curating the catalogue
        // must not imply the right to read or record delivery.
        $outsider = $this->createUserWithCapabilities($w['school'], ['syllabus.view', 'syllabus.manage']);
        $delivery = $this->createDelivery($w['offering'], $w['section'], $w['unit']);

        $this->as($w, $outsider)->getJson($this->collectionUrl($w))->assertForbidden();
        $this->as($w, $outsider)->postJson($this->collectionUrl($w), [
            'section_id' => $w['section']->id, 'syllabus_unit_id' => $w['unit']->id,
            'started_on' => $this->today()->toDateString(),
        ])->assertForbidden();
        $this->as($w, $outsider)->getJson($this->base($w)."/curriculum-deliveries/{$delivery->id}")->assertForbidden();
        $this->as($w, $outsider)->patchJson($this->base($w)."/curriculum-deliveries/{$delivery->id}", [
            'started_on' => $this->today()->toDateString(),
        ])->assertForbidden();
        $this->as($w, $outsider)->postJson($this->base($w)."/curriculum-deliveries/{$delivery->id}/transition", [
            'expected_status' => 'in_progress', 'new_status' => 'completed',
            'completed_on' => $this->today()->toDateString(),
        ])->assertForbidden();
    }

    // --- surface shape ---------------------------------------------------

    #[Test]
    public function there_is_no_delete_route_and_exactly_five_operations(): void
    {
        $w = $this->deliveryWorld();
        $delivery = $this->createDelivery($w['offering'], $w['section'], $w['unit']);

        $this->as($w)->deleteJson($this->base($w)."/curriculum-deliveries/{$delivery->id}")
            ->assertStatus(405);

        $routes = collect(Route::getRoutes()->getRoutes())
            // The Tier 1 (School-wide) surface; TCH.3's owned `/my/` family
            // is pinned by CurriculumDeliveryArchitectureGuardTest.
            ->filter(fn ($r) => str_contains($r->uri(), 'curriculum-deliveries') && str_starts_with($r->uri(), 'api/') && ! str_contains($r->uri(), '/my/'))
            ->flatMap(fn ($r) => array_map(fn ($m) => $m.' /'.$r->uri(), array_values(array_diff($r->methods(), ['HEAD']))))
            ->values();

        $this->assertCount(5, $routes, 'The Curriculum Delivery API is exactly five operations: '.$routes->implode(', '));
        foreach ($routes as $route) {
            $this->assertStringNotContainsStringIgnoringCase('DELETE ', $route);
            $this->assertStringNotContainsStringIgnoringCase('activate', $route);
            $this->assertStringNotContainsStringIgnoringCase('archive', $route);
        }
    }

    // --- audit -----------------------------------------------------------

    #[Test]
    public function every_operation_writes_bounded_audit_metadata_without_curriculum_prose(): void
    {
        $w = $this->deliveryWorld();
        $this->inDeliverySchool($w['school'], fn () => $w['unit']->update(['title' => 'Secret Curriculum Title']));

        $created = $this->as($w)->postJson($this->collectionUrl($w), [
            'section_id' => $w['section']->id,
            'syllabus_unit_id' => $w['unit']->id,
            'started_on' => $this->today()->subDays(2)->toDateString(),
        ])->assertCreated();
        $deliveryId = $created->json('data.id');

        $this->as($w)->postJson($this->base($w)."/curriculum-deliveries/{$deliveryId}/transition", [
            'expected_status' => 'in_progress', 'new_status' => 'completed',
            'completed_on' => $this->today()->toDateString(),
        ])->assertOk();

        $this->as($w)->patchJson($this->base($w)."/curriculum-deliveries/{$deliveryId}", [
            'started_on' => $this->today()->subDays(4)->toDateString(),
        ])->assertOk();

        $events = $this->inDeliverySchool($w['school'], fn () => SchoolAuditEvent::query()
            ->whereIn('event_type', [
                'curriculum.delivery.created',
                'curriculum.delivery.transitioned',
                'curriculum.delivery.updated',
            ])
            ->get());

        $this->assertSame(
            ['curriculum.delivery.created', 'curriculum.delivery.transitioned', 'curriculum.delivery.updated'],
            $events->pluck('event_type')->sort()->values()->all(),
        );

        $transitioned = $events->firstWhere('event_type', 'curriculum.delivery.transitioned');
        $this->assertSame('in_progress', $transitioned->metadata['previousStatus']);
        $this->assertSame('completed', $transitioned->metadata['newStatus']);

        $updated = $events->firstWhere('event_type', 'curriculum.delivery.updated');
        $this->assertSame(['started_on'], $updated->metadata['changedFields']);
        $this->assertArrayHasKey('before', $updated->metadata);
        $this->assertArrayHasKey('after', $updated->metadata);

        // An audit row must never become a second copy of curriculum
        // content, and must never name a person.
        foreach ($events as $event) {
            $encoded = json_encode($event->metadata);
            $this->assertStringNotContainsString('Secret Curriculum Title', $encoded);
            $this->assertStringNotContainsString('teacher', $encoded);
            $this->assertStringNotContainsString('student', $encoded);
        }
    }
}
