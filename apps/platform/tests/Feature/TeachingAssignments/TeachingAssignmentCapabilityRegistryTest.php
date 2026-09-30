<?php

namespace Tests\Feature\TeachingAssignments;

use App\Models\Capability;
use App\Models\Role;
use Database\Seeders\CapabilityAndRoleSeeder;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * TCH.2 (ADR 0063 D-11, section 15): the two ADMINISTRATIVE capabilities
 * are seeded through the one capability/role seeder, granted by default to
 * school_admin and principal only -- and TCH.2 creates no Teacher role and
 * no owned-scope `*.teacher` capability (those are TCH.3 onward).
 */
class TeachingAssignmentCapabilityRegistryTest extends TestCase
{
    private const array KEYS = ['teaching.assignments.view', 'teaching.assignments.manage'];

    #[Test]
    public function both_capabilities_are_seeded_once_school_scoped(): void
    {
        (new CapabilityAndRoleSeeder)->run();

        foreach (self::KEYS as $key) {
            $this->assertSame(1, Capability::query()->where('key', $key)->count(), "{$key} seeded exactly once.");
            $this->assertSame('school', Capability::query()->where('key', $key)->value('namespace'));
        }
    }

    #[Test]
    public function only_school_admin_and_principal_hold_them_by_default(): void
    {
        $holders = Role::query()->where('is_system', true)
            ->whereHas('capabilities', fn ($q) => $q->whereIn('key', self::KEYS))
            ->pluck('key')->sort()->values()->all();

        $this->assertSame(['principal', 'school_admin'], $holders);

        foreach (['school_admin', 'principal'] as $roleKey) {
            $granted = Role::query()->where('key', $roleKey)->firstOrFail()->capabilities->pluck('key')->all();
            foreach (self::KEYS as $key) {
                $this->assertContains($key, $granted, "{$roleKey} holds {$key}.");
            }
        }
    }

    #[Test]
    public function there_is_no_teacher_role_and_no_owned_scope_teacher_capability(): void
    {
        $this->assertFalse(Role::query()->where('key', 'teacher')->exists(), 'No production Teacher role before TCH.3.');
        $this->assertSame([], Role::query()->where('is_system', true)->where('key', 'like', '%teacher%')->pluck('key')->all());

        $teacherCapabilities = Capability::query()->where('key', 'like', '%teacher%')->pluck('key')->all();
        $this->assertSame([], $teacherCapabilities, 'No *.teacher capability exists yet.');
        $this->assertSame(self::KEYS, Capability::query()->where('key', 'like', 'teaching.%')->orderByDesc('key')->pluck('key')->all(), 'teaching.* holds exactly the two administrative capabilities.');
    }
}
