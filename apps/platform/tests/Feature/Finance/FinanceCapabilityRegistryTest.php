<?php

namespace Tests\Feature\Finance;

use App\Models\Capability;
use App\Models\Role;
use Database\Seeders\CapabilityAndRoleSeeder;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Phase 0G.3: proves the Finance ledger capability catalog
 * (docs/modules/FINANCE.md "Authorization design") is registered
 * through the existing, sole capability/role seeder -- no parallel
 * Finance-only capability table, no duplicate rows, idempotent across
 * repeated runs, and default role grants follow this checkpoint's own
 * documented "school_admin gets all three, principal gets none"
 * boundary. Mirrors `Tests\Feature\HR\HrCapabilityRegistryTest`'s exact
 * shape.
 */
class FinanceCapabilityRegistryTest extends TestCase
{
    private const array FINANCE_CAPABILITY_KEYS = [
        'finance.ledger.view', 'finance.ledger.post', 'finance.ledger.reverse',
    ];

    #[Test]
    public function every_approved_finance_capability_is_registered(): void
    {
        foreach (self::FINANCE_CAPABILITY_KEYS as $key) {
            $this->assertTrue(
                Capability::query()->where('key', $key)->exists(),
                "Expected capability '{$key}' to be seeded.",
            );
        }
    }

    #[Test]
    public function all_finance_capabilities_are_school_scoped_not_platform(): void
    {
        foreach (self::FINANCE_CAPABILITY_KEYS as $key) {
            $capability = Capability::query()->where('key', $key)->firstOrFail();
            $this->assertSame('school', $capability->namespace, "'{$key}' must be school-namespaced, not platform.");
        }
    }

    #[Test]
    public function finance_accounts_manage_is_registered_since_fee_1_and_granted_to_school_admin_only(): void
    {
        // 0G.3 deliberately registered no account administration; FEE.1
        // (ADR 0062 §6, owner decision K1) adds the minimal create /
        // activate-deactivate path under this one capability.
        $capability = Capability::query()->where('key', 'finance.accounts.manage')->firstOrFail();
        $this->assertSame('school', $capability->namespace);

        $holders = Role::query()->where('scope', 'school')->where('is_system', true)->get()
            ->filter(fn (Role $role) => $role->capabilities->contains('key', 'finance.accounts.manage'))
            ->pluck('key')->values()->all();
        $this->assertSame(['school_admin'], $holders);
    }

    #[Test]
    public function running_the_seeder_twice_is_idempotent(): void
    {
        $before = Capability::query()->count();

        (new CapabilityAndRoleSeeder)->run();
        (new CapabilityAndRoleSeeder)->run();

        $after = Capability::query()->count();

        $this->assertSame($before, $after, 'Re-running the seeder must never duplicate capability rows.');

        foreach (self::FINANCE_CAPABILITY_KEYS as $key) {
            $this->assertSame(1, Capability::query()->where('key', $key)->count(), "'{$key}' must exist exactly once after a repeated seeder run.");
        }
    }

    #[Test]
    public function rerunning_the_seeder_does_not_delete_unrelated_capabilities(): void
    {
        $platformKeysBefore = Capability::query()->where('namespace', 'platform')->pluck('key')->sort()->values();

        (new CapabilityAndRoleSeeder)->run();

        $platformKeysAfter = Capability::query()->where('namespace', 'platform')->pluck('key')->sort()->values();

        $this->assertEquals($platformKeysBefore->all(), $platformKeysAfter->all(), 'Re-seeding Finance capabilities must never remove/alter unrelated platform capabilities.');
    }

    #[Test]
    public function school_admin_receives_all_three_finance_capabilities_by_default(): void
    {
        $role = Role::query()->where('key', 'school_admin')->firstOrFail();
        $granted = $role->capabilities->pluck('key')->all();

        foreach (self::FINANCE_CAPABILITY_KEYS as $key) {
            $this->assertContains($key, $granted, "school_admin must receive '{$key}' by default.");
        }
    }

    #[Test]
    public function principal_receives_no_finance_capability_by_default(): void
    {
        $role = Role::query()->where('key', 'principal')->firstOrFail();
        $granted = $role->capabilities->pluck('key')->all();

        foreach (self::FINANCE_CAPABILITY_KEYS as $key) {
            $this->assertNotContains($key, $granted, "principal must NOT receive '{$key}' by default (docs/modules/FINANCE.md 0G.3 as-built).");
        }
    }
}
