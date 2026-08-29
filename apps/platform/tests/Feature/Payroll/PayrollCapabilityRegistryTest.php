<?php

namespace Tests\Feature\Payroll;

use App\Models\Capability;
use App\Models\Role;
use Database\Seeders\CapabilityAndRoleSeeder;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Phase 9.7 (ADR 0032 "Separation of duties"; docs/modules/PAYROLL.md
 * "Authorization") -- proves the Payroll capability catalog is
 * registered through the existing, sole capability/role seeder,
 * mirroring `Tests\Feature\Payments\PaymentsCapabilityRegistryTest`'s
 * exact shape.
 */
class PayrollCapabilityRegistryTest extends TestCase
{
    private const array PAYROLL_CAPABILITY_KEYS = [
        'payroll.structures.view',
        'payroll.structures.manage',
        'payroll.compensation.sensitive.view',
        'payroll.compensation.sensitive.manage',
        'payroll.periods.manage',
        'payroll.runs.manage',
        'payroll.runs.post',
        'payroll.runs.reverse',
        'payroll.accounting.manage',
    ];

    #[Test]
    public function every_approved_payroll_capability_is_registered(): void
    {
        foreach (self::PAYROLL_CAPABILITY_KEYS as $key) {
            $this->assertTrue(
                Capability::query()->where('key', $key)->exists(),
                "Expected capability '{$key}' to be seeded.",
            );
        }
    }

    #[Test]
    public function all_payroll_capabilities_are_school_scoped_not_platform(): void
    {
        foreach (self::PAYROLL_CAPABILITY_KEYS as $key) {
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

        foreach (self::PAYROLL_CAPABILITY_KEYS as $key) {
            $this->assertSame(1, Capability::query()->where('key', $key)->count(), "'{$key}' must exist exactly once after a repeated seeder run.");
        }
    }

    #[Test]
    public function school_admin_receives_every_payroll_capability_by_default(): void
    {
        $role = Role::query()->where('key', 'school_admin')->firstOrFail();
        $granted = $role->capabilities->pluck('key')->all();

        foreach (self::PAYROLL_CAPABILITY_KEYS as $key) {
            $this->assertContains($key, $granted, "school_admin must receive '{$key}' by default.");
        }
    }

    #[Test]
    public function principal_receives_no_payroll_capability_by_default(): void
    {
        $role = Role::query()->where('key', 'principal')->firstOrFail();
        $granted = $role->capabilities->pluck('key')->all();

        foreach (self::PAYROLL_CAPABILITY_KEYS as $key) {
            $this->assertNotContains($key, $granted, "principal must NOT receive '{$key}' by default.");
        }
    }

    #[Test]
    public function no_other_default_role_receives_a_payroll_capability(): void
    {
        $otherRoleKeys = Role::query()
            ->whereNotIn('key', ['school_admin'])
            ->where('scope', 'school')
            ->pluck('key');

        foreach ($otherRoleKeys as $roleKey) {
            $role = Role::query()->where('key', $roleKey)->firstOrFail();
            $granted = $role->capabilities->pluck('key')->all();

            foreach (self::PAYROLL_CAPABILITY_KEYS as $key) {
                $this->assertNotContains($key, $granted, "'{$roleKey}' must NOT receive '{$key}' by default.");
            }
        }
    }
}
