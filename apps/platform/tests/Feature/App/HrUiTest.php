<?php

namespace Tests\Feature\App;

use App\Models\School;
use App\Models\User;
use PHPUnit\Framework\Assert as PHPUnit;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 8A closure correction (item 2) -- the administrative HR UI
 * (App\Http\Controllers\App\HR\*). Backend authorization/tenant-safety/
 * domain invariants are already proven by tests/Feature/HR's own suite
 * (re-run unmodified alongside this file) -- these tests cover the
 * Inertia-specific integration: page rendering, capability-aware props,
 * redirect/validation behavior, and that a hidden button is never the
 * only protection, mirroring Tests\Feature\App\FinanceUiTest's exact
 * shape.
 */
class HrUiTest extends TestCase
{
    use CreatesTenancyFixtures;

    private function activate(User $user, School $school): void
    {
        $this->actingAs($user)->post("/app/schools/{$school->id}/activate");
    }

    private function memberWith(array $capabilities, School $school): User
    {
        $user = $this->createUserWithCapabilities($school, $capabilities);
        $this->activate($user, $school);

        return $user;
    }

    // --- Hub / navigation ----------------------------------------------------

    #[Test]
    public function dashboard_nav_shows_hr_only_when_any_hr_view_capability_is_held(): void
    {
        $school = $this->createSchool();
        $viewer = $this->memberWith(['hr.employees.view'], $school);

        $this->actingAs($viewer)->get('/app')->assertInertia(fn ($page) => $page
            ->where('nav.canViewHr', true)
        );

        $withoutHr = $this->memberWith([], $school);
        $this->actingAs($withoutHr)->get('/app')->assertInertia(fn ($page) => $page
            ->where('nav.canViewHr', false)
        );
    }

    #[Test]
    public function hr_hub_hides_areas_the_user_cannot_view(): void
    {
        $school = $this->createSchool();
        $this->memberWith(['hr.positions.view'], $school);

        $this->get('/app/hr')->assertInertia(fn ($page) => $page
            ->component('App/HR/Index')
            ->where('can.viewPositions', true)
            ->where('can.viewEmployees', false)
            ->where('can.viewDepartments', false)
            ->where('can.viewCategories', false)
        );
    }

    // --- Employee directory --------------------------------------------------

    #[Test]
    public function employees_index_requires_hr_employees_view(): void
    {
        $school = $this->createSchool();
        $this->memberWith([], $school);

        $this->get('/app/hr/employees')->assertForbidden();
    }

    #[Test]
    public function employees_index_lists_employees_and_reflects_search_filter(): void
    {
        $school = $this->createSchool();
        $this->createEmployee($school, ['full_name' => 'Alexandra Fernandes']);
        $this->createEmployee($school, ['full_name' => 'Priya Nair']);
        $this->memberWith(['hr.employees.view'], $school);

        $this->get('/app/hr/employees?search=Fernandes')->assertInertia(fn ($page) => $page
            ->component('App/HR/Employees/Index')
            ->has('employees.data', 1)
            ->where('employees.data.0.display_name', 'Alexandra Fernandes')
            ->where('filters.search', 'Fernandes')
        );
    }

    #[Test]
    public function employees_index_shows_canmanage_false_for_a_view_only_member_and_hides_mutation_routes(): void
    {
        $school = $this->createSchool();
        $this->memberWith(['hr.employees.view'], $school);

        $this->get('/app/hr/employees')->assertInertia(fn ($page) => $page
            ->where('canManage', false)
        );

        $this->get('/app/hr/employees/create')->assertForbidden();
        $this->post('/app/hr/employees', [])->assertForbidden();
    }

    #[Test]
    public function hr_employees_manage_can_create_an_employee(): void
    {
        $school = $this->createSchool();
        $this->memberWith(['hr.employees.manage', 'hr.employees.personal.view'], $school);

        $response = $this->post('/app/hr/employees', [
            'full_name' => 'Alexandra Fernandes',
            'work_email' => 'alexandra@example.com',
        ]);

        $response->assertRedirect();
        $this->assertStringContainsString('/app/hr/employees/', $response->headers->get('Location'));

        $employeeId = str($response->headers->get('Location'))->afterLast('/')->toString();

        $this->get("/app/hr/employees/{$employeeId}")->assertInertia(fn ($page) => $page
            ->component('App/HR/Employees/Show')
            ->where('workspace.summary.display_name', 'Alexandra Fernandes')
        );
    }

    #[Test]
    public function duplicate_work_email_shows_a_field_error(): void
    {
        $school = $this->createSchool();
        $this->createEmployee($school, ['work_email' => 'taken@example.com']);
        $this->memberWith(['hr.employees.manage'], $school);

        $response = $this->post('/app/hr/employees', [
            'full_name' => 'Someone Else',
            'work_email' => 'taken@example.com',
        ]);

        $response->assertSessionHasErrors('work_email');
    }

