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
 * school_admin and principal only. The TCH.3 Teacher role never receives
 * them (TeacherRoleRegistryTest pins the Teacher bundle).
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
    public function the_administrative_pair_never_reaches_the_teacher_role_and_teaching_holds_nothing_else(): void
    {
        // TCH.3 adds the production Teacher role; it must never administer
        // assignments (ADR 0063 section 12, TCH.3 scope).
        $teacher = Role::query()->where('key', 'teacher')->firstOrFail()->capabilities->pluck('key')->all();
        foreach (self::KEYS as $key) {
            $this->assertNotContains($key, $teacher);
        }

        $this->assertSame(self::KEYS, Capability::query()->where('key', 'like', 'teaching.%')->orderByDesc('key')->pluck('key')->all(), 'teaching.* holds exactly the two administrative capabilities.');
    }
}
