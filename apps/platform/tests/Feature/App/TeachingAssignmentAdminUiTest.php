<?php

namespace Tests\Feature\App;

use App\Domain\TeachingAssignments\Infrastructure\TeachingAssignment;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTeachingAssignmentFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * TCH.2 (ADR 0063 section 23): the session-authenticated administrative
 * TeachingAssignment page -- list per AcademicYear, create, end -- and its
 * capability boundary. Pickers are manage-only and directory-tier.
 */
class TeachingAssignmentAdminUiTest extends TestCase
{
    use CreatesTeachingAssignmentFixtures, CreatesTenancyFixtures;

    private function actor(array $w, ?User $user = null): static
    {
        return $this->actingAs($user ?? $w['admin'])->withHeader('X-School-Id', $w['school']->id);
    }

    private function assignments(array $w): array
    {
        return app(TenantContext::class)->withSchool($w['school'], fn () => TeachingAssignment::query()->get()->all());
    }

    #[Test]
    public function a_manager_sees_the_active_years_assignments_and_directory_tier_pickers(): void
    {
        $w = $this->teachingWorld();
        $a = $this->assign($w, '2026-06-01');
        // An elective and another context's Section must not be offered as
        // a compatible pairing (server-filtered to required offerings).
        $this->createSubjectOffering($w['year'], $w['campus'], $w['grade'], $this->createSubject($w['school']), ['is_required' => false]);

        $this->actor($w)->get('/app/teaching-assignments')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('App/TeachingAssignments/Index')
                ->where('academicYearId', $w['year']->id)
                ->where('canManage', true)
                ->has('assignments.data', 1)
                ->where('assignments.data.0.id', $a->id)
                ->where('assignments.data.0.employee.fullName', $w['employee']->full_name)
                ->has('options.offerings', 1)
                ->where('options.offerings.0.id', $w['offering']->id)
                ->has('options.sections', 1)
                ->where('options.employees', fn ($employees) => collect($employees)->every(
                    fn ($e) => array_keys((array) $e) === ['id', 'employeeNumber', 'fullName'],
                ))
            );
    }

    #[Test]
    public function a_viewer_sees_the_list_without_pickers_and_cannot_write(): void
    {
        $w = $this->teachingWorld();
        $this->assign($w);
        $viewer = $this->createUserWithCapabilities($w['school'], ['teaching.assignments.view']);

        $this->actor($w, $viewer)->get('/app/teaching-assignments')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('canManage', false)
                ->where('options', null)
                ->has('assignments.data', 1)
            );

        $this->actor($w, $viewer)->post('/app/teaching-assignments', [
            'employee_id' => $w['employee']->id, 'section_id' => $w['section']->id,
            'subject_offering_id' => $w['offering']->id, 'starts_on' => '2027-01-01',
        ])->assertForbidden();

        $this->actor($w, $this->createUserWithCapabilities($w['school'], []))->get('/app/teaching-assignments')->assertForbidden();
    }

    #[Test]
    public function a_manager_creates_and_ends_an_assignment_and_domain_errors_are_form_errors(): void
    {
        $w = $this->teachingWorld();

        $this->actor($w)->post('/app/teaching-assignments', [
            'employee_id' => $w['employee']->id, 'section_id' => $w['section']->id,
            'subject_offering_id' => $w['offering']->id, 'starts_on' => '2026-06-01', 'ends_on' => null,
        ])->assertRedirect()->assertSessionHasNoErrors();

        [$a] = $this->assignments($w);

        $this->actor($w)->post('/app/teaching-assignments', [
            'employee_id' => $w['employee']->id, 'section_id' => $w['section']->id,
            'subject_offering_id' => $w['offering']->id, 'starts_on' => '2026-07-01',
        ])->assertSessionHasErrors('starts_on');

        $this->actor($w)->post("/app/teaching-assignments/{$a->id}/end", ['ends_on' => '2026-09-30', 'reason' => 'reassigned'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->actor($w)->post("/app/teaching-assignments/{$a->id}/end", ['ends_on' => '2026-08-31', 'reason' => 'completed'])
            ->assertSessionHasErrors('ends_on');

        $fresh = app(TenantContext::class)->withSchool($w['school'], fn () => $a->fresh());
        $this->assertSame(['2026-09-30', 'reassigned'], [$fresh->ends_on->toDateString(), $fresh->end_reason]);
    }

    #[Test]
    public function the_dashboard_links_the_page_only_for_viewers(): void
    {
        $w = $this->teachingWorld();

        $this->actor($w)->get('/app')->assertInertia(fn (AssertableInertia $page) => $page->where('nav.canViewTeachingAssignments', true));
        $this->actor($w, $this->createUserWithCapabilities($w['school'], ['timetable.schedule.view']))->get('/app')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('nav.canViewTeachingAssignments', false));
    }
}