    // --- Employee profile workspace -------------------------------------------

    #[Test]
    public function employee_show_requires_hr_employees_personal_view(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $this->memberWith(['hr.employees.view'], $school);

        $this->get("/app/hr/employees/{$employee->id}")->assertForbidden();
    }

    #[Test]
    public function employee_show_is_a_uniform_404_for_a_cross_school_id(): void
    {
        $school = $this->createSchool();
        $otherSchool = $this->createSchool();
        $foreignEmployee = $this->createEmployee($otherSchool);
        $this->memberWith(['hr.employees.personal.view'], $school);

        $this->get("/app/hr/employees/{$foreignEmployee->id}")->assertNotFound();
    }

    #[Test]
    public function employee_show_exposes_capability_aware_can_flags(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $this->memberWith(['hr.employees.personal.view'], $school);

        $this->get("/app/hr/employees/{$employee->id}")->assertInertia(fn ($page) => $page
            ->where('can.managePersonal', false)
            ->where('can.manageAssignments', false)
        );
    }

    #[Test]
    public function hr_employees_personal_manage_can_set_personal_details(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $this->memberWith(['hr.employees.personal.view', 'hr.employees.personal.manage'], $school);

        $response = $this->put("/app/hr/employees/{$employee->id}/personal-detail", [
            'date_of_birth' => '1990-01-01',
            'nationality' => 'Indian',
        ]);

        $response->assertRedirect("/app/hr/employees/{$employee->id}");

        $this->get("/app/hr/employees/{$employee->id}")->assertInertia(fn ($page) => $page
            ->where('workspace.personal_details.nationality', 'Indian')
        );
    }

    #[Test]
    public function hr_employees_personal_manage_can_add_and_remove_an_address(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $this->memberWith(['hr.employees.personal.view', 'hr.employees.personal.manage'], $school);

        $this->post("/app/hr/employees/{$employee->id}/addresses", [
            'address_type' => 'current',
            'address_line1' => '221B Baker Street',
        ])->assertRedirect();

        $response = $this->get("/app/hr/employees/{$employee->id}");
        $response->assertInertia(fn ($page) => $page->has('workspace.addresses', 1));
        $addressId = $response->viewData('page')['props']['workspace']['addresses'][0]['id'];

        $this->delete("/app/hr/employees/{$employee->id}/addresses/{$addressId}")->assertRedirect();

        $this->get("/app/hr/employees/{$employee->id}")->assertInertia(fn ($page) => $page
            ->has('workspace.addresses', 0)
        );
    }

    #[Test]
    public function hr_employees_notes_manage_can_add_and_remove_a_note(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $this->memberWith(['hr.employees.personal.view', 'hr.employees.notes.view', 'hr.employees.notes.manage'], $school);

        $this->post("/app/hr/employees/{$employee->id}/notes", [
            'body' => 'Discussed onboarding.',
        ])->assertRedirect();

        $this->get("/app/hr/employees/{$employee->id}")->assertInertia(fn ($page) => $page
            ->has('workspace.notes', 1)
            ->where('workspace.notes.0.body', 'Discussed onboarding.')
        );
    }

    #[Test]
    public function notes_are_absent_from_the_ui_without_notes_view(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $this->createEmployeeNote($employee);
        $this->memberWith(['hr.employees.personal.view'], $school);

        $this->get("/app/hr/employees/{$employee->id}")->assertInertia(fn ($page) => $page
            ->has('workspace.notes', 0)
        );
    }

    #[Test]
    public function hr_employees_assignments_manage_can_create_employment_and_an_assignment(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $position = $this->createPosition($school);
        $this->memberWith([
            'hr.employees.personal.view', 'hr.employees.assignments.view', 'hr.employees.assignments.manage',
        ], $school);

        $this->post("/app/hr/employees/{$employee->id}/employment-records", [
            'employment_type' => 'full_time',
            'starts_on' => '2026-06-01',
        ])->assertRedirect();

        $response = $this->get("/app/hr/employees/{$employee->id}");
        $employmentId = $response->viewData('page')['props']['workspace']['employment_history'][0]['id'];

        $this->post("/app/hr/employees/{$employee->id}/employment-records/{$employmentId}/assignments", [
            'starts_on' => '2026-06-01',
            'position_id' => $position->id,
        ])->assertRedirect();

        $this->get("/app/hr/employees/{$employee->id}")->assertInertia(fn ($page) => $page
            ->has('workspace.employment_history', 1)
            ->has('workspace.assignments', 1)
            ->where('workspace.assignments.0.position_name', $position->name)
        );
    }

