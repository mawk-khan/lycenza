<?php

namespace Tests\Feature\Payments;

use App\Models\Capability;
use App\Models\Role;
use Database\Seeders\CapabilityAndRoleSeeder;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Phase 0G.5: proves the Payments capability catalog
 * (docs/modules/FINANCE.md "Authorization architecture") is registered
 * through the existing, sole capability/role seeder -- mirrors
 * `Tests\Feature\Fees\FeesCapabilityRegistryTest`'s exact shape.
 */
class PaymentsCapabilityRegistryTest extends TestCase
{
    private const array PAYMENTS_CAPABILITY_KEYS = ['finance.payments.view'];

    #[Test]
    public function every_approved_payments_capability_is_registered(): void
    {
        foreach (self::PAYMENTS_CAPABILITY_KEYS as $key) {
            $this->assertTrue(
                Capability::query()->where('key', $key)->exists(),
                "Expected capability '{$key}' to be seeded.",
            );
        }
    }

    #[Test]
    public function finance_payments_manage_is_deliberately_not_registered(): void
    {
        $this->assertFalse(
            Capability::query()->where('key', 'finance.payments.manage')->exists(),
            'finance.payments.manage must not be registered in 0G.5 -- no human-triggered payment write action exists to gate (rule 53).'
        );
    }

    #[Test]
    public function all_payments_capabilities_are_school_scoped_not_platform(): void
    {
        foreach (self::PAYMENTS_CAPABILITY_KEYS as $key) {
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

        foreach (self::PAYMENTS_CAPABILITY_KEYS as $key) {
            $this->assertSame(1, Capability::query()->where('key', $key)->count(), "'{$key}' must exist exactly once after a repeated seeder run.");
        }
    }

    #[Test]
    public function school_admin_receives_the_payments_capability_by_default(): void
    {
        $role = Role::query()->where('key', 'school_admin')->firstOrFail();
        $granted = $role->capabilities->pluck('key')->all();

        foreach (self::PAYMENTS_CAPABILITY_KEYS as $key) {
            $this->assertContains($key, $granted, "school_admin must receive '{$key}' by default.");
        }
    }

    #[Test]
    public function principal_receives_no_payments_capability_by_default(): void
    {
        $role = Role::query()->where('key', 'principal')->firstOrFail();
        $granted = $role->capabilities->pluck('key')->all();

        foreach (self::PAYMENTS_CAPABILITY_KEYS as $key) {
            $this->assertNotContains($key, $granted, "principal must NOT receive '{$key}' by default.");
        }
    }

    #[Test]
    public function no_other_default_role_receives_a_payments_capability(): void
    {
        $otherRoleKeys = Role::query()
            ->whereNotIn('key', ['school_admin'])
            ->where('scope', 'school')
            ->pluck('key');

        foreach ($otherRoleKeys as $roleKey) {
            $role = Role::query()->where('key', $roleKey)->firstOrFail();
            $granted = $role->capabilities->pluck('key')->all();

            foreach (self::PAYMENTS_CAPABILITY_KEYS as $key) {
                $this->assertNotContains($key, $granted, "'{$roleKey}' must NOT receive '{$key}' by default.");
            }
        }
    }
}
