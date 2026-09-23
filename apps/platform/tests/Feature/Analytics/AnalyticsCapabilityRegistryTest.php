<?php

namespace Tests\Feature\Analytics;

use App\Models\Capability;
use App\Models\Role;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Phase 0L.2-1 -- the Analytics capabilities ADR 0040 §5 froze, as
 * seeded: `analytics.view` for the approved School Admin and Principal
 * audience only, `analytics.export` registered but granted to no
 * system role, and `analytics.platform.view` not seeded at all.
 */
class AnalyticsCapabilityRegistryTest extends TestCase
{
    #[Test]
    public function view_and_export_are_registered_as_school_capabilities(): void
    {
        foreach (['analytics.view', 'analytics.export'] as $key) {
            $this->assertSame('school', Capability::query()->where('key', $key)->firstOrFail()->namespace, $key);
        }
    }

    #[Test]
    public function cross_school_analytics_is_not_seeded(): void
    {
        $this->assertFalse(Capability::query()->where('key', 'analytics.platform.view')->exists());
        $this->assertSame(['analytics.export', 'analytics.view'], Capability::query()->where('key', 'like', 'analytics.%')->orderBy('key')->pluck('key')->all());
    }

    #[Test]
    public function only_school_admin_and_principal_system_roles_receive_analytics_view(): void
    {
        $holders = Role::query()->where('is_system', true)
            ->whereHas('capabilities', fn ($q) => $q->where('key', 'analytics.view'))
            ->orderBy('key')->pluck('key')->all();

        $this->assertSame(['principal', 'school_admin'], $holders);
    }

    #[Test]
    public function no_system_role_receives_analytics_export(): void
    {
        $this->assertFalse(Role::query()->where('is_system', true)
            ->whereHas('capabilities', fn ($q) => $q->where('key', 'analytics.export'))->exists());
    }
}
