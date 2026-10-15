<?php

namespace Tests\Feature\App;

use App\Domain\TeachingAssignments\Application\TeachingAssignmentService;
use App\Models\User;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTeachingAssignmentFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * V11.2 (ADR 0075): the Teaching Assignment list's teacher/Section filters
 * (already supported by TeachingAssignmentReadService::list() and the API)
 * and their options, which come only from that year's own assignments.
 * Plus the Employee profile's link, shown only to holders of
 * teaching.assignments.view.
 */
class TeachingAssignmentFiltersUiTest extends TestCase
{
    use CreatesTeachingAssignmentFixtures, CreatesTenancyFixtures;

    private function actor(array $w, User $user): static
    {
        return $this->actingAs($user)->withHeader('X-School-Id', $w['school']->id);
    }

    /** Two teachers, two Sections of the same class context, one assignment each. */
    private function twoAssignments(): array
    {
        $w = $this->teachingWorld();
        $secondSection = $this->createSection($w['year'], $w['campus'], $w['grade'], ['code' => 'SB'.strtoupper(Str::random(6)), 'name' => 'Second Section']);
        $secondTeacher = $this->employedTeacher($w['school']);
        $first = $this->assign($w, '2026-06-01');
        $second = app(TeachingAssignmentService::class)->create(
            $w['school'], $secondTeacher->id, $secondSection->id, $w['offering']->id, '2026-06-01', null, $w['admin'],
        );

        return [$w, $first, $second, $secondTeacher, $secondSection];
    }

    #[Test]
    public function the_teacher_and_section_filters_narrow_the_list_and_combine(): void
    {
        [$w, $first, $second, $secondTeacher] = $this->twoAssignments();
        $viewer = $this->createUserWithCapabilities($w['school'], ['teaching.assignments.view']);

        $ids = fn (string $query) => array_column(
            $this->actor($w, $viewer)->get('/app/teaching-assignments?'.$query)->assertOk()->viewData('page')['props']['assignments']['data'],
            'id',
        );

        $this->assertEqualsCanonicalizing([$first->id, $second->id], $ids(''));
        $this->assertSame([$second->id], $ids('employee_id='.$secondTeacher->id));
        $this->assertSame([$first->id], $ids('section_id='.$w['section']->id));
        $this->assertSame([], $ids('employee_id='.$secondTeacher->id.'&section_id='.$w['section']->id));
    }

    #[Test]
    public function filter_options_are_the_years_own_teachers_and_sections_only(): void
    {
        [$w, , , $secondTeacher, $secondSection] = $this->twoAssignments();
        // An employed teacher with no assignment, and a Section nobody teaches:
        // neither may appear as a filter option.
        $unassigned = $this->employedTeacher($w['school']);
        $untaught = $this->createSection($w['year'], $w['campus'], $w['grade'], ['code' => 'SU'.strtoupper(Str::random(6)), 'name' => 'Untaught Section']);
        $viewer = $this->createUserWithCapabilities($w['school'], ['teaching.assignments.view']);

        $props = $this->actor($w, $viewer)->get('/app/teaching-assignments')->assertOk()->viewData('page')['props'];

        $employeeIds = array_column($props['filterOptions']['employees'], 'id');
        $sectionIds = array_column($props['filterOptions']['sections'], 'id');
        $this->assertEqualsCanonicalizing([$w['employee']->id, $secondTeacher->id], $employeeIds);
        $this->assertEqualsCanonicalizing([$w['section']->id, $secondSection->id], $sectionIds);
        $this->assertNotContains($unassigned->id, $employeeIds);
        $this->assertNotContains($untaught->id, $sectionIds);
        $this->assertSame(['id', 'employeeNumber', 'fullName'], array_keys($props['filterOptions']['employees'][0]));
    }

    #[Test]
    public function a_foreign_school_filter_value_matches_nothing_and_bad_input_is_a_validation_error(): void
    {
        [$w] = $this->twoAssignments();
        [$other] = $this->twoAssignments();
        $viewer = $this->createUserWithCapabilities($w['school'], ['teaching.assignments.view']);

        $data = $this->actor($w, $viewer)->get('/app/teaching-assignments?employee_id='.$other['employee']->id)
            ->assertOk()->viewData('page')['props']['assignments']['data'];
        $this->assertSame([], $data);

        $this->actor($w, $viewer)->get('/app/teaching-assignments?section_id=nope')->assertSessionHasErrors('section_id');
    }

    #[Test]
    public function without_the_capability_the_filtered_list_is_forbidden(): void
    {
        [$w] = $this->twoAssignments();
        $stranger = $this->createUserWithCapabilities($w['school'], ['hr.employees.view']);

        $this->actor($w, $stranger)->get('/app/teaching-assignments?employee_id='.$w['employee']->id)->assertForbidden();
    }

    #[Test]
    public function the_employee_profile_links_to_their_assignments_only_for_assignment_viewers(): void
    {
        [$w] = $this->twoAssignments();
        // The profile page itself needs hr.employees.personal.view (its own gate).
        $hrOnly = $this->createUserWithCapabilities($w['school'], ['hr.employees.view', 'hr.employees.personal.view']);
        $both = $this->createUserWithCapabilities($w['school'], ['hr.employees.view', 'hr.employees.personal.view', 'teaching.assignments.view']);
        $url = '/app/hr/employees/'.$w['employee']->id;

        $this->assertFalse($this->actor($w, $hrOnly)->get($url)->assertOk()->viewData('page')['props']['can']['viewTeachingAssignments']);
        $this->assertTrue($this->actor($w, $both)->get($url)->assertOk()->viewData('page')['props']['can']['viewTeachingAssignments']);
    }
}
