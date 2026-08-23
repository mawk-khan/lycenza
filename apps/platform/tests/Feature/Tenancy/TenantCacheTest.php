<?php

namespace Tests\Feature\Tenancy;

use App\Support\Tenancy\TenantCache;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantContextRequiredException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Section 24: identical logical keys in different Schools must never
 * collide, and missing required context must fail closed.
 */
class TenantCacheTest extends TestCase
{
    use CreatesTenancyFixtures;

    #[Test]
    public function identical_keys_in_different_schools_do_not_collide(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $context = app(TenantContext::class);
        $cache = app(TenantCache::class);

        $context->set($schoolA);
        $cache->put('dashboard.widget', 'value-for-a', 60);

        $context->set($schoolB);
        $cache->put('dashboard.widget', 'value-for-b', 60);

        $context->set($schoolA);
        $this->assertSame('value-for-a', $cache->get('dashboard.widget'));

        $context->set($schoolB);
        $this->assertSame('value-for-b', $cache->get('dashboard.widget'));
    }

    #[Test]
    public function calling_a_tenant_cache_method_without_context_fails_closed(): void
    {
        app(TenantContext::class)->clear();

        $this->expectException(TenantContextRequiredException::class);

        app(TenantCache::class)->get('anything');
    }

    #[Test]
    public function platform_key_is_visibly_distinct_from_any_school_key(): void
    {
        $this->assertSame('platform:foo', TenantCache::platformKey('foo'));
    }
}
