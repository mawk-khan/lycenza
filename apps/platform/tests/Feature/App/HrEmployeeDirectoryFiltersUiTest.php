<?php

namespace Tests\Feature\App;

use App\Domain\HR\Infrastructure\Employee;
use App\Models\School;
use App\Models\User;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * V11.2 (ADR 0075): the web Employee directory exposes the filters
 * EmployeeDirectoryService already supports (campus, department,
 * position, allow-listed sort). Department/Position option lists follow
 * their own view capabilities; every filter stays inside the School.
 */
class HrEmployeeDirectoryFiltersUiTest extends TestCase
{
    use CreatesTenancyFixtures;

    private function activate(User $user, School $school): void
    {
        $this->actingAs($user)->post("/app/schools/{$school->id}/activate");
    }

    /** An Employee with a current primary assignment at the given campus/department/position. */
    private function placedEmployee(School $school, string $name, string $number, array $placement): Employee
    {
        $employee = $this->createEmployee($school, ['full_name' => $name, 'employee_number' => $number]);
        $employment = $this->createEmploymentRecord($employee, ['starts_on' => '2020-01-01', 'ends_on' => null]);
        $this->createEmployeeAssignment($employment, $placement['position'], [
            'campus_id' => $placement['campus']->id,
            'department_id' => $placement['department']->id,
            'is_primary' => true,
            'starts_on' => '2020-01-01',
            'ends_on' => null,
        ]);

        return $employee;
    }

    /** @return array{school: School, a: array<string, mixed>, b: array<string, mixed>} */
    private function twoPlacements(): array
    {
        $school = $this->createSchool();
        $a = [
            'campus' => $this->createCampus($school, ['name' => 'North Campus']),
            'department' => $this->createDepartment($school, ['name' => 'Science', 'campus_id' => null]),
            'position' => $this->createPosition($school, ['name' => 'Teacher']),
        ];
        $b = [
            'campus' => $this->createCampus($school, ['name' => 'South Campus']),
            'department' => $this->createDepartment($school, ['name' => 'Office', 'campus_id' => null]),
            'position' => $this->createPosition($school, ['name' => 'Clerk']),
        ];

        return compact('school', 'a', 'b');
    }

    /** @return array<string, mixed> */
    private function props(string $uri): array
    {
        return $this->get($uri)->assertOk()->viewData('page')['props'];
    }

    /** @return list<string> */
    private function names(string $uri): array
    {
        return array_column($this->props($uri)['employees']['data'], 'display_name');
    }

    #[Test]
    public function campus_department_and_position_filters_narrow_the_directory_and_combine(): void
    {
        ['school' => $school, 'a' => $a, 'b' => $b] = $this->twoPlacements();
        $this->placedEmployee($school, 'Asha Rao', 'EMP-000101', $a);
        $this->placedEmployee($school, 'Bilal Khan', 'EMP-000102', $b);
        $user = $this->createUserWithCapabilities($school, ['hr.employees.view', 'hr.departments.view', 'hr.positions.view']);
        $this->activate($user, $school);

        $this->assertSame(['Asha Rao'], $this->names('/app/hr/employees?campus_id='.$a['campus']->id));
        $this->assertSame(['Bilal Khan'], $this->names('/app/hr/employees?department_id='.$b['department']->id));
        $this->assertSame([], $this->names('/app/hr/employees?position_id='.$a['position']->id.'&campus_id='.$b['campus']->id), 'Filters combine (AND).');
        $this->assertSame(['Bilal Khan'], $this->names('/app/hr/employees?position_id='.$b['position']->id.'&campus_id='.$b['campus']->id));
    }

