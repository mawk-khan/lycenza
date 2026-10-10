<?php

namespace Tests\Feature\Payroll;

use App\Models\Capability;
use App\Models\Role;
use Database\Seeders\CapabilityAndRoleSeeder;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Phase 9.7 (ADR 0034 "Separation of duties"; docs/modules/PAYROLL.md
 * "Authorization") -- proves the Payroll capability catalog is
 * registered through the existing, sole capability/role seeder,
 * mirroring `Tests\Feature\Payments\PaymentsCapabilityRegistryTest`'s
 * exact shape. Corrected at Phase 9.7's own authorization review: run
 * capabilities are five distinct keys (view/prepare/approve/post/
 * reverse), never one broad `.manage`, and the Highly Sensitive
 * compensation family plus the reserved statutory key are granted to
 * NO default role -- mirroring `hr.employees.sensitive.*`'s identical
 * "nobody by default" treatment.
 */
class PayrollCapabilityRegistryTest extends TestCase
{
    private const array ALL_PAYROLL_CAPABILITY_KEYS = [
        'payroll.structures.view',
        'payroll.structures.manage',
        'payroll.compensation.view',
        'payroll.compensation.sensitive.view',
        'payroll.compensation.sensitive.manage',
        'payroll.periods.manage',
        'payroll.runs.view',
        'payroll.runs.prepare',
        'payroll.runs.approve',
        'payroll.runs.post',
        'payroll.runs.reverse',
        'payroll.accounting.manage',
        'payroll.statutory.manage',
        'payroll.statutory.view',
        'payroll.statutory.identifiers.view',
        'payroll.statutory.identifiers.manage',
        'payroll.statutory.exports.generate',
    ];

    private const array SCHOOL_ADMIN_DEFAULT_KEYS = [
        'payroll.structures.view',
        'payroll.structures.manage',
        'payroll.compensation.view',
        'payroll.periods.manage',
        'payroll.runs.view',
        'payroll.runs.prepare',
        'payroll.runs.approve',
        'payroll.runs.post',
        'payroll.runs.reverse',
        'payroll.accounting.manage',
    ];

    private const array NOBODY_BY_DEFAULT_KEYS = [
        'payroll.compensation.sensitive.view',
        'payroll.compensation.sensitive.manage',
        'payroll.statutory.manage',
        'payroll.statutory.view',
        'payroll.statutory.identifiers.view',
        'payroll.statutory.identifiers.manage',
        'payroll.statutory.exports.generate',
    ];

    #[Test]
    public function every_approved_payroll_capability_is_registered(): void
    {
        foreach (self::ALL_PAYROLL_CAPABILITY_KEYS as $key) {
            $this->assertTrue(
                Capability::query()->where('key', $key)->exists(),
                "Expected capability '{$key}' to be seeded.",
            );
        }
    }

    #[Test]
    public function payroll_runs_manage_is_deliberately_not_registered(): void
    {
        $this->assertFalse(
            Capability::query()->where('key', 'payroll.runs.manage')->exists(),
            'payroll.runs.manage was replaced by distinct view/prepare/approve/post/reverse capabilities -- it must never be seeded.',
        );
    }

