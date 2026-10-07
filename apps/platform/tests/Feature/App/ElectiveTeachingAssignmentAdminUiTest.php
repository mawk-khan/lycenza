<?php

namespace Tests\Feature\App;

use App\Domain\TeachingAssignments\Infrastructure\ElectiveTeachingAssignment;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTeachingAssignmentFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * TCH-E (ADR 0063 section 45): the session-authenticated administrative
 * elective teaching assignment page -- list per AcademicYear, create, end --
 * under the same `teaching.assignments.*` capabilities. Teachers reach none
 * of it; pickers are manage-only, elective-only and directory-tier.
 */
class ElectiveTeachingAssignmentAdminUiTest extends TestCase
{
    use CreatesTeachingAssignmentFixtures, CreatesTenancyFixtures;

    private function actor(array $w, ?User $user = null): static
    {
        return $this->actingAs($user ?? $w['admin'])->withHeader('X-School-Id', $w['school']->id);
    }

    /** @return list<ElectiveTeachingAssignment> */
    private function rows(array $w): array
    {
        return app(TenantContext::class)->withSchool($w['school'], fn () => ElectiveTeachingAssignment::query()->get()->all());
    }

    #[Test]
    public function a_manager_lists_creates_and_ends_elective_assignments(): void
    {
        $w = $this->teachingWorld();
        $elective = $this->electiveOffering($w);

        $this->actor($w)->get('/app/elective-teaching-assignments')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('App/TeachingAssignments/Electives')
                ->where('academicYearId', $w['year']->id)
                ->where('canManage', true)
                ->has('assignments.data', 0)
                ->has('options.offerings', 1)
                ->where('options.offerings.0.id', $elective->id)
                ->where('options.employees', fn ($employees) => collect($employees)->every(fn ($e) => array_keys((array) $e) === ['id', 'employeeNumber', 'fullName'])));

        $this->actor($w)->post('/app/elective-teaching-assignments', [
            'employee_id' => $w['employee']->id, 'subject_offering_id' => $elective->id, 'starts_on' => '2026-06-01', 'ends_on' => null,
        ])->assertRedirect()->assertSessionHasNoErrors();
        [$row] = $this->rows($w);

        $this->actor($w)->get('/app/elective-teaching-assignments')
            ->assertInertia(fn (AssertableInertia $page) => $page->has('assignments.data', 1)->where('assignments.data.0.id', $row->id)
                ->where('assignments.data.0.subjectOffering.id', $elective->id)->missing('assignments.data.0.section'));

        $this->actor($w)->post("/app/elective-teaching-assignments/{$row->id}/end", ['ends_on' => '2026-09-30', 'reason' => 'completed'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('2026-09-30', $this->rows($w)[0]->ends_on->toDateString());
    }

    #[Test]
    public function a_required_offering_is_refused_with_a_message(): void
    {
        $w = $this->teachingWorld();

        $this->actor($w)->post('/app/elective-teaching-assignments', [
            'employee_id' => $w['employee']->id, 'subject_offering_id' => $w['offering']->id, 'starts_on' => '2026-06-01',
        ])->assertSessionHasErrors('starts_on');
        $this->assertSame([], $this->rows($w));
    }

    #[Test]
    public function viewers_cannot_manage_and_teachers_and_other_schools_reach_nothing(): void
    {
        $w = $this->teachingWorld();
        $elective = $this->electiveOffering($w);
        $row = $this->assignElective($w, $elective);

        $viewer = $this->createUserWithCapabilities($w['school'], ['teaching.assignments.view']);
        $this->actor($w, $viewer)->get('/app/elective-teaching-assignments')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('canManage', false)->where('options', null));
        $this->actor($w, $viewer)->post('/app/elective-teaching-assignments', [
            'employee_id' => $w['employee']->id, 'subject_offering_id' => $elective->id, 'starts_on' => '2026-07-01',
        ])->assertForbidden();
        $this->actor($w, $viewer)->post("/app/elective-teaching-assignments/{$row->id}/end", ['ends_on' => '2026-09-30', 'reason' => 'completed'])->assertForbidden();

        $teacher = $this->createUser();
        $this->assignSchoolRole($this->createMembership($teacher, $w['school']), 'teacher');
        $this->actor($w, $teacher)->get('/app/elective-teaching-assignments')->assertForbidden();
        $this->actor($w, $teacher)->post('/app/elective-teaching-assignments', [
            'employee_id' => $w['employee']->id, 'subject_offering_id' => $elective->id, 'starts_on' => '2026-07-01',
        ])->assertForbidden();

        $other = $this->teachingWorld();
        $this->actor($other)->post("/app/elective-teaching-assignments/{$row->id}/end", ['ends_on' => '2026-09-30', 'reason' => 'completed'])->assertNotFound();
        $this->assertNull($this->rows($w)[0]->ended_at);
    }
}
