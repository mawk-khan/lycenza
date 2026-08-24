<?php

namespace Tests\Feature\HR;

use App\Domain\HR\Application\EmployeeAssignmentService;
use App\Domain\HR\Application\EmploymentService;
use App\Models\Role;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 8A.14 -- REQUIRED HTTP contract proof for the Directory API
 * (`GET /api/v1/schools/{school}/employees`), checkpoint brief section
 * 72. Exercises the real Sanctum-authenticated HTTP path, not just the
 * underlying `EmployeeDirectoryService` (already proven in 8A.8/8A.10's
 * own service-level tests, not repeated here).
 */
class HrEmployeeDirectoryApiTest extends TestCase
{
    use CreatesTenancyFixtures;

    private function token($user): string
    {
        return $user->createToken('test-device')->plainTextToken;
    }

    #[Test]
    public function a_guest_is_denied(): void
    {
        $school = $this->createSchool();

        $this->getJson("/api/v1/schools/{$school->id}/employees")->assertUnauthorized();
    }

    #[Test]
    public function an_ordinary_school_member_is_denied(): void
    {
        $school = $this->createSchool();
        $member = $this->createUserWithCapabilities($school, []);

        $this->withHeader('Authorization', 'Bearer '.$this->token($member))
            ->getJson("/api/v1/schools/{$school->id}/employees")
            ->assertForbidden();
    }

    #[Test]
    public function a_directory_authorized_actor_is_allowed(): void
    {
        $school = $this->createSchool();
        $actor = $this->fullHrActor($school);
        $this->createEmployee($school);

        $this->withHeader('Authorization', 'Bearer '.$this->token($actor))
            ->getJson("/api/v1/schools/{$school->id}/employees")
            ->assertOk();
    }

    #[Test]
    public function the_same_user_is_allowed_in_school_a_but_denied_in_school_b(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $user = $this->createUser();
        $membershipA = $this->createMembership($user, $schoolA);
        $this->createMembership($user, $schoolB);

        $role = Role::query()->create([
            'key' => 'test.hr_view.'.Str::uuid(),
            'name' => 'Test HR View',
            'scope' => 'school',
            'is_system' => false,
        ]);
        $role->capabilities()->sync(['hr.employees.view']);
        $this->assignSchoolRole($membershipA, $role->key);

        $token = $this->token($user);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson("/api/v1/schools/{$schoolA->id}/employees")
            ->assertOk();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson("/api/v1/schools/{$schoolB->id}/employees")
            ->assertForbidden();
    }

    #[Test]
    public function the_directory_entry_has_exactly_the_accepted_twelve_keys(): void
    {
        $school = $this->createSchool();
        $actor = $this->fullHrActor($school);
        $employee = $this->createEmployee($school);
        $position = $this->createPosition($school);
        $department = $this->createDepartment($school);
        $employment = app(EmploymentService::class)->create($employee, ['employment_type' => 'permanent', 'starts_on' => '2022-01-01'], $actor);
        $assignment = app(EmployeeAssignmentService::class)->create($employment, ['starts_on' => '2022-01-01'], $position, $actor, department: $department);
        app(EmployeeAssignmentService::class)->setPrimary($assignment, $actor);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token($actor))
            ->getJson("/api/v1/schools/{$school->id}/employees")
            ->assertOk();

