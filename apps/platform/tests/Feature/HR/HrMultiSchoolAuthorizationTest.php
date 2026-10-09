<?php

namespace Tests\Feature\HR;

use App\Domain\HR\Application\EmployeeDirectoryQuery;
use App\Domain\HR\Application\EmployeeDirectoryService;
use App\Domain\HR\Application\EmployeeProfileWorkspaceService;
use App\Domain\HR\Infrastructure\Employee;
use App\Models\Role;
use App\Support\Authorization\CapabilityResolver;
use App\Support\Tenancy\TenantContext;
use App\Support\Testing\LocalCatalogueFixtures;
use Illuminate\Auth\Access\AuthorizationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 8A.10 -- REQUIRED multi-School authorization proof (checkpoint
 * brief section 29/54): the SAME User, with an HR-capable role at
 * School A and only an ordinary (no-capability) membership at School
 * B, must be allowed at A and denied at B. Also proves the RLS-vs-RBAC
 * and RBAC-vs-RLS separations explicitly (sections 31/32): a
 * capability grant in one School never overrides tenant isolation for
 * another School's row, and real RLS-visible same-School data is still
 * denied without the capability.
 */
class HrMultiSchoolAuthorizationTest extends TestCase
{
    use CreatesTenancyFixtures;

    #[Test]
    public function the_same_user_is_authorized_in_school_a_and_denied_in_school_b(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $user = $this->createUser();

        $membershipA = $this->createMembership($user, $schoolA);
        $role = LocalCatalogueFixtures::asOwner(fn () => Role::query()->create(['key' => 'test.hr.'.uniqid(), 'name' => 'Test HR', 'scope' => 'school', 'is_system' => false]));
        LocalCatalogueFixtures::asOwner(fn () => $role->capabilities()->sync(['hr.employees.view']));
        $this->assignSchoolRole($membershipA, $role->key);

        $this->createMembership($user, $schoolB); // ordinary member, no role

        $employeeA = $this->createEmployee($schoolA);

        // Allowed in School A.
        $resultA = app(EmployeeDirectoryService::class)->search($schoolA, new EmployeeDirectoryQuery, $user);
        $this->assertCount(1, $resultA->items());
        $this->assertSame($employeeA->id, $resultA->items()[0]->employeeId);

        // Denied in School B, same User identity.
        $this->expectException(AuthorizationException::class);
        app(EmployeeDirectoryService::class)->search($schoolB, new EmployeeDirectoryQuery, $user);
    }

    #[Test]
    public function a_capability_grant_in_school_a_cannot_reach_a_school_b_employee(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $actor = $this->createUserWithCapabilities($schoolA, ['hr.employees.personal.view']);
        $employeeB = $this->createEmployee($schoolB);

        // Authorized in School A, but the target Employee belongs to
        // School B -- the workspace resolves the Employee scoped by
        // $schoolA (the trusted, capability-checked School), so this
        // must return null, never School B's data and never a
        // distinguishing error.
        $result = app(EmployeeProfileWorkspaceService::class)->build($schoolA, $employeeB->id, $actor);

        $this->assertNull($result);
    }

    #[Test]
    public function real_time_tenant_context_visibility_is_not_mistaken_for_authorization(): void
    {
        // Proves RLS != RBAC (section 31): a User has a real, ACTIVE
        // membership at $school (so RLS/TenantContext would happily
        // show them $school's rows), but holds no hr.* capability at
        // all -- the HR application operation must still be denied.
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $member = $this->createUserWithCapabilities($school, []);

        app(TenantContext::class)->set($school);
        $this->assertTrue(
            Employee::query()->where('id', $employee->id)->exists(),
            'Sanity check: RLS genuinely permits this School\'s own row to be visible.',
        );

        $this->expectException(AuthorizationException::class);
        app(EmployeeProfileWorkspaceService::class)->build($school, $employee->id, $member);
    }

    #[Test]
    public function a_capability_in_one_school_never_satisfies_the_resolver_for_another_school(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $user = $this->createUser();

        $membershipA = $this->createMembership($user, $schoolA);
        $role = LocalCatalogueFixtures::asOwner(fn () => Role::query()->create(['key' => 'test.hr.'.uniqid(), 'name' => 'Test HR', 'scope' => 'school', 'is_system' => false]));
        LocalCatalogueFixtures::asOwner(fn () => $role->capabilities()->sync(['hr.employees.manage']));
        $this->assignSchoolRole($membershipA, $role->key);

        $resolver = app(CapabilityResolver::class);

        $this->assertTrue($resolver->canInSchool($user, 'hr.employees.manage', $schoolA));
        $this->assertFalse($resolver->canInSchool($user, 'hr.employees.manage', $schoolB));
    }
}