    #[Test]
    public function all_payroll_capabilities_are_school_scoped_not_platform(): void
    {
        foreach (self::ALL_PAYROLL_CAPABILITY_KEYS as $key) {
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

        foreach (self::ALL_PAYROLL_CAPABILITY_KEYS as $key) {
            $this->assertSame(1, Capability::query()->where('key', $key)->count(), "'{$key}' must exist exactly once after a repeated seeder run.");
        }
    }

    #[Test]
    public function school_admin_receives_the_non_sensitive_payroll_capabilities_by_default(): void
    {
        $role = Role::query()->where('key', 'school_admin')->firstOrFail();
        $granted = $role->capabilities->pluck('key')->all();

        foreach (self::SCHOOL_ADMIN_DEFAULT_KEYS as $key) {
            $this->assertContains($key, $granted, "school_admin must receive '{$key}' by default.");
        }
    }

    #[Test]
    public function school_admin_does_not_receive_sensitive_compensation_or_statutory_capabilities_by_default(): void
    {
        $role = Role::query()->where('key', 'school_admin')->firstOrFail();
        $granted = $role->capabilities->pluck('key')->all();

        foreach (self::NOBODY_BY_DEFAULT_KEYS as $key) {
            $this->assertNotContains($key, $granted, "school_admin must NOT receive '{$key}' by default -- Employee compensation amounts are Highly Sensitive, mirroring hr.employees.sensitive.* being granted to nobody by default.");
        }
    }

    #[Test]
    public function no_default_role_receives_a_sensitive_compensation_or_statutory_capability(): void
    {
        // SR.3 (ADR 0071 §4.3): the two compensation-sensitive keys sit ONLY on
        // the explicit `payroll_officer` role (granted only through
        // school.roles.grant.payroll_sensitive); statutory keys sit on no role.
        $this->assertSame(
            ['payroll.compensation.sensitive.manage', 'payroll.compensation.sensitive.view'],
            Role::query()->where('key', 'payroll_officer')->firstOrFail()->capabilities->pluck('key')
                ->filter(fn (string $key) => in_array($key, self::NOBODY_BY_DEFAULT_KEYS, true))->sort()->values()->all(),
        );
        // "By default" = the SYSTEM catalogue: committed non-system fixture roles
        // (e.g. a best-effort concurrency-test teardown) are never defaults.
        $roleKeys = Role::query()->where('scope', 'school')->where('is_system', true)->where('key', '<>', 'payroll_officer')->pluck('key');

        foreach ($roleKeys as $roleKey) {
            $role = Role::query()->where('key', $roleKey)->firstOrFail();
            $granted = $role->capabilities->pluck('key')->all();

            foreach (self::NOBODY_BY_DEFAULT_KEYS as $key) {
                $this->assertNotContains($key, $granted, "'{$roleKey}' must NOT receive '{$key}' by default -- nobody does.");
            }
        }
    }

    #[Test]
    public function principal_receives_no_payroll_capability_by_default(): void
    {
        $role = Role::query()->where('key', 'principal')->firstOrFail();
        $granted = $role->capabilities->pluck('key')->all();

        foreach (self::ALL_PAYROLL_CAPABILITY_KEYS as $key) {
            $this->assertNotContains($key, $granted, "principal must NOT receive '{$key}' by default.");
        }
    }

    #[Test]
    public function no_other_default_role_receives_a_non_sensitive_payroll_capability(): void
    {
        // SR.3 (ADR 0071 §4.3): `payroll_officer` is the one other holder -- the
        // maker side only, never approve, post or reverse (checker, §9).
        $officer = Role::query()->where('key', 'payroll_officer')->firstOrFail()->capabilities->pluck('key')->all();
        $this->assertSame(
            ['payroll.accounting.manage', 'payroll.compensation.view', 'payroll.periods.manage', 'payroll.runs.prepare', 'payroll.runs.view', 'payroll.structures.manage', 'payroll.structures.view'],
            collect($officer)->filter(fn (string $key) => in_array($key, self::SCHOOL_ADMIN_DEFAULT_KEYS, true))->sort()->values()->all(),
        );
        foreach (['payroll.runs.approve', 'payroll.runs.post', 'payroll.runs.reverse'] as $checker) {
            $this->assertNotContains($checker, $officer);
        }

        $otherRoleKeys = Role::query()
            ->whereNotIn('key', ['school_admin', 'payroll_officer'])
            ->where('scope', 'school')
            ->where('is_system', true)
            ->pluck('key');

        foreach ($otherRoleKeys as $roleKey) {
            $role = Role::query()->where('key', $roleKey)->firstOrFail();
            $granted = $role->capabilities->pluck('key')->all();

            foreach (self::SCHOOL_ADMIN_DEFAULT_KEYS as $key) {
                $this->assertNotContains($key, $granted, "'{$roleKey}' must NOT receive '{$key}' by default.");
            }
        }
    }
}
