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
 * FEE.2 adds the run capability; FEE.3 the three concession capabilities
 * (School Admin all, Principal none, the demo finance officer everything
 * except approval -- maker/checker).
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

        // SR.3 (ADR 0071 §4.4): the production Accountant is the ONE other
        // system role holding fee setup; no other role does.
        foreach (Role::query()->where('scope', 'school')->whereNotIn('key', ['school_admin', 'accountant'])->where('is_system', true)->get() as $role) {
            $granted = $role->capabilities->pluck('key')->all();
            foreach (self::FEE_1_CAPABILITIES as $key) {
                $this->assertNotContains($key, $granted, "{$role->key} must NOT hold {$key} by default");
            }
        }
        $accountant = Role::query()->where('key', 'accountant')->firstOrFail()->capabilities->pluck('key')->all();
        $this->assertSame([], array_values(array_diff(self::FEE_1_CAPABILITIES, $accountant)), 'Accountant holds fee setup.');

        $principal = Role::query()->where('key', 'principal')->firstOrFail()->capabilities->pluck('key')->all();
        $this->assertEmpty(array_filter($principal, fn ($k) => str_starts_with($k, 'finance.')), 'Principal holds no Finance capability.');
    }

    #[Test]
    public function fee_2_registers_the_run_capability_for_school_admin_only(): void
    {
        $run = Capability::query()->where('key', 'finance.fee_assessments.run')->firstOrFail();
        $this->assertSame('school', $run->namespace);

        $holders = Role::query()->where('scope', 'school')->where('is_system', true)->get()
            ->filter(fn (Role $role) => $role->capabilities->contains('key', 'finance.fee_assessments.run'))
            ->pluck('key')->sort()->values()->all();
        // SR.3 (ADR 0071 §4.4): the production Accountant runs fee assessments too; never Principal.
        $this->assertSame(['accountant', 'school_admin'], $holders, 'School Admin and Accountant run fee assessments; never Principal.');
        $this->assertContains('accountant', DemoAccountCatalog::OPERATIONS_DESKS['finance']['roles']);
    }

    #[Test]
    public function fee_3_concession_capabilities_follow_owner_decision_l(): void
    {
        $officer = $this->accountantCapabilities();

        // SR.3 (ADR 0071 §4.4, §9): the Accountant views and REQUESTS; only School Admin approves.
        foreach (['finance.fee_concessions.view' => ['accountant', 'school_admin'], 'finance.fee_concessions.request' => ['accountant', 'school_admin'], 'finance.fee_concessions.approve' => ['school_admin']] as $key => $expected) {
            $this->assertSame('school', Capability::query()->where('key', $key)->value('namespace'), $key);

            $holders = Role::query()->where('scope', 'school')->where('is_system', true)->get()
                ->filter(fn (Role $role) => $role->capabilities->contains('key', $key))
                ->pluck('key')->sort()->values()->all();
            $this->assertSame($expected, $holders, "{$key}: never Principal.");
        }

        $this->assertContains('finance.fee_concessions.view', $officer);
        $this->assertContains('finance.fee_concessions.request', $officer);
        $this->assertNotContains('finance.fee_concessions.approve', $officer, 'The Accountant requests; School Admin approves (maker/checker).');
    }

    #[Test]
    public function the_accountant_holds_fee_setup(): void
    {
        $officer = $this->accountantCapabilities();

        foreach (self::FEE_1_CAPABILITIES as $key) {
            $this->assertContains($key, $officer);
        }
    }

    /** @return list<string> the production Accountant role's capabilities (SR.3) */
    private function accountantCapabilities(): array
    {
        return Role::query()->where('key', 'accountant')->where('is_system', true)->firstOrFail()->capabilities()->pluck('key')->all();
    }
}
