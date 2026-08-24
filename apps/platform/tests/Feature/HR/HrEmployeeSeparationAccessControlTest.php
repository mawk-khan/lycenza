<?php

namespace Tests\Feature\HR;

use App\Domain\HR\Application\EmployeeLifecycleService;
use App\Domain\HR\Application\EmploymentService;
use App\Models\MembershipRoleAssignment;
use App\Models\PlatformRoleAssignment;
use App\Models\Role;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 8A.13 -- REQUIRED proof that Employment lifecycle (separation
 * AND rehire) never mutates access-control state (checkpoint brief
 * sections 7/55/56): a separated Employee's User account, School
 * membership, and role/capability grants are completely untouched --
 * "the UI hides the button" is never how account access is revoked,
 * and HR lifecycle never does it implicitly either. Rehire's identical
 * non-side-effect is proven in HrEmployeeRehireTest.
 */
class HrEmployeeSeparationAccessControlTest extends TestCase
{
    use CreatesTenancyFixtures;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    #[Test]
    public function separation_never_touches_the_linked_users_account_or_membership(): void
    {
        Carbon::setTestNow('2026-06-15');
        $school = $this->createSchool();
        $linkedUser = $this->createUser();
        $membership = $this->createMembership($linkedUser, $school, 'active');
        $employee = $this->createEmployee($school, ['user_id' => $linkedUser->id]);
        $actor = $this->fullHrActor($school);
        $employment = app(EmploymentService::class)->create($employee, ['employment_type' => 'permanent', 'starts_on' => '2022-01-01'], $actor);

        app(EmployeeLifecycleService::class)->separate($employment, '2026-06-15', $actor);

        $this->assertNotNull($linkedUser->fresh(), 'The User row must still exist.');
        $this->assertSame('active', $membership->fresh()->status, 'School membership status must be completely untouched by separation.');

        app(TenantContext::class)->set($school);
        $this->assertSame($linkedUser->id, $employee->fresh()->user_id, 'The Employee/User link is never automatically cleared by separation.');
    }

    #[Test]
    public function the_full_separation_operation_never_touches_an_authorization_table(): void
    {
        Carbon::setTestNow('2026-06-15');
        $school = $this->createSchool();
        $linkedUser = $this->createUser();
        $this->createMembership($linkedUser, $school, 'active');
        $employee = $this->createEmployee($school, ['user_id' => $linkedUser->id]);
        // Created BEFORE the baseline counts below -- fullHrActor()'s
        // own capability grant is test SETUP, not part of what this
        // test measures (mirrors PositionTest's identical pattern).
        $actor = $this->fullHrActor($school);
        $employment = app(EmploymentService::class)->create($employee, ['employment_type' => 'permanent', 'starts_on' => '2022-01-01'], $actor);

        app(TenantContext::class)->set($school);
        $rolesBefore = Role::query()->count();
        $membershipRoleAssignmentsBefore = MembershipRoleAssignment::query()->count();
        $platformRoleAssignmentsBefore = PlatformRoleAssignment::query()->count();

        app(EmployeeLifecycleService::class)->separate($employment, '2026-06-15', $actor);

        app(TenantContext::class)->set($school);
        $this->assertSame($rolesBefore, Role::query()->count(), 'Separation must never create/delete a Role.');
        $this->assertSame($membershipRoleAssignmentsBefore, MembershipRoleAssignment::query()->count(), 'Separation must never create/remove a MembershipRoleAssignment.');
        $this->assertSame($platformRoleAssignmentsBefore, PlatformRoleAssignment::query()->count(), 'Separation must never create/remove a PlatformRoleAssignment.');
    }

    #[Test]
    public function separation_does_not_auto_suspend_a_still_active_membership(): void
    {
        Carbon::setTestNow('2026-06-15');
        $school = $this->createSchool();
        $linkedUser = $this->createUser();
        $membership = $this->createMembership($linkedUser, $school, 'active');
        $employee = $this->createEmployee($school, ['user_id' => $linkedUser->id]);
        $actor = $this->fullHrActor($school);
        $employment = app(EmploymentService::class)->create($employee, ['employment_type' => 'permanent', 'starts_on' => '2022-01-01'], $actor);

        app(EmployeeLifecycleService::class)->separate($employment, '2026-06-15', $actor, 'terminated');

        $this->assertSame('active', $membership->fresh()->status);
    }

    #[Test]
    public function a_membership_already_suspended_for_unrelated_reasons_is_unaffected_by_separation(): void
    {
        Carbon::setTestNow('2026-06-15');
        $school = $this->createSchool();
        $linkedUser = $this->createUser();
        $membership = $this->createMembership($linkedUser, $school, 'suspended');
        $employee = $this->createEmployee($school, ['user_id' => $linkedUser->id]);
        $actor = $this->fullHrActor($school);
        $employment = app(EmploymentService::class)->create($employee, ['employment_type' => 'permanent', 'starts_on' => '2022-01-01'], $actor);

        app(EmployeeLifecycleService::class)->separate($employment, '2026-06-15', $actor);

        $this->assertSame('suspended', $membership->fresh()->status, 'Separation neither causes nor lifts a membership suspension.');
    }

    #[Test]
    public function separation_of_an_employee_with_no_linked_user_succeeds_normally(): void
    {
        Carbon::setTestNow('2026-06-15');
        $school = $this->createSchool();
        $employee = $this->createEmployee($school, ['user_id' => null]);
        $actor = $this->fullHrActor($school);
        $employment = app(EmploymentService::class)->create($employee, ['employment_type' => 'permanent', 'starts_on' => '2022-01-01'], $actor);

        $separated = app(EmployeeLifecycleService::class)->separate($employment, '2026-06-15', $actor);

        $this->assertSame('separated', $separated->status);

        app(TenantContext::class)->set($school);
        $this->assertNull($employee->fresh()->user_id);
    }
}