    #[Test]
    public function the_allow_listed_sort_is_applied_and_echoed_and_filters_survive_pagination_links(): void
    {
        ['school' => $school, 'a' => $a] = $this->twoPlacements();
        $this->placedEmployee($school, 'Asha Rao', 'EMP-000201', $a);
        $this->placedEmployee($school, 'Zoya Mir', 'EMP-000200', $a);
        $user = $this->createUserWithCapabilities($school, ['hr.employees.view']);
        $this->activate($user, $school);

        $props = $this->props('/app/hr/employees?sort=employee_number&direction=asc&campus_id='.$a['campus']->id);
        $this->assertSame(['Zoya Mir', 'Asha Rao'], array_column($props['employees']['data'], 'display_name'));
        $this->assertSame('employee_number', $props['filters']['sort']);
        $this->assertSame('asc', $props['filters']['direction']);
        $this->assertSame($a['campus']->id, $props['filters']['campus_id']);
        $this->assertSame(2, $props['employees']['total']);

        $this->assertSame(['Zoya Mir', 'Asha Rao'], $this->names('/app/hr/employees?sort=full_name&direction=desc'));
    }

    #[Test]
    public function invalid_filter_input_is_rejected_cleanly_never_a_500(): void
    {
        $school = $this->createSchool();
        $user = $this->createUserWithCapabilities($school, ['hr.employees.view']);
        $this->activate($user, $school);

        foreach (['campus_id=not-a-uuid', 'department_id=1', 'position_id=x', 'sort=salary', 'direction=sideways'] as $query) {
            $this->get('/app/hr/employees?'.$query)->assertSessionHasErrors();
        }
    }

    #[Test]
    public function department_and_position_options_follow_their_own_view_capabilities(): void
    {
        ['school' => $school] = $this->twoPlacements();

        $directoryOnly = $this->createUserWithCapabilities($school, ['hr.employees.view']);
        $this->activate($directoryOnly, $school);
        $props = $this->props('/app/hr/employees');
        $this->assertNull($props['departmentOptions']);
        $this->assertNull($props['positionOptions']);
        $this->assertCount(2, $props['campusOptions']);

        $orgViewer = $this->createUserWithCapabilities($school, ['hr.employees.view', 'hr.departments.view', 'hr.positions.view']);
        $this->activate($orgViewer, $school);
        $props = $this->props('/app/hr/employees');
        $this->assertSame(['Office', 'Science'], array_column($props['departmentOptions'], 'name'));
        $this->assertSame(['Clerk', 'Teacher'], array_column($props['positionOptions'], 'name'));
    }

    #[Test]
    public function another_schools_campus_never_matches_and_its_options_never_appear(): void
    {
        ['school' => $school, 'a' => $a] = $this->twoPlacements();
        $this->placedEmployee($school, 'Asha Rao', 'EMP-000301', $a);
        $other = $this->twoPlacements();
        $this->placedEmployee($other['school'], 'Other School Person', 'EMP-000302', $other['a']);

        $user = $this->createUserWithCapabilities($school, ['hr.employees.view', 'hr.departments.view', 'hr.positions.view']);
        $this->activate($user, $school);

        $this->assertSame([], $this->names('/app/hr/employees?campus_id='.$other['a']['campus']->id));

        $props = $this->props('/app/hr/employees');
        $this->assertSame(['Asha Rao'], array_column($props['employees']['data'], 'display_name'));
        $ids = array_merge(
            array_column($props['campusOptions'], 'id'),
            array_column($props['departmentOptions'], 'id'),
            array_column($props['positionOptions'], 'id'),
        );
        $this->assertNotContains($other['a']['campus']->id, $ids);
        $this->assertNotContains($other['a']['department']->id, $ids);
        $this->assertNotContains($other['a']['position']->id, $ids);
    }

    #[Test]
    public function without_hr_employees_view_the_directory_is_forbidden(): void
    {
        $school = $this->createSchool();
        $user = $this->createUserWithCapabilities($school, ['hr.departments.view']);
        $this->activate($user, $school);

        $this->get('/app/hr/employees?campus_id='.$this->createCampus($school)->id)->assertForbidden();
    }
}