        $entry = collect($response->json('data'))->firstWhere('employee_id', $employee->id);
        $this->assertNotNull($entry);
        $this->assertSame([
            'employee_id', 'employee_number', 'display_name',
            'position_id', 'position_name',
            'department_id', 'department_name',
            'campus_id', 'campus_name',
            'manager_employee_id', 'manager_employee_number', 'manager_display_name',
        ], array_keys($entry));
    }

    #[Test]
    public function search_by_employee_number_works(): void
    {
        $school = $this->createSchool();
        $actor = $this->fullHrActor($school);
        $employee = $this->createEmployee($school);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token($actor))
            ->getJson("/api/v1/schools/{$school->id}/employees?search=".$employee->employee_number)
            ->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertSame($employee->id, $response->json('data.0.employee_id'));
    }

    #[Test]
    public function search_by_name_works(): void
    {
        $school = $this->createSchool();
        $actor = $this->fullHrActor($school);
        $employee = $this->createEmployee($school, ['full_name' => 'Distinctive Search Target']);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token($actor))
            ->getJson("/api/v1/schools/{$school->id}/employees?search=Distinctive")
            ->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertSame($employee->id, $response->json('data.0.employee_id'));
    }

    #[Test]
    public function the_position_filter_narrows_results(): void
    {
        $school = $this->createSchool();
        $actor = $this->fullHrActor($school);
        $positionA = $this->createPosition($school, ['code' => 'FILT-A']);
        $positionB = $this->createPosition($school, ['code' => 'FILT-B']);
        $employeeA = $this->createEmployee($school);
        $employeeB = $this->createEmployee($school);
        $employmentA = app(EmploymentService::class)->create($employeeA, ['employment_type' => 'permanent', 'starts_on' => '2022-01-01'], $actor);
        $assignmentA = app(EmployeeAssignmentService::class)->create($employmentA, ['starts_on' => '2022-01-01'], $positionA, $actor);
        app(EmployeeAssignmentService::class)->setPrimary($assignmentA, $actor);
        $employmentB = app(EmploymentService::class)->create($employeeB, ['employment_type' => 'permanent', 'starts_on' => '2022-01-01'], $actor);
        $assignmentB = app(EmployeeAssignmentService::class)->create($employmentB, ['starts_on' => '2022-01-01'], $positionB, $actor);
        app(EmployeeAssignmentService::class)->setPrimary($assignmentB, $actor);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token($actor))
            ->getJson("/api/v1/schools/{$school->id}/employees?position_id={$positionA->id}")
            ->assertOk();

        $ids = collect($response->json('data'))->pluck('employee_id')->all();
        $this->assertContains($employeeA->id, $ids);
        $this->assertNotContains($employeeB->id, $ids);
    }

    #[Test]
    public function sort_by_employee_number_descending_is_respected(): void
    {
        $school = $this->createSchool();
        $actor = $this->fullHrActor($school);
        $this->createEmployee($school);
        $this->createEmployee($school);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token($actor))
            ->getJson("/api/v1/schools/{$school->id}/employees?sort=employee_number&direction=desc")
            ->assertOk();

        $numbers = collect($response->json('data'))->pluck('employee_number')->all();
        $sorted = $numbers;
        rsort($sorted);
        $this->assertSame($sorted, $numbers);
    }

    #[Test]
    public function a_malicious_sort_value_is_safely_ignored_never_a_sql_error(): void
    {
        $school = $this->createSchool();
        $actor = $this->fullHrActor($school);
        $this->createEmployee($school);

        $this->withHeader('Authorization', 'Bearer '.$this->token($actor))
            ->getJson("/api/v1/schools/{$school->id}/employees?sort=".urlencode('id; DROP TABLE employees;--'))
            ->assertOk();
    }

    #[Test]
    public function default_pagination_is_twenty_five_per_page(): void
    {
        $school = $this->createSchool();
        $actor = $this->fullHrActor($school);
        for ($i = 0; $i < 3; $i++) {
            $this->createEmployee($school);
        }

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token($actor))
            ->getJson("/api/v1/schools/{$school->id}/employees")
            ->assertOk();

        $this->assertSame(25, $response->json('meta.perPage'));
        $this->assertSame(3, $response->json('meta.total'));
    }

    #[Test]
    public function per_page_is_clamped_to_the_maximum_of_one_hundred(): void
    {
        $school = $this->createSchool();
        $actor = $this->fullHrActor($school);
        $this->createEmployee($school);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token($actor))
            ->getJson("/api/v1/schools/{$school->id}/employees?per_page=100000")
            ->assertOk();

        $this->assertSame(100, $response->json('meta.perPage'));
    }

    #[Test]
    public function restricted_personal_data_never_appears_in_the_directory_response(): void
    {
        $school = $this->createSchool();
        $actor = $this->fullHrActor($school);
        $employee = $this->createEmployee($school, ['full_name' => 'Directory Sentinel Person']);
        $this->createEmployeePersonalDetail($employee, [
            'personal_email' => 'sentinel-directory@example.com',
            'date_of_birth' => '1990-01-01',
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token($actor))
            ->getJson("/api/v1/schools/{$school->id}/employees")
            ->assertOk();

        $raw = $response->getContent();
        $this->assertStringNotContainsString('sentinel-directory@example.com', $raw);
        $this->assertStringNotContainsString('1990-01-01', $raw);
        $this->assertStringNotContainsString('date_of_birth', $raw);
        $this->assertStringNotContainsString('personal_email', $raw);
    }

    #[Test]
    public function a_cross_school_employee_never_appears_in_the_directory(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $actorA = $this->fullHrActor($schoolA);
        $employeeB = $this->createEmployee($schoolB);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token($actorA))
            ->getJson("/api/v1/schools/{$schoolA->id}/employees")
            ->assertOk();

        $ids = collect($response->json('data'))->pluck('employee_id')->all();
        $this->assertNotContains($employeeB->id, $ids);
    }

    #[Test]
    public function a_foreign_schools_position_id_filter_yields_zero_results_not_an_error(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $actorA = $this->fullHrActor($schoolA);
        $this->createEmployee($schoolA);
        $foreignPosition = $this->createPosition($schoolB);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token($actorA))
            ->getJson("/api/v1/schools/{$schoolA->id}/employees?position_id={$foreignPosition->id}")
            ->assertOk();

        $this->assertSame([], $response->json('data'));
    }

    #[Test]
    public function invalid_query_parameters_are_handled_safely(): void
    {
        $school = $this->createSchool();
        $actor = $this->fullHrActor($school);

        $this->withHeader('Authorization', 'Bearer '.$this->token($actor))
            ->getJson("/api/v1/schools/{$school->id}/employees?page=not-a-number")
            ->assertStatus(422);

        $this->withHeader('Authorization', 'Bearer '.$this->token($actor))
            ->getJson("/api/v1/schools/{$school->id}/employees?per_page=-5")
            ->assertStatus(422);
    }
}
