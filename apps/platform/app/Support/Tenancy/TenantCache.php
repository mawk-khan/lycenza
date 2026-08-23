<?php

namespace App\Support\Tenancy;

use Illuminate\Support\Facades\Cache;

/**
 * Tenant-aware cache convention (section 24, docs/architecture/TENANCY.md).
 * Every tenant-scoped cache entry is namespaced "school:{id}:{key}" so
 * identical logical keys in different Schools never collide, and a
 * cache flush can be scoped to one School if ever needed. Fails closed:
 * calling a `school*` method with no active School context throws
 * rather than silently falling back to an unscoped (and therefore
 * potentially cross-tenant-colliding) key.
 *
 * Platform-level (non-tenant) caching uses the plain Cache facade
 * directly, or schoolKey()'s "platform:" counterpart below, so the two
 * namespaces stay visibly distinct.
 */
class TenantCache
{
    public function __construct(private readonly TenantContext $context) {}

    public function key(string $key): string
    {
        return "school:{$this->context->requireSchool()->id}:{$key}";
    }

    public static function platformKey(string $key): string
    {
        return "platform:{$key}";
    }

    public function remember(string $key, int $ttlSeconds, callable $callback): mixed
    {
        return Cache::remember($this->key($key), $ttlSeconds, $callback);
    }

    public function put(string $key, mixed $value, int $ttlSeconds): bool
    {
        return Cache::put($this->key($key), $value, $ttlSeconds);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return Cache::get($this->key($key), $default);
    }

    public function forget(string $key): bool
    {
        return Cache::forget($this->key($key));
    }
}