    #[Test]
    public function employee_archive_and_restore_round_trip(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $this->memberWith(['hr.employees.view', 'hr.employees.manage', 'hr.employees.personal.view'], $school);

        $this->post("/app/hr/employees/{$employee->id}/archive")->assertRedirect();
        $this->get("/app/hr/employees/{$employee->id}")->assertInertia(fn ($page) => $page
            ->where('workspace.summary.employee_record_status', 'archived')
        );

        $this->post("/app/hr/employees/{$employee->id}/restore")->assertRedirect();
        $this->get("/app/hr/employees/{$employee->id}")->assertInertia(fn ($page) => $page
            ->where('workspace.summary.employee_record_status', 'active')
        );
    }

    // --- Reference data: Departments/Positions/Categories ---------------------

    #[Test]
    public function departments_index_requires_hr_departments_view(): void
    {
        $school = $this->createSchool();
        $this->memberWith([], $school);

        $this->get('/app/hr/departments')->assertForbidden();
    }

    #[Test]
    public function hr_departments_manage_can_create_archive_and_reactivate_a_department(): void
    {
        $school = $this->createSchool();
        $this->memberWith(['hr.departments.view', 'hr.departments.manage'], $school);

        $this->post('/app/hr/departments', ['name' => 'Finance', 'code' => 'fin'])->assertRedirect();

        $response = $this->get('/app/hr/departments');
        $departments = $response->viewData('page')['props']['departments'];
        PHPUnit::assertSame('FIN', $departments[0]['code']);
        $departmentId = $departments[0]['id'];

        $this->post("/app/hr/departments/{$departmentId}/archive")->assertRedirect();
        $this->get('/app/hr/departments')->assertInertia(fn ($page) => $page
            ->where('departments.0.status', 'inactive')
        );

        $this->post("/app/hr/departments/{$departmentId}/reactivate")->assertRedirect();
        $this->get('/app/hr/departments')->assertInertia(fn ($page) => $page
            ->where('departments.0.status', 'active')
        );
    }

    #[Test]
    public function positions_index_requires_hr_positions_view(): void
    {
        $school = $this->createSchool();
        $this->memberWith([], $school);

        $this->get('/app/hr/positions')->assertForbidden();
    }

    #[Test]
    public function hr_positions_manage_can_create_a_position(): void
    {
        $school = $this->createSchool();
        $this->memberWith(['hr.positions.view', 'hr.positions.manage'], $school);

        $this->post('/app/hr/positions', ['name' => 'Teacher', 'code' => 'tch'])->assertRedirect();

        $this->get('/app/hr/positions')->assertInertia(fn ($page) => $page
            ->where('positions.0.code', 'TCH')
        );
    }

    #[Test]
    public function categories_index_requires_hr_categories_view(): void
    {
        $school = $this->createSchool();
        $this->memberWith([], $school);

        $this->get('/app/hr/categories')->assertForbidden();
    }

    #[Test]
    public function hr_categories_manage_can_create_a_category(): void
    {
        $school = $this->createSchool();
        $this->memberWith(['hr.categories.view', 'hr.categories.manage'], $school);

        $this->post('/app/hr/categories', ['name' => 'Teaching', 'code' => 'teach'])->assertRedirect();

        $this->get('/app/hr/categories')->assertInertia(fn ($page) => $page
            ->where('categories.0.code', 'TEACH')
        );
    }

    // --- Import ----------------------------------------------------------------

    #[Test]
    public function import_page_requires_hr_employees_manage(): void
    {
        $school = $this->createSchool();
        $this->memberWith(['hr.employees.view'], $school);

        $this->get('/app/hr/employees/import')->assertForbidden();
        $this->post('/app/hr/employees/import', ['rows_json' => '[]'])->assertForbidden();
    }

    #[Test]
    public function import_creates_employees_and_reports_the_result(): void
    {
        $school = $this->createSchool();
        $this->memberWith(['hr.employees.manage'], $school);

        $rows = json_encode([
            ['full_name' => 'Imported Employee One'],
            ['full_name' => 'Imported Employee Two'],
        ]);

        $this->post('/app/hr/employees/import', ['rows_json' => $rows])
            ->assertInertia(fn ($page) => $page
                ->component('App/HR/Import/Result')
                ->where('result.created', 2)
                ->where('result.received', 2)
            );
    }

    #[Test]
    public function import_rejects_malformed_json_with_a_field_error(): void
    {
        $school = $this->createSchool();
        $this->memberWith(['hr.employees.manage'], $school);

        $this->post('/app/hr/employees/import', ['rows_json' => 'not json'])
            ->assertSessionHasErrors('rows_json');
    }
}
