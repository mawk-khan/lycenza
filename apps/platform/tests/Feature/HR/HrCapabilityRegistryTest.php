<?php

namespace Tests\Feature\HR;

use App\Models\Capability;
use App\Models\Role;
use Database\Seeders\CapabilityAndRoleSeeder;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Phase 8A.10: proves the HR capability catalog (docs/modules/HR.md
 * "Authorization design") is registered through the existing, sole
 * capability/role seeder -- no parallel HR-only capability table, no
 * duplicate rows, idempotent across repeated runs, and default role
 * grants follow the checkpoint's own documented "view/manage/
 * personal.view only, nothing else by default" boundary.
 */
class HrCapabilityRegistryTest extends TestCase
{
    private const array HR_CAPABILITY_KEYS = [
        'hr.employees.view', 'hr.employees.manage',
        'hr.employees.personal.view', 'hr.employees.personal.manage',
        'hr.employees.assignments.view', 'hr.employees.assignments.manage',
        'hr.employees.qualifications.view', 'hr.employees.qualifications.manage',
        'hr.employees.documents.view', 'hr.employees.documents.manage',
        'hr.employees.notes.view', 'hr.employees.notes.manage',
        'hr.employees.sensitive.view', 'hr.employees.sensitive.manage',
        'hr.departments.view', 'hr.departments.manage',
        'hr.positions.view', 'hr.positions.manage',
    ];

    #[Test]
    public function every_approved_hr_capability_is_registered(): void
    {
        foreach (self::HR_CAPABILITY_KEYS as $key) {
            $this->assertTrue(
                Capability::query()->where('key', $key)->exists(),
                "Expected capability '{$key}' to be seeded.",
            );
        }
    }

    #[Test]
    public function all_hr_capabilities_are_school_scoped_not_platform(): void
    {
        foreach (self::HR_CAPABILITY_KEYS as $key) {
            $capability = Capability::query()->where('key', $key)->firstOrFail();
            $this->assertSame('school', $capability->namespace, "'{$key}' must be school-namespaced, not platform.");
        }
    }

    #[Test]
    public function running_the_seeder_twice_is_idempotent(): void
    {
        $before = Capability::query()->count();

        (new CapabilityAndRoleSeeder)->run();
        (new CapabilityAndRoleSeeder)->run();

        $after = Capability::query()->count();

        $this->assertSame($before, $after, 'Re-running the seeder must never duplicate capability rows.');

        foreach (self::HR_CAPABILITY_KEYS as $key) {
            $this->assertSame(1, Capability::query()->where('key', $key)->count(), "'{$key}' must exist exactly once after a repeated seeder run.");
        }
    }

    #[Test]
    public function rerunning_the_seeder_does_not_delete_unrelated_capabilities(): void
    {
        $nonHrKeysBefore = Capability::query()->where('namespace', 'platform')->pluck('key')->sort()->values();

        (new CapabilityAndRoleSeeder)->run();

        $nonHrKeysAfter = Capability::query()->where('namespace', 'platform')->pluck('key')->sort()->values();

        $this->assertEquals($nonHrKeysBefore->all(), $nonHrKeysAfter->all(), 'Re-seeding HR capabilities must never remove/alter unrelated platform capabilities.');
    }

    #[Test]
    public function school_admin_receives_only_view_manage_and_personal_view_by_default(): void
    {
        $role = Role::query()->where('key', 'school_admin')->firstOrFail();
        $granted = $role->capabilities->pluck('key')->all();

        $this->assertContains('hr.employees.view', $granted);
        $this->assertContains('hr.employees.manage', $granted);
        $this->assertContains('hr.employees.personal.view', $granted);

        foreach (array_diff(self::HR_CAPABILITY_KEYS, ['hr.employees.view', 'hr.employees.manage', 'hr.employees.personal.view']) as $notExpected) {
            $this->assertNotContains($notExpected, $granted, "school_admin must NOT receive '{$notExpected}' by default (docs/modules/HR.md 8A.10 as-built).");
        }
    }

    #[Test]
    public function principal_receives_only_view_manage_and_personal_view_by_default(): void
    {
        $role = Role::query()->where('key', 'principal')->firstOrFail();
        $granted = $role->capabilities->pluck('key')->all();

        $this->assertContains('hr.employees.view', $granted);
        $this->assertContains('hr.employees.manage', $granted);
        $this->assertContains('hr.employees.personal.view', $granted);

        foreach (array_diff(self::HR_CAPABILITY_KEYS, ['hr.employees.view', 'hr.employees.manage', 'hr.employees.personal.view']) as $notExpected) {
            $this->assertNotContains($notExpected, $granted, "principal must NOT receive '{$notExpected}' by default.");
        }
    }

    #[Test]
    public function no_system_role_receives_sensitive_capabilities_by_default(): void
    {
        foreach (Role::query()->where('is_system', true)->get() as $role) {
            $granted = $role->capabilities->pluck('key')->all();
            $this->assertNotContains('hr.employees.sensitive.view', $granted, "{$role->key} must not receive hr.employees.sensitive.view by default.");
            $this->assertNotContains('hr.employees.sensitive.manage', $granted, "{$role->key} must not receive hr.employees.sensitive.manage by default.");
        }
    }
}
