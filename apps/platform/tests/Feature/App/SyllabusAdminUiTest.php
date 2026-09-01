<?php

namespace Tests\Feature\App;

use App\Domain\Syllabus\Infrastructure\SyllabusUnit;
use App\Models\SchoolAuditEvent;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Syllabus\Concerns\CreatesSyllabusFixtures;
use Tests\TestCase;

/**
 * Phase 0H.3A -- the session-authenticated administrative Syllabus
 * surface: AcademicYear/SubjectOffering context, ordered unit list,
 * create, edit (including status), and the capability boundary.
 */
class SyllabusAdminUiTest extends TestCase
{
    use CreatesSyllabusFixtures;

    private function actor(array $w, ?object $user = null): static
    {
        return $this->actingAs($user ?? $w['actor'])->withHeader('X-School-Id', $w['school']->id);
    }

    #[Test]
    public function the_page_resolves_the_academic_year_and_offering_context(): void
    {
        $w = $this->syllabusWorld();

        $this->actor($w)->get('/app/syllabus')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('App/Syllabus/Index')
                // Defaults to the School's ACTIVE AcademicYear.
                ->where('filters.academicYearId', $w['year']->id)
                ->where('filters.subjectOfferingId', '')
                ->has('offerings', 1)
                ->where('offerings.0.id', $w['offering']->id)
                ->has('units', 0)
                ->where('statuses', ['active', 'inactive'])
                ->where('canManage', true)
            );
    }

    #[Test]
    public function selecting_an_offering_lists_its_units_in_order(): void
    {
        $w = $this->syllabusWorld();
        $this->createSyllabusUnit($w['offering'], ['code' => 'B1', 'sequence' => 2]);
        $this->createSyllabusUnit($w['offering'], ['code' => 'A1', 'sequence' => 1]);

        $this->actor($w)->get("/app/syllabus?subject_offering_id={$w['offering']->id}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('units', 2)
                ->where('units.0.code', 'A1')
                ->where('units.1.code', 'B1')
                ->where('filters.subjectOfferingId', $w['offering']->id)
            );
    }

    #[Test]
    public function a_unit_can_be_created_and_edited_through_the_ui(): void
    {
        $w = $this->syllabusWorld();

        $this->actor($w)->post('/app/syllabus', [
            'subject_offering_id' => $w['offering']->id,
            'code' => 'u1', 'title' => 'Unit One', 'sequence' => 1,
        ])->assertRedirect();

        $unit = $this->inSchool($w['school'], fn () => SyllabusUnit::query()->firstOrFail());
        $this->assertSame('U1', $unit->code, 'The UI path must normalize the code too.');

        $this->actor($w)->patch("/app/syllabus/{$unit->id}", [
            'title' => 'Renamed', 'sequence' => 7, 'status' => 'inactive',
        ])->assertRedirect();

        $fresh = $this->inSchool($w['school'], fn () => $unit->fresh());
        $this->assertSame('Renamed', $fresh->title);
        $this->assertSame(7, $fresh->sequence);
        $this->assertSame('inactive', $fresh->status);

        // Audited through the same bounded shape as the API.
        $this->assertSame(1, $this->inSchool($w['school'], fn () => SchoolAuditEvent::query()
            ->where('event_type', 'syllabus.unit.created')->count()));
        $this->assertSame(1, $this->inSchool($w['school'], fn () => SchoolAuditEvent::query()
            ->where('event_type', 'syllabus.unit.updated')->count()));
    }

    #[Test]
    public function a_duplicate_code_is_rejected_by_the_ui_endpoint(): void
    {
        $w = $this->syllabusWorld();
        $this->createSyllabusUnit($w['offering'], ['code' => 'U1']);

        $this->actor($w)->post('/app/syllabus', [
            'subject_offering_id' => $w['offering']->id,
            'code' => 'u1', 'title' => 'Duplicate', 'sequence' => 2,
        ])->assertSessionHasErrors('code');

        $this->assertSame(1, $this->inSchool($w['school'], fn () => SyllabusUnit::query()->count()));
    }

    #[Test]
    public function a_member_without_syllabus_view_cannot_reach_the_page(): void
    {
        $w = $this->syllabusWorld();
        $outsider = $this->createUserWithCapabilities($w['school'], ['school.settings.view']);

        $this->actor($w, $outsider)->get('/app/syllabus')->assertForbidden();
        $this->actor($w, $outsider)->post('/app/syllabus', [])->assertForbidden();
    }

    #[Test]
    public function a_view_only_member_sees_the_page_but_cannot_write(): void
    {
        $w = $this->syllabusWorld();
        $unit = $this->createSyllabusUnit($w['offering'], ['code' => 'U1']);
        $viewer = $this->createUserWithCapabilities($w['school'], ['syllabus.view']);

        $this->actor($w, $viewer)->get('/app/syllabus')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('canManage', false));

        $this->actor($w, $viewer)->post('/app/syllabus', [
            'subject_offering_id' => $w['offering']->id, 'code' => 'X', 'title' => 'T', 'sequence' => 1,
        ])->assertForbidden();
        $this->actor($w, $viewer)->patch("/app/syllabus/{$unit->id}", ['title' => 'T'])->assertForbidden();
    }

    #[Test]
    public function another_schools_unit_cannot_be_edited_through_the_ui(): void
    {
        $w = $this->syllabusWorld();
        $unit = $this->createSyllabusUnit($w['offering'], ['code' => 'U1', 'title' => 'Original']);
        $other = $this->syllabusWorld();

        // School B's actor, School B's session context, School A's unit.
        // The tenant-scoped lookup must refuse it outright.
        $response = $this->actor($other)->patch("/app/syllabus/{$unit->id}", ['title' => 'hijacked']);
        $this->assertContains($response->getStatusCode(), [403, 404]);

        $this->assertSame('Original', $this->inSchool($w['school'], fn () => $unit->fresh()->title));
    }
}
