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
    public function teacher_is_a_system_school_role_carrying_exactly_the_owned_scope_capabilities(): void
    {
        $role = Role::query()->where('key', 'teacher')->firstOrFail();

        $this->assertTrue($role->is_system);
        $this->assertSame('school', $role->scope);
        // TCH.3 + TCH.4 + TCH.5C + TCH.5D + RES.4 (ADR 0068 §25, development only): exactly the five owned-scope capabilities.
        $this->assertSame(['attendance.teacher', 'curriculum.delivery.teacher', 'examinations.marks.teacher', 'lms.assignments.teacher', 'lms.content.teacher'], $this->capabilities('teacher'));
        $this->assertSame(1, Role::query()->where('key', 'like', '%teacher%')->count(), 'One Teacher role, no second teacher-like role.');
    }

    #[Test]
    public function the_teacher_bundle_holds_no_school_wide_or_unrelated_authority(): void
    {
        $teacher = $this->capabilities('teacher');

        foreach (['curriculum.delivery.view', 'curriculum.delivery.manage', 'attendance.view', 'attendance.manage', 'teaching.assignments.view', 'teaching.assignments.manage', 'syllabus.view', 'syllabus.manage',
            'lms.content.view', 'lms.content.manage', 'lms.assignments.view', 'lms.assignments.manage', 'students.view'] as $key) {
            $this->assertNotContains($key, $teacher);
        }

        foreach (['hr.', 'finance.', 'payroll.', 'school.', 'students.', 'timetable.', 'academics.'] as $prefix) {
            $this->assertSame([], array_values(array_filter($teacher, fn ($k) => str_starts_with($k, $prefix))), "No {$prefix}* capability.");
        }
        $this->assertSame(['attendance.teacher'], array_values(array_filter($teacher, fn ($k) => str_starts_with($k, 'attendance.'))), 'Only the owned-scope Attendance capability.');
        $this->assertSame(['lms.assignments.teacher', 'lms.content.teacher'], array_values(array_filter($teacher, fn ($k) => str_starts_with($k, 'lms.'))), 'Only the owned-scope LMS capabilities; no School-wide LMS capability.');
        // RES.4: the owned marks key only -- never marks view/manage/lock/corrections, papers, definitions or results.
        $this->assertSame(['examinations.marks.teacher'], array_values(array_filter($teacher, fn ($k) => str_starts_with($k, 'examinations.'))), 'Only the owned-scope marks capability.');
    }

    #[Test]
    public function the_owned_capabilities_are_carried_only_by_teacher_and_by_school_admin_for_grantability(): void
    {
        foreach (['curriculum.delivery.teacher', 'attendance.teacher', 'lms.content.teacher', 'lms.assignments.teacher', 'examinations.marks.teacher'] as $key) {
            $holders = Role::query()->where('is_system', true)
                ->whereHas('capabilities', fn ($q) => $q->where('key', $key))
                ->pluck('key')->sort()->values()->all();

            // school_admin holds them only so it can GRANT the Teacher role
            // (StaffRoleCatalog's no-escalation rule); principal does not.
            $this->assertSame(['school_admin', 'teacher'], $holders, $key);
            $this->assertSame('school', Capability::query()->where('key', $key)->value('namespace'));
        }

        $this->assertSame(['attendance.teacher', 'curriculum.delivery.teacher', 'examinations.marks.teacher', 'lms.assignments.teacher', 'lms.content.teacher'], Capability::query()->where('key', 'like', '%.teacher')->orderBy('key')->pluck('key')->all(), 'No Timetable or other *.teacher capability (not adopted).');
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
        $this->assertTrue(app(CapabilityResolver::class)->canInSchool($staff, 'lms.content.teacher', $school));
        $this->assertTrue(app(CapabilityResolver::class)->canInSchool($staff, 'lms.assignments.teacher', $school));

        // A role grant creates no Employee link and no TeachingAssignment.
        app(TenantContext::class)->withSchool($school, function () use ($staff) {
            $this->assertFalse(Employee::query()->where('user_id', $staff->id)->exists());
            $this->assertSame(0, TeachingAssignment::query()->count());
        });

        app(StaffAccessService::class)->revokeRole($school, $admin, $membership->id, 'teacher');
        $this->assertFalse(app(CapabilityResolver::class)->canInSchool($staff, 'curriculum.delivery.teacher', $school), 'The existing cache invalidation applies.');
        $this->assertFalse(app(CapabilityResolver::class)->canInSchool($staff, 'lms.content.teacher', $school), 'Revocation removes LMS teacher access too.');
        $this->assertFalse(app(CapabilityResolver::class)->canInSchool($staff, 'lms.assignments.teacher', $school));
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
