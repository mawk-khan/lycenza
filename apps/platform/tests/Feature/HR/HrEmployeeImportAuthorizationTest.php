<?php

namespace Tests\Feature\HR;

use App\Domain\HR\Application\EmployeeImportService;
use App\Domain\HR\Infrastructure\Employee;
use App\Models\Role;
use App\Models\School;
use App\Support\Tenancy\TenantContext;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 8A.12 -- REQUIRED authorization proof (checkpoint brief
 * section 74). No new capability was created -- import reuses
 * `hr.employees.manage`/`.personal.manage`/`.assignments.manage`
 * exactly as they already gate interactive creation of the same data.
 */
class HrEmployeeImportAuthorizationTest extends TestCase
{
    use CreatesTenancyFixtures;

    #[Test]
    public function an_ordinary_member_is_denied_import(): void
    {
        $school = $this->createSchool();
        $ordinaryMember = $this->createUserWithCapabilities($school, []);

        $result = app(EmployeeImportService::class)->import($school, $ordinaryMember, [
            ['full_name' => 'Asha Verma'],
        ]);

        $this->assertSame('failed', $result->rows[0]->status);
        $this->assertSame('authorization', $result->rows[0]->errors[0]['code']);
        $this->assertSame(0, $result->created);
    }

    #[Test]
    public function an_actor_with_employees_manage_can_import_a_core_only_row(): void
    {
        $school = $this->createSchool();
        $actor = $this->createUserWithCapabilities($school, ['hr.employees.manage']);

        $result = app(EmployeeImportService::class)->import($school, $actor, [
            ['full_name' => 'Asha Verma'],
        ]);

        $this->assertSame('created', $result->rows[0]->status);
        $this->assertSame(1, $result->created);
    }

    #[Test]
    public function the_same_user_is_authorized_in_school_a_and_denied_in_school_b(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $user = $this->createUser();
        $membershipA = $this->createMembership($user, $schoolA);
        $role = Role::query()->create(['key' => 'test.import.'.uniqid(), 'name' => 'Test', 'scope' => 'school', 'is_system' => false]);
        $role->capabilities()->sync(['hr.employees.manage']);
        $this->assignSchoolRole($membershipA, $role->key);
        $this->createMembership($user, $schoolB);

        $resultA = app(EmployeeImportService::class)->import($schoolA, $user, [['full_name' => 'Asha Verma']]);
        $this->assertSame('created', $resultA->rows[0]->status);

        $resultB = app(EmployeeImportService::class)->import($schoolB, $user, [['full_name' => 'Rahul Nair']]);
        $this->assertSame('failed', $resultB->rows[0]->status);
        $this->assertSame('authorization', $resultB->rows[0]->errors[0]['code']);
    }

    #[Test]
    public function personal_fields_require_personal_manage_even_when_employees_manage_is_held(): void
    {
        $school = $this->createSchool();
        $actor = $this->createUserWithCapabilities($school, ['hr.employees.manage']);

        $result = app(EmployeeImportService::class)->import($school, $actor, [
            ['full_name' => 'Asha Verma', 'personal_email' => 'asha@example.com'],
        ]);

        $this->assertSame('failed', $result->rows[0]->status);
        $this->assertSame('authorization', $result->rows[0]->errors[0]['code']);
    }

    #[Test]
    public function a_row_with_partial_authorization_fails_before_any_mutation_rather_than_creating_a_partial_employee(): void
    {
        $school = $this->createSchool();
        $actor = $this->createUserWithCapabilities($school, ['hr.employees.manage']);

        app(EmployeeImportService::class)->import($school, $actor, [
            ['full_name' => 'Asha Verma', 'personal_email' => 'asha@example.com'],
        ]);

        $this->assertSame(0, $this->employeeCount($school));
    }

    #[Test]
    public function employment_fields_require_assignments_manage(): void
    {
        $school = $this->createSchool();
        $actor = $this->createUserWithCapabilities($school, ['hr.employees.manage']);

        $result = app(EmployeeImportService::class)->import($school, $actor, [
            ['full_name' => 'Asha Verma', 'employment_type' => 'permanent', 'employment_starts_on' => '2026-01-01'],
        ]);

        $this->assertSame('failed', $result->rows[0]->status);
        $this->assertSame('authorization', $result->rows[0]->errors[0]['code']);
    }

    #[Test]
    public function full_authorization_allows_a_complete_row(): void
    {
        $school = $this->createSchool();
        $actor = $this->fullHrActor($school);
        $position = $this->createPosition($school);

        $result = app(EmployeeImportService::class)->import($school, $actor, [
            [
                'full_name' => 'Asha Verma', 'personal_email' => 'asha@example.com',
                'employment_type' => 'permanent', 'employment_starts_on' => '2026-01-01',
                'position_code' => $position->code,
            ],
        ]);

        $this->assertSame('created', $result->rows[0]->status);
    }

    #[Test]
    public function manager_relationship_alone_grants_no_import_authorization(): void
    {
        $school = $this->createSchool();
        $managerUser = $this->createUser();
        $this->createMembership($managerUser, $school);

        $result = app(EmployeeImportService::class)->import($school, $managerUser, [
            ['full_name' => 'Asha Verma'],
        ]);

        $this->assertSame('failed', $result->rows[0]->status);
    }

    private function employeeCount(School $school): int
    {
        return app(TenantContext::class)->withSchool(
            $school,
            fn () => Employee::query()->where('school_id', $school->id)->count(),
        );
    }
}
