<?php

namespace Tests\Feature\Examinations;

use App\Domain\Examinations\Http\Controllers\ExaminationController;
use App\Domain\Examinations\Infrastructure\Examination;
use App\Models\SchoolAuditEvent;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Examinations\Concerns\CreatesExaminationFixtures;
use Tests\TestCase;

/**
 * Phase 0H.4A -- the four-operation Examination API: CRUD-minus-delete,
 * code normalization and case-insensitive uniqueness within one
 * AcademicYear, lifecycle through PATCH, deterministic ordering, tenant
 * isolation, audit, and capability allow/deny on every operation.
 */
class ExaminationApiTest extends TestCase
{
    use CreatesExaminationFixtures;

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
        return $this->base($w)."/academic-years/{$w['year']->id}/examinations";
    }

    private function payload(array $w, array $overrides = []): array
    {
        return array_merge([
            'code' => 'MID1',
            'name' => 'Mid-Term Examination',
            'starts_on' => $this->today()->addDays(10)->toDateString(),
            'ends_on' => $this->today()->addDays(20)->toDateString(),
        ], $overrides);
    }

    // --- create / read ------------------------------------------------

    #[Test]
    public function an_examination_can_be_created_and_read_back(): void
    {
        $w = $this->examinationWorld();

        $created = $this->as($w)->postJson($this->collectionUrl($w), $this->payload($w));
        $created->assertCreated();

        $data = $created->json('data');
        $this->assertSame('MID1', $data['code']);
        $this->assertSame('Mid-Term Examination', $data['name']);
        $this->assertSame($w['year']->id, $data['academicYearId']);
        $this->assertSame('active', $data['status']);

        $show = $this->as($w)->getJson($this->base($w)."/examinations/{$data['id']}");
        $show->assertOk();
        $this->assertSame($data['id'], $show->json('data.id'));

        // The projection carries the window's own facts and nothing
        // else -- no schoolId, no AcademicTerm, no Campus/GradeLevel, no
        // Student, no teacher, no paper data.
        $this->assertSame(
            ['id', 'academicYearId', 'code', 'name', 'startsOn', 'endsOn', 'status'],
            array_keys($data),
        );
    }

    #[Test]
    public function future_examination_dates_are_accepted(): void
    {
        // The defining departure from Curriculum Delivery: an
        // examination is SCHEDULED AHEAD.
        $w = $this->examinationWorld();
        $startsOn = $this->today()->addMonths(4)->toDateString();
        $endsOn = $this->today()->addMonths(5)->toDateString();

        $response = $this->as($w)->postJson($this->collectionUrl($w), $this->payload($w, [
            'starts_on' => $startsOn, 'ends_on' => $endsOn,
        ]));

        $response->assertCreated();
        $this->assertSame($startsOn, $response->json('data.startsOn'));
        $this->assertSame($endsOn, $response->json('data.endsOn'));
    }

    #[Test]
    public function overlapping_examination_windows_are_both_accepted(): void
    {
        $w = $this->examinationWorld();

        $this->as($w)->postJson($this->collectionUrl($w), $this->payload($w, [
            'code' => 'A1',
            'starts_on' => $this->today()->addDays(10)->toDateString(),
            'ends_on' => $this->today()->addDays(20)->toDateString(),
        ]))->assertCreated();

        $this->as($w)->postJson($this->collectionUrl($w), $this->payload($w, [
            'code' => 'B1',
            'starts_on' => $this->today()->addDays(15)->toDateString(),
            'ends_on' => $this->today()->addDays(25)->toDateString(),
        ]))->assertCreated();

        $this->assertSame(2, $this->inExaminationSchool(
            $w['school'], fn () => Examination::query()->count(),
        ));
    }

    #[Test]
    public function the_index_returns_that_years_examinations_in_deterministic_order(): void
    {
        $w = $this->examinationWorld();
        $this->createExamination($w['year'], [
            'code' => 'B1', 'starts_on' => $this->today()->addDays(30)->toDateString(),
            'ends_on' => $this->today()->addDays(35)->toDateString(),
        ]);
        $this->createExamination($w['year'], [
            'code' => 'A9', 'starts_on' => $this->today()->addDays(10)->toDateString(),
            'ends_on' => $this->today()->addDays(15)->toDateString(),
        ]);
        // Same start date as A9 -> tie breaks on upper(code).
        $this->createExamination($w['year'], [
            'code' => 'A1', 'starts_on' => $this->today()->addDays(10)->toDateString(),
            'ends_on' => $this->today()->addDays(12)->toDateString(),
        ]);

        // Another year's examination must not leak in.
        $otherYear = $this->createAcademicYear($w['school'], [
            'code' => 'AY-NEXT', 'status' => 'draft',
            'starts_on' => $this->today()->addMonths(7)->toDateString(),
            'ends_on' => $this->today()->addMonths(18)->toDateString(),
        ]);
        $this->createExamination($otherYear, [
            'code' => 'Z9',
            'starts_on' => $this->today()->addMonths(8)->toDateString(),
            'ends_on' => $this->today()->addMonths(9)->toDateString(),
        ]);

        $response = $this->as($w)->getJson($this->collectionUrl($w));
        $response->assertOk();

        $this->assertSame(['A1', 'A9', 'B1'], array_column($response->json('data'), 'code'));
    }

    #[Test]
    public function the_index_filters_by_status(): void
    {
        $w = $this->examinationWorld();
        $this->createExamination($w['year'], ['code' => 'A1']);
        $this->createExamination($w['year'], ['code' => 'B1', 'status' => 'inactive']);

        $active = $this->as($w)->getJson($this->collectionUrl($w).'?status=active');
        $active->assertOk();
        $this->assertSame(['A1'], array_column($active->json('data'), 'code'));

        $this->as($w)->getJson($this->collectionUrl($w).'?status=archived')->assertStatus(422);
    }

    // --- code normalization + uniqueness ------------------------------

    #[Test]
    public function a_code_is_normalized_and_case_insensitively_unique_within_the_year(): void
    {
        $w = $this->examinationWorld();

        $created = $this->as($w)->postJson($this->collectionUrl($w), $this->payload($w, ['code' => ' mid1 ']));
        $created->assertCreated();
        $this->assertSame('MID1', $created->json('data.code'));

        // A case-variant duplicate returns a clean 422, not a raw
        // constraint violation.
        $this->as($w)->postJson($this->collectionUrl($w), $this->payload($w, ['code' => 'Mid1']))
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['errors' => ['code']]]);
    }

    #[Test]
    public function the_same_code_is_accepted_in_a_different_academic_year(): void
    {
        $w = $this->examinationWorld();
        $this->as($w)->postJson($this->collectionUrl($w), $this->payload($w, ['code' => 'MID1']))->assertCreated();

        $nextYear = $this->createAcademicYear($w['school'], [
            'code' => 'AY-NEXT', 'status' => 'draft',
            'starts_on' => $this->today()->addMonths(7)->toDateString(),
            'ends_on' => $this->today()->addMonths(18)->toDateString(),
        ]);

        $this->as($w)->postJson($this->base($w)."/academic-years/{$nextYear->id}/examinations", [
            'code' => 'MID1',
            'name' => 'Mid-Term Examination',
            'starts_on' => $this->today()->addMonths(8)->toDateString(),
            'ends_on' => $this->today()->addMonths(9)->toDateString(),
        ])->assertCreated();
    }

    // --- validation ----------------------------------------------------

    #[Test]
    public function validation_rejects_missing_and_malformed_input(): void
    {
        $w = $this->examinationWorld();

        $this->as($w)->postJson($this->collectionUrl($w), [])
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['errors' => ['code', 'name', 'starts_on', 'ends_on']]]);

        $this->as($w)->postJson($this->collectionUrl($w), $this->payload($w, ['starts_on' => '10-09-2026']))
            ->assertStatus(422)->assertJsonStructure(['error' => ['errors' => ['starts_on']]]);

        $this->as($w)->postJson($this->collectionUrl($w), $this->payload($w, ['status' => 'draft']))
            ->assertStatus(422)->assertJsonStructure(['error' => ['errors' => ['status']]]);
    }

    #[Test]
    public function a_window_outside_the_academic_year_is_rejected(): void
    {
        $w = $this->examinationWorld();

        $this->as($w)->postJson($this->collectionUrl($w), $this->payload($w, [
            'starts_on' => $w['year']->starts_on->subDay()->toDateString(),
            'ends_on' => $this->today()->toDateString(),
        ]))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'EXAMINATION_DATE_OUTSIDE_ACADEMIC_YEAR');

        $this->as($w)->postJson($this->collectionUrl($w), $this->payload($w, [
            'code' => 'X2',
            'starts_on' => $this->today()->toDateString(),
            'ends_on' => $w['year']->ends_on->addDay()->toDateString(),
        ]))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'EXAMINATION_DATE_OUTSIDE_ACADEMIC_YEAR');
    }

    #[Test]
    public function an_end_date_before_the_start_date_is_rejected(): void
    {
        $w = $this->examinationWorld();

        $this->as($w)->postJson($this->collectionUrl($w), $this->payload($w, [
            'starts_on' => $this->today()->addDays(20)->toDateString(),
            'ends_on' => $this->today()->addDays(10)->toDateString(),
        ]))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'EXAMINATION_DATE_ORDER');
    }

    // --- update ---------------------------------------------------------

    #[Test]
    public function an_examination_can_be_updated_including_its_status(): void
    {
        $w = $this->examinationWorld();
        $examination = $this->createExamination($w['year'], ['code' => 'MID1']);
        $newEnd = $this->today()->addDays(25)->toDateString();

        $response = $this->as($w)->patchJson($this->base($w)."/examinations/{$examination->id}", [
            'name' => 'Renamed Examination',
            'ends_on' => $newEnd,
            'status' => 'inactive',
        ]);
        $response->assertOk();

        $this->assertSame('Renamed Examination', $response->json('data.name'));
        $this->assertSame($newEnd, $response->json('data.endsOn'));
        // `status` is an ordinary PATCH field -- there is no lifecycle
        // route and no guarded transition.
        $this->assertSame('inactive', $response->json('data.status'));
        // The AcademicYear is fixed at creation.
        $this->assertSame($w['year']->id, $response->json('data.academicYearId'));
    }

    #[Test]
    public function an_update_cannot_reassign_the_school_or_academic_year(): void
    {
        $w = $this->examinationWorld();
        $evil = $this->examinationWorld();
        $examination = $this->createExamination($w['year']);

        $this->as($w)->patchJson($this->base($w)."/examinations/{$examination->id}", [
            'name' => 'Still ours',
            'school_id' => $evil['school']->id,
            'academic_year_id' => $evil['year']->id,
        ])->assertOk();

        $fresh = $this->inExaminationSchool($w['school'], fn () => Examination::query()->findOrFail($examination->id));
        $this->assertSame($w['school']->id, $fresh->school_id);
        $this->assertSame($w['year']->id, $fresh->academic_year_id);
    }

    #[Test]
    public function an_update_revalidates_the_academic_year_range(): void
    {
        $w = $this->examinationWorld();
        $examination = $this->createExamination($w['year']);

        $this->as($w)->patchJson($this->base($w)."/examinations/{$examination->id}", [
            'ends_on' => $w['year']->ends_on->addDay()->toDateString(),
        ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'EXAMINATION_DATE_OUTSIDE_ACADEMIC_YEAR');
    }

    // --- tenant isolation ------------------------------------------------

    #[Test]
    public function another_schools_examination_is_not_reachable(): void
    {
        $w = $this->examinationWorld();
        $other = $this->examinationWorld();
        $foreign = $this->createExamination($other['year']);

        $this->as($w)->getJson($this->base($w)."/examinations/{$foreign->id}")->assertNotFound();
        $this->as($w)->patchJson($this->base($w)."/examinations/{$foreign->id}", ['name' => 'x'])->assertNotFound();

        // ...and a foreign AcademicYear's collection is a 404, not an
        // empty list that would imply it exists.
        $this->as($w)->getJson($this->base($w)."/academic-years/{$other['year']->id}/examinations")
            ->assertNotFound();
        $this->as($w)->postJson($this->base($w)."/academic-years/{$other['year']->id}/examinations", $this->payload($w))
            ->assertNotFound();
    }

    // --- authorization ----------------------------------------------------

    #[Test]
    public function view_capability_alone_cannot_write(): void
    {
        $w = $this->examinationWorld();
        $viewer = $this->createUserWithCapabilities($w['school'], ['examinations.definitions.view']);
        $examination = $this->createExamination($w['year']);

        $this->as($w, $viewer)->getJson($this->collectionUrl($w))->assertOk();
        $this->as($w, $viewer)->getJson($this->base($w)."/examinations/{$examination->id}")->assertOk();

        $this->as($w, $viewer)->postJson($this->collectionUrl($w), $this->payload($w, ['code' => 'NEW1']))
            ->assertForbidden();
        $this->as($w, $viewer)->patchJson($this->base($w)."/examinations/{$examination->id}", ['name' => 'x'])
            ->assertForbidden();
    }

    #[Test]
    public function unrelated_academic_capabilities_do_not_grant_examination_access(): void
    {
        $w = $this->examinationWorld();
        // Deliberately holds Academic Structure's YEAR pair (the parent
        // entity's own capabilities) plus the Academics pairs: none of
        // them may imply Examination access.
        $outsider = $this->createUserWithCapabilities($w['school'], [
            'academics.years.view', 'academics.years.manage',
            'syllabus.view', 'syllabus.manage',
            'curriculum.delivery.view', 'curriculum.delivery.manage',
        ]);
        $examination = $this->createExamination($w['year']);

        $this->as($w, $outsider)->getJson($this->collectionUrl($w))->assertForbidden();
        $this->as($w, $outsider)->postJson($this->collectionUrl($w), $this->payload($w, ['code' => 'NEW1']))
            ->assertForbidden();
        $this->as($w, $outsider)->getJson($this->base($w)."/examinations/{$examination->id}")->assertForbidden();
        $this->as($w, $outsider)->patchJson($this->base($w)."/examinations/{$examination->id}", ['name' => 'x'])
            ->assertForbidden();
    }

    // --- surface shape ------------------------------------------------------

    #[Test]
    public function there_is_no_delete_route_and_exactly_four_operations(): void
    {
        $w = $this->examinationWorld();
        $examination = $this->createExamination($w['year']);

        $this->as($w)->deleteJson($this->base($w)."/examinations/{$examination->id}")->assertStatus(405);

        // Filtered by controller class, not URI substring: Phase 0H.4B's
        // ExaminationPaper API deliberately shares the literal path
        // segment "examinations" (e.g.
        // `/examinations/{examination}/examination-papers`), so a
        // substring filter would now also catch it.
        $routes = collect(Route::getRoutes()->getRoutes())
            ->filter(function ($r) {
                $action = $r->getAction('controller');
                if (! is_string($action)) {
                    return false;
                }
                [$controller] = explode('@', $action, 2) + [null];

                return $controller === ExaminationController::class;
            })
            ->flatMap(fn ($r) => array_map(fn ($m) => $m.' /'.$r->uri(), array_values(array_diff($r->methods(), ['HEAD']))))
            ->values();

        $this->assertCount(4, $routes, 'The Examination API is exactly four operations: '.$routes->implode(', '));
        foreach ($routes as $route) {
            $this->assertStringNotContainsStringIgnoringCase('DELETE ', $route);
            $this->assertStringNotContainsStringIgnoringCase('activate', $route);
            $this->assertStringNotContainsStringIgnoringCase('archive', $route);
        }
    }

    // --- audit ---------------------------------------------------------------

    #[Test]
    public function audit_metadata_is_bounded_and_never_copies_the_name(): void
    {
        $w = $this->examinationWorld();

        $id = $this->as($w)->postJson($this->collectionUrl($w), $this->payload($w, [
            'name' => 'Secret Examination Name',
        ]))->json('data.id');

        $created = $this->inExaminationSchool($w['school'], fn () => SchoolAuditEvent::query()
            ->where('event_type', 'examinations.examination.created')->firstOrFail());

        $this->assertSame($id, $created->metadata['examinationId']);
        $this->assertSame($w['year']->id, $created->metadata['academicYearId']);
        $this->assertSame('MID1', $created->metadata['code']);
        $this->assertArrayHasKey('startsOn', $created->metadata);
        $this->assertArrayHasKey('endsOn', $created->metadata);
        $this->assertStringNotContainsString('Secret Examination Name', json_encode($created->metadata));

        $this->as($w)->patchJson($this->base($w)."/examinations/{$id}", [
            'name' => 'Another Secret Name', 'status' => 'inactive',
        ])->assertOk();

        $updated = $this->inExaminationSchool($w['school'], fn () => SchoolAuditEvent::query()
            ->where('event_type', 'examinations.examination.updated')->firstOrFail());

        $this->assertSame($id, $updated->metadata['examinationId']);
        $this->assertContains('name', $updated->metadata['changedFields']);
        $this->assertSame('active', $updated->metadata['before']['status']);
        $this->assertSame('inactive', $updated->metadata['after']['status']);

        // The name CHANGED, but its value must never be recorded.
        $encoded = json_encode($updated->metadata);
        $this->assertStringNotContainsString('Another Secret Name', $encoded);
        $this->assertArrayNotHasKey('name', $updated->metadata['before']);
        $this->assertArrayNotHasKey('name', $updated->metadata['after']);
    }
}
