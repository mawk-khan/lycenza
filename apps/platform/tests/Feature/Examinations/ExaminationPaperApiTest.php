<?php

namespace Tests\Feature\Examinations;

use App\Domain\Examinations\Infrastructure\ExaminationPaper;
use App\Models\SchoolAuditEvent;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Examinations\Concerns\CreatesExaminationPaperFixtures;
use Tests\TestCase;

/**
 * Phase 0H.4B -- the four-operation ExaminationPaper API: create/read
 * against active parents (required AND elective Offerings), scheduling
 * invariants, the aggregate duplicate rule, immutable parents, lifecycle
 * through PATCH with the reactivation guard, tenant isolation, audit, and
 * capability allow/deny.
 */
class ExaminationPaperApiTest extends TestCase
{
    use CreatesExaminationPaperFixtures;

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
        return $this->base($w)."/examinations/{$w['examination']->id}/examination-papers";
    }

    private function payload(array $w, array $overrides = []): array
    {
        return array_merge([
            'subject_offering_id' => $w['subjectOffering']->id,
            'scheduled_on' => $this->today()->addDays(10)->toDateString(),
            'starts_at' => '09:00:00',
            'ends_at' => '11:00:00',
            'max_marks' => '100.00',
        ], $overrides);
    }

    // --- create / read ----------------------------------------------------

    #[Test]
    public function a_paper_can_be_created_and_read_back(): void
    {
        $w = $this->examinationPaperWorld();

        $created = $this->as($w)->postJson($this->collectionUrl($w), $this->payload($w));
        $created->assertCreated();

        $data = $created->json('data');
        $this->assertSame($w['examination']->id, $data['examinationId']);
        $this->assertSame($w['subjectOffering']->id, $data['subjectOfferingId']);
        $this->assertSame('100.00', $data['maxMarks']);
        $this->assertSame('active', $data['status']);

        $show = $this->as($w)->getJson($this->base($w)."/examination-papers/{$data['id']}");
        $show->assertOk();
        $this->assertSame($data['id'], $show->json('data.id'));

        // Exactly eight public properties -- no schoolId, no
        // AcademicYear/Campus/GradeLevel pin, no Student, no teacher.
        $this->assertSame(
            ['id', 'examinationId', 'subjectOfferingId', 'scheduledOn', 'startsAt', 'endsAt', 'maxMarks', 'status'],
            array_keys($data),
        );
    }

    #[Test]
    public function an_active_required_offering_is_accepted(): void
    {
        $w = $this->examinationPaperWorld();
        $this->inExaminationSchool($w['school'], fn () => $w['subjectOffering']->forceFill(['is_required' => true])->save());

        $this->as($w)->postJson($this->collectionUrl($w), $this->payload($w))->assertCreated();
    }

    #[Test]
    public function an_active_elective_offering_is_accepted(): void
    {
        $w = $this->examinationPaperWorld();
        $this->inExaminationSchool($w['school'], fn () => $w['subjectOffering']->forceFill(['is_required' => false])->save());

        $this->as($w)->postJson($this->collectionUrl($w), $this->payload($w))->assertCreated();
    }

    #[Test]
    public function an_inactive_examination_rejects_creation(): void
    {
        $w = $this->examinationPaperWorld();
        $this->inExaminationSchool($w['school'], fn () => $w['examination']->forceFill(['status' => 'inactive'])->save());

        $this->as($w)->postJson($this->collectionUrl($w), $this->payload($w))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'EXAMINATION_NOT_ACTIVE');
    }

    #[Test]
    public function an_inactive_offering_rejects_creation(): void
    {
        $w = $this->examinationPaperWorld();
        $this->inExaminationSchool($w['school'], fn () => $w['subjectOffering']->forceFill(['status' => 'inactive'])->save());

        $this->as($w)->postJson($this->collectionUrl($w), $this->payload($w))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'EXAMINATION_PAPER_SUBJECT_OFFERING_NOT_AVAILABLE');
    }

    #[Test]
    public function future_paper_dates_are_accepted(): void
    {
        $w = $this->examinationPaperWorld();

        $this->as($w)->postJson($this->collectionUrl($w), $this->payload($w, [
            'scheduled_on' => $w['examination']->ends_on->toDateString(),
        ]))->assertCreated();
    }

    #[Test]
    public function a_scheduled_date_outside_the_examination_window_is_rejected(): void
    {
        $w = $this->examinationPaperWorld();

        $this->as($w)->postJson($this->collectionUrl($w), $this->payload($w, [
            'scheduled_on' => $w['examination']->starts_on->subDay()->toDateString(),
        ]))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'EXAMINATION_PAPER_DATE_OUTSIDE_WINDOW');
    }

    #[Test]
    public function an_end_time_not_after_the_start_time_is_rejected(): void
    {
        $w = $this->examinationPaperWorld();

        $this->as($w)->postJson($this->collectionUrl($w), $this->payload($w, [
            'starts_at' => '11:00:00', 'ends_at' => '09:00:00',
        ]))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'EXAMINATION_PAPER_TIME_ORDER');
    }

    #[Test]
    public function a_non_positive_max_marks_is_rejected(): void
    {
        $w = $this->examinationPaperWorld();

        $this->as($w)->postJson($this->collectionUrl($w), $this->payload($w, ['max_marks' => '0']))
            ->assertStatus(422);
    }

    #[Test]
    public function overlapping_papers_across_different_offerings_are_both_accepted(): void
    {
        $w = $this->examinationPaperWorld();
        $second = $this->createSubjectOffering($w['year'], $w['campus'], $w['gradeLevel'], $this->createSubject($w['school']));

        $this->as($w)->postJson($this->collectionUrl($w), $this->payload($w))->assertCreated();
        $this->as($w)->postJson($this->collectionUrl($w), $this->payload($w, ['subject_offering_id' => $second->id]))
            ->assertCreated();

        $this->assertSame(2, $this->inExaminationSchool($w['school'], fn () => ExaminationPaper::query()->count()));
    }

    #[Test]
    public function a_duplicate_examination_offering_pair_is_rejected(): void
    {
        $w = $this->examinationPaperWorld();
        $this->as($w)->postJson($this->collectionUrl($w), $this->payload($w))->assertCreated();

        $this->as($w)->postJson($this->collectionUrl($w), $this->payload($w, [
            'scheduled_on' => $this->today()->addDays(12)->toDateString(),
        ]))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'EXAMINATION_PAPER_DUPLICATE');
    }

    #[Test]
    public function the_index_returns_papers_in_deterministic_order(): void
    {
        $w = $this->examinationPaperWorld();
        $second = $this->createSubjectOffering($w['year'], $w['campus'], $w['gradeLevel'], $this->createSubject($w['school']));

        $this->as($w)->postJson($this->collectionUrl($w), $this->payload($w, [
            'scheduled_on' => $this->today()->addDays(15)->toDateString(),
        ]))->assertCreated();
        $this->as($w)->postJson($this->collectionUrl($w), $this->payload($w, [
            'subject_offering_id' => $second->id,
            'scheduled_on' => $this->today()->addDays(10)->toDateString(),
        ]))->assertCreated();

        $response = $this->as($w)->getJson($this->collectionUrl($w));
        $response->assertOk();

        $this->assertSame(
            [$this->today()->addDays(10)->toDateString(), $this->today()->addDays(15)->toDateString()],
            array_column($response->json('data'), 'scheduledOn'),
        );
    }

    // --- validation ---------------------------------------------------------

    #[Test]
    public function validation_rejects_missing_and_malformed_input(): void
    {
        $w = $this->examinationPaperWorld();

        $this->as($w)->postJson($this->collectionUrl($w), [])
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['errors' => ['subject_offering_id', 'scheduled_on', 'starts_at', 'ends_at', 'max_marks']]]);

        $this->as($w)->postJson($this->collectionUrl($w), $this->payload($w, ['status' => 'draft']))
            ->assertStatus(422)->assertJsonStructure(['error' => ['errors' => ['status']]]);
    }

    // --- malicious payload injection ----------------------------------------

    #[Test]
    public function context_and_identity_injection_on_create_is_inert(): void
    {
        $w = $this->examinationPaperWorld();
        $other = $this->examinationPaperWorld();

        $created = $this->as($w)->postJson($this->collectionUrl($w), $this->payload($w, [
            'id' => 'attacker-id',
            'school_id' => $other['school']->id,
            'examination_id' => $other['examination']->id,
            'academic_year_id' => $other['examination']->academic_year_id,
            'campus_id' => $other['campus']->id,
            'grade_level_id' => $other['gradeLevel']->id,
            'student_id' => 'nope',
            'teacher_id' => 'nope',
            'room_id' => 'nope',
        ]));
        $created->assertCreated();

        $this->assertNotSame('attacker-id', $created->json('data.id'));
        $this->assertSame($w['examination']->id, $created->json('data.examinationId'));
    }

    #[Test]
    public function parent_and_pin_injection_on_update_is_inert(): void
    {
        $w = $this->examinationPaperWorld();
        $other = $this->examinationPaperWorld();
        $id = $this->as($w)->postJson($this->collectionUrl($w), $this->payload($w))->json('data.id');

        $response = $this->as($w)->patchJson($this->base($w)."/examination-papers/{$id}", [
            'examination_id' => $other['examination']->id,
            'subject_offering_id' => $other['subjectOffering']->id,
            'school_id' => $other['school']->id,
            'academic_year_id' => $other['examination']->academic_year_id,
            'campus_id' => $other['campus']->id,
            'grade_level_id' => $other['gradeLevel']->id,
            'max_marks' => '50.00',
        ]);
        $response->assertOk();

        $this->assertSame($w['examination']->id, $response->json('data.examinationId'));
        $this->assertSame($w['subjectOffering']->id, $response->json('data.subjectOfferingId'));
        $this->assertSame('50.00', $response->json('data.maxMarks'));
    }

    // --- update / lifecycle ---------------------------------------------------

    #[Test]
    public function a_paper_can_be_updated_including_its_status(): void
    {
        $w = $this->examinationPaperWorld();
        $id = $this->as($w)->postJson($this->collectionUrl($w), $this->payload($w))->json('data.id');
        $newEnd = '12:30:00';

        $response = $this->as($w)->patchJson($this->base($w)."/examination-papers/{$id}", [
            'ends_at' => $newEnd,
            'status' => 'inactive',
        ]);
        $response->assertOk();

        $this->assertSame($newEnd, $response->json('data.endsAt'));
        $this->assertSame('inactive', $response->json('data.status'));
    }

    #[Test]
    public function a_correction_is_allowed_while_the_examination_is_inactive(): void
    {
        $w = $this->examinationPaperWorld();
        $id = $this->as($w)->postJson($this->collectionUrl($w), $this->payload($w))->json('data.id');
        $this->inExaminationSchool($w['school'], fn () => $w['examination']->forceFill(['status' => 'inactive'])->save());

        $this->as($w)->patchJson($this->base($w)."/examination-papers/{$id}", ['max_marks' => '60.00'])
            ->assertOk()
            ->assertJsonPath('data.maxMarks', '60.00');
    }

    #[Test]
    public function reactivation_succeeds_when_both_parents_are_active(): void
    {
        $w = $this->examinationPaperWorld();
        $id = $this->as($w)->postJson($this->collectionUrl($w), $this->payload($w, ['status' => 'inactive']))->json('data.id');

        $this->as($w)->patchJson($this->base($w)."/examination-papers/{$id}", ['status' => 'active'])
            ->assertOk()
            ->assertJsonPath('data.status', 'active');
    }

    #[Test]
    public function reactivation_is_rejected_while_the_examination_is_inactive(): void
    {
        $w = $this->examinationPaperWorld();
        $id = $this->as($w)->postJson($this->collectionUrl($w), $this->payload($w, ['status' => 'inactive']))->json('data.id');
        $this->inExaminationSchool($w['school'], fn () => $w['examination']->forceFill(['status' => 'inactive'])->save());

        $this->as($w)->patchJson($this->base($w)."/examination-papers/{$id}", ['status' => 'active'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'EXAMINATION_NOT_ACTIVE');
    }

    #[Test]
    public function reactivation_is_rejected_while_the_offering_is_inactive(): void
    {
        $w = $this->examinationPaperWorld();
        $id = $this->as($w)->postJson($this->collectionUrl($w), $this->payload($w, ['status' => 'inactive']))->json('data.id');
        $this->inExaminationSchool($w['school'], fn () => $w['subjectOffering']->forceFill(['status' => 'inactive'])->save());

        $this->as($w)->patchJson($this->base($w)."/examination-papers/{$id}", ['status' => 'active'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'EXAMINATION_PAPER_SUBJECT_OFFERING_NOT_AVAILABLE');
    }

    #[Test]
    public function inactive_papers_do_not_free_the_aggregate_pair_for_a_second_active_paper(): void
    {
        $w = $this->examinationPaperWorld();
        $this->as($w)->postJson($this->collectionUrl($w), $this->payload($w, ['status' => 'inactive']))->assertCreated();

        $this->as($w)->postJson($this->collectionUrl($w), $this->payload($w, [
            'scheduled_on' => $this->today()->addDays(13)->toDateString(),
        ]))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'EXAMINATION_PAPER_DUPLICATE');
    }

    // --- tenant isolation ------------------------------------------------------

    #[Test]
    public function another_schools_paper_is_not_reachable(): void
    {
        $w = $this->examinationPaperWorld();
        $other = $this->examinationPaperWorld();
        $foreign = $this->createExaminationPaper($other['examination'], $other['subjectOffering']);

        $this->as($w)->getJson($this->base($w)."/examination-papers/{$foreign->id}")->assertNotFound();
        $this->as($w)->patchJson($this->base($w)."/examination-papers/{$foreign->id}", ['max_marks' => '1'])->assertNotFound();

        $this->as($w)->getJson($this->base($w)."/examinations/{$other['examination']->id}/examination-papers")
            ->assertNotFound();
        $this->as($w)->postJson($this->base($w)."/examinations/{$other['examination']->id}/examination-papers", $this->payload($w))
            ->assertNotFound();
    }

    // --- authorization -----------------------------------------------------------

    #[Test]
    public function view_capability_alone_cannot_write(): void
    {
        $w = $this->examinationPaperWorld();
        $viewer = $this->createUserWithCapabilities($w['school'], ['examinations.papers.view']);
        $paper = $this->createExaminationPaper($w['examination'], $w['subjectOffering']);

        $this->as($w, $viewer)->getJson($this->collectionUrl($w))->assertOk();
        $this->as($w, $viewer)->getJson($this->base($w)."/examination-papers/{$paper->id}")->assertOk();

        $this->as($w, $viewer)->postJson($this->collectionUrl($w), $this->payload($w, ['scheduled_on' => $this->today()->addDays(20)->toDateString()]))
            ->assertForbidden();
        $this->as($w, $viewer)->patchJson($this->base($w)."/examination-papers/{$paper->id}", ['max_marks' => '1'])
            ->assertForbidden();
    }

    #[Test]
    public function examination_definitions_capability_alone_grants_no_paper_access(): void
    {
        $w = $this->examinationPaperWorld();
        $outsider = $this->createUserWithCapabilities($w['school'], [
            'examinations.definitions.view', 'examinations.definitions.manage',
        ]);
        $paper = $this->createExaminationPaper($w['examination'], $w['subjectOffering']);

        $this->as($w, $outsider)->getJson($this->collectionUrl($w))->assertForbidden();
        $this->as($w, $outsider)->postJson($this->collectionUrl($w), $this->payload($w))->assertForbidden();
        $this->as($w, $outsider)->getJson($this->base($w)."/examination-papers/{$paper->id}")->assertForbidden();
        $this->as($w, $outsider)->patchJson($this->base($w)."/examination-papers/{$paper->id}", ['max_marks' => '1'])
            ->assertForbidden();
    }

    // --- surface shape ---------------------------------------------------------

    #[Test]
    public function there_is_no_delete_route_and_exactly_four_operations(): void
    {
        $w = $this->examinationPaperWorld();
        $paper = $this->createExaminationPaper($w['examination'], $w['subjectOffering']);

        $this->as($w)->deleteJson($this->base($w)."/examination-papers/{$paper->id}")->assertStatus(405);

        $routes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($r) => str_contains($r->uri(), 'examination-papers') && str_starts_with($r->uri(), 'api/'))
            ->flatMap(fn ($r) => array_map(fn ($m) => $m.' /'.$r->uri(), array_values(array_diff($r->methods(), ['HEAD']))))
            ->values();

        $this->assertCount(4, $routes, 'The ExaminationPaper API is exactly four operations: '.$routes->implode(', '));
        foreach ($routes as $route) {
            $this->assertStringNotContainsStringIgnoringCase('DELETE ', $route);
            $this->assertStringNotContainsStringIgnoringCase('activate', $route);
            $this->assertStringNotContainsStringIgnoringCase('archive', $route);
        }
    }

    // --- audit -----------------------------------------------------------------

    #[Test]
    public function audit_metadata_is_bounded(): void
    {
        $w = $this->examinationPaperWorld();

        $id = $this->as($w)->postJson($this->collectionUrl($w), $this->payload($w))->json('data.id');

        $created = $this->inExaminationSchool($w['school'], fn () => SchoolAuditEvent::query()
            ->where('event_type', 'examinations.paper.created')->firstOrFail());

        $this->assertSame($id, $created->metadata['paperId']);
        $this->assertSame($w['examination']->id, $created->metadata['examinationId']);
        $this->assertSame($w['subjectOffering']->id, $created->metadata['subjectOfferingId']);
        $this->assertArrayHasKey('scheduledOn', $created->metadata);
        $this->assertArrayHasKey('maxMarks', $created->metadata);

        $this->as($w)->patchJson($this->base($w)."/examination-papers/{$id}", ['status' => 'inactive'])->assertOk();

        $updated = $this->inExaminationSchool($w['school'], fn () => SchoolAuditEvent::query()
            ->where('event_type', 'examinations.paper.updated')->firstOrFail());

        $this->assertSame($id, $updated->metadata['paperId']);
        $this->assertContains('status', $updated->metadata['changedFields']);
        $this->assertSame('active', $updated->metadata['before']['status']);
        $this->assertSame('inactive', $updated->metadata['after']['status']);
    }
}
