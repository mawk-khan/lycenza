<?php

namespace Tests\Feature\Fees;

use App\Models\Capability;
use App\Models\Role;
use Database\Seeders\CapabilityAndRoleSeeder;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Phase 0G.4: proves the Fees/charges capability catalog
 * (docs/modules/FINANCE.md "Authorization architecture") is registered
 * through the existing, sole capability/role seeder -- mirrors
 * `Tests\Feature\Finance\FinanceCapabilityRegistryTest`'s exact shape.
 */
class FeesCapabilityRegistryTest extends TestCase
{
    private const array FEES_CAPABILITY_KEYS = [
        'finance.charges.view', 'finance.charges.manage',
    ];

    #[Test]
    public function every_approved_fees_capability_is_registered(): void
    {
        foreach (self::FEES_CAPABILITY_KEYS as $key) {
            $this->assertTrue(
                Capability::query()->where('key', $key)->exists(),
                "Expected capability '{$key}' to be seeded.",
            );
        }
    }

    #[Test]
    public function all_fees_capabilities_are_school_scoped_not_platform(): void
    {
        foreach (self::FEES_CAPABILITY_KEYS as $key) {
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

        foreach (self::FEES_CAPABILITY_KEYS as $key) {
            $this->assertSame(1, Capability::query()->where('key', $key)->count(), "'{$key}' must exist exactly once after a repeated seeder run.");
        }
    }

    #[Test]
    public function school_admin_receives_both_fees_capabilities_by_default(): void
    {
        $role = Role::query()->where('key', 'school_admin')->firstOrFail();
        $granted = $role->capabilities->pluck('key')->all();

        foreach (self::FEES_CAPABILITY_KEYS as $key) {
            $this->assertContains($key, $granted, "school_admin must receive '{$key}' by default.");
        }
    }

    #[Test]
    public function principal_receives_no_fees_capability_by_default(): void
    {
        $role = Role::query()->where('key', 'principal')->firstOrFail();
        $granted = $role->capabilities->pluck('key')->all();

        foreach (self::FEES_CAPABILITY_KEYS as $key) {
            $this->assertNotContains($key, $granted, "principal must NOT receive '{$key}' by default (docs/modules/FINANCE.md 0G.4 as-built).");
        }
    }

    #[Test]
    public function no_other_default_role_receives_a_fees_capability(): void
    {
        // SR.3 (ADR 0071 §4.4, §4.5): exactly the production Accountant (view +
        // manage) and Cashier (view) hold charge capabilities besides School Admin.
        $contracted = [
            'finance.charges.view' => ['accountant', 'cashier', 'school_admin'],
            'finance.charges.manage' => ['accountant', 'school_admin'],
        ];

        foreach (self::FEES_CAPABILITY_KEYS as $key) {
            $holders = Role::query()->where('scope', 'school')->where('is_system', true)->get()
                ->filter(fn (Role $role) => $role->capabilities->contains('key', $key))
                ->pluck('key')->sort()->values()->all();
            $this->assertSame($contracted[$key], $holders, "{$key}: no other role receives it by default.");
        }
    }
}
