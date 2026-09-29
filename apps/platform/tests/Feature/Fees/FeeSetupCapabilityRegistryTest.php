<?php

namespace Tests\Feature\Fees;

use App\Models\Capability;
use App\Models\Role;
use Database\Seeders\CapabilityAndRoleSeeder;
use Database\Seeders\Demo\DemoAccountCatalog;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * FEE.1 (ADR 0062 §19, owner decision L): the three FEE.1 capabilities are
 * School-scoped, granted by default to School Admin only (never Principal
 * or any other default role), and the demo finance officer holds them.
 * No FEE.2+ capability (assessment, concessions) is registered yet.
 */
class FeeSetupCapabilityRegistryTest extends TestCase
{
    private const array FEE_1_CAPABILITIES = [
        'finance.accounts.manage', 'finance.fee_structures.view', 'finance.fee_structures.manage',
    ];

    #[Test]
    public function every_fee_1_capability_is_registered_school_scoped_and_idempotent(): void
    {
        (new CapabilityAndRoleSeeder)->run();

        foreach (self::FEE_1_CAPABILITIES as $key) {
            $this->assertSame(1, Capability::query()->where('key', $key)->count(), $key);
            $this->assertSame('school', Capability::query()->where('key', $key)->value('namespace'), $key);
        }
    }

    #[Test]
    public function school_admin_holds_them_and_no_other_default_role_does(): void
    {
        $admin = Role::query()->where('key', 'school_admin')->firstOrFail()->capabilities->pluck('key')->all();
        foreach (self::FEE_1_CAPABILITIES as $key) {
            $this->assertContains($key, $admin, "school_admin must hold {$key}");
        }

        foreach (Role::query()->where('scope', 'school')->where('key', '<>', 'school_admin')->where('is_system', true)->get() as $role) {
            $granted = $role->capabilities->pluck('key')->all();
            foreach (self::FEE_1_CAPABILITIES as $key) {
                $this->assertNotContains($key, $granted, "{$role->key} must NOT hold {$key} by default");
            }
        }

        $principal = Role::query()->where('key', 'principal')->firstOrFail()->capabilities->pluck('key')->all();
        $this->assertEmpty(array_filter($principal, fn ($k) => str_starts_with($k, 'finance.')), 'Principal holds no Finance capability.');
    }

    #[Test]
    public function no_fee_2_or_later_capability_is_registered_yet(): void
    {
        foreach (['finance.fee_assessments.run', 'finance.fee_concessions.view', 'finance.fee_concessions.request', 'finance.fee_concessions.approve'] as $key) {
            $this->assertFalse(Capability::query()->where('key', $key)->exists(), "{$key} belongs to FEE.2+.");
        }
    }

    #[Test]
    public function the_demo_finance_officer_holds_fee_setup(): void
    {
        $officer = DemoAccountCatalog::OPERATIONS_DESK_ROLES['demo.finance_officer']['capabilities'];

        foreach (self::FEE_1_CAPABILITIES as $key) {
            $this->assertContains($key, $officer);
        }
    }
}
