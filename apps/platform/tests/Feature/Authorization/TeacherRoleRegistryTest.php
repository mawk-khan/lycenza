<?php

namespace Tests\Feature\Authorization;

use App\Domain\HR\Infrastructure\Employee;
use App\Domain\Identity\Application\Staff\StaffAccessService;
use App\Domain\Identity\Application\Staff\StaffAccountException;
use App\Domain\Identity\Application\Staff\StaffRoleCatalog;
use App\Domain\TeachingAssignments\Infrastructure\TeachingAssignment;
use App\Models\Capability;
use App\Models\Role;
use App\Models\School;
use App\Models\User;
use App\Support\Authorization\CapabilityResolver;
use App\Support\Tenancy\TenantContext;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * TCH.3 (ADR 0063 sections 12-13, T1): the production Teacher role -- one
 * system School role, a bundle of exactly one owned-scope capability,
 * granted and revoked through the ordinary staff role path under the
 * existing no-escalation rule, and never touching HR linkage or teaching
 * ownership.
 */
class TeacherRoleRegistryTest extends TestCase
{
    use CreatesTenancyFixtures;

    private function capabilities(string $roleKey): array
    {
        return Role::query()->where('key', $roleKey)->firstOrFail()->capabilities->pluck('key')->sort()->values()->all();
    }

    #[Test]
    public function teacher_is_a_system_school_role_carrying_exactly_one_owned_scope_capability(): void
    {
        $role = Role::query()->where('key', 'teacher')->firstOrFail();

        $this->assertTrue($role->is_system);
        $this->assertSame('school', $role->scope);
        $this->assertSame(['curriculum.delivery.teacher'], $this->capabilities('teacher'));
        $this->assertSame(1, Role::query()->where('key', 'like', '%teacher%')->count(), 'One Teacher role, no second teacher-like role.');
    }

    #[Test]
    public function the_teacher_bundle_holds_no_school_wide_or_unrelated_authority(): void
    {
        $teacher = $this->capabilities('teacher');

        foreach (['curriculum.delivery.view', 'curriculum.delivery.manage', 'teaching.assignments.view', 'teaching.assignments.manage', 'syllabus.view', 'syllabus.manage'] as $key) {
            $this->assertNotContains($key, $teacher);
        }

        foreach (['attendance.', 'lms.', 'hr.', 'finance.', 'payroll.', 'school.', 'students.', 'timetable.', 'academics.'] as $prefix) {
            $this->assertSame([], array_values(array_filter($teacher, fn ($k) => str_starts_with($k, $prefix))), "No {$prefix}* capability.");
        }
    }

    #[Test]
    public function the_capability_is_school_scoped_and_only_three_roles_carry_it(): void
    {
        $holders = Role::query()->where('is_system', true)
            ->whereHas('capabilities', fn ($q) => $q->where('key', 'curriculum.delivery.teacher'))
            ->pluck('key')->sort()->values()->all();

        // school_admin holds it only so it can GRANT the Teacher role
        // (StaffRoleCatalog's no-escalation rule); principal does not.
        $this->assertSame(['school_admin', 'teacher'], $holders);
        $this->assertSame([], array_values(array_filter(
            Capability::query()->where('key', 'like', '%.teacher')->pluck('key')->all(),
            fn ($k) => $k !== 'curriculum.delivery.teacher',
        )), 'No other *.teacher capability (Attendance and LMS are not adopted).');
    }

    /** @return array{0: User, 1: School} */
    private function schoolWithAdmins(): array
    {
        [$admin, $school] = $this->createSchoolAdmin();
        // A second qualifying administrator keeps the last-admin invariant out of the way.
        $this->assignSchoolRole($this->createMembership($this->createUser(), $school), 'school_admin');

        return [$admin, $school];
    }

    #[Test]
    public function a_school_admin_grants_and_revokes_the_teacher_role_through_the_ordinary_path(): void
    {
        [$admin, $school] = $this->schoolWithAdmins();
        $staff = $this->createUser();
        $membership = $this->createMembership($staff, $school);
        $this->assignSchoolRole($membership, 'principal'); // an existing staff member

        $catalog = collect(app(StaffRoleCatalog::class)->catalogFor($admin, $school))->keyBy('key');
        $this->assertTrue($catalog['teacher']['grantable']);

        app(StaffAccessService::class)->grantRole($school, $admin, $membership->id, 'teacher');
        $this->assertTrue(app(CapabilityResolver::class)->canInSchool($staff, 'curriculum.delivery.teacher', $school));

        // A role grant creates no Employee link and no TeachingAssignment.
        app(TenantContext::class)->withSchool($school, function () use ($staff) {
            $this->assertFalse(Employee::query()->where('user_id', $staff->id)->exists());
            $this->assertSame(0, TeachingAssignment::query()->count());
        });

        app(StaffAccessService::class)->revokeRole($school, $admin, $membership->id, 'teacher');
        $this->assertFalse(app(CapabilityResolver::class)->canInSchool($staff, 'curriculum.delivery.teacher', $school), 'The existing cache invalidation applies.');
    }

    #[Test]
    public function principals_and_ordinary_members_cannot_grant_it(): void
    {
        [, $school] = $this->schoolWithAdmins();
        $principal = $this->createUser();
        $this->assignSchoolRole($this->createMembership($principal, $school), 'principal');
        $ordinary = $this->createUserWithCapabilities($school, []);
        $target = $this->createMembership($this->createUser(), $school);
        $this->assignSchoolRole($target, 'principal');

        foreach ([$principal, $ordinary] as $actor) {
            try {
                app(StaffAccessService::class)->grantRole($school, $actor, $target->id, 'teacher');
                $this->fail('A non-role-manager granted the Teacher role.');
            } catch (StaffAccountException $e) {
                // Refused by no-escalation (the role is not within their
                // capabilities) before, or instead of, the manager check.
                $this->assertContains($e->outcome, ['role_escalation', 'not_authorized']);
            }
        }
    }
}
