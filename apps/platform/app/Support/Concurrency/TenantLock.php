<?php

namespace App\Support\Concurrency;

use App\Models\School;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;

/**
 * Phase 0C.4 section 26/27: the one sanctioned way to take a
 * distributed lock in this codebase -- wraps Laravel's own supported
 * cache-locking abstraction (`Cache::lock()`, backed by whatever
 * `CACHE_STORE` actually is -- Redis in dev, the `array` store's
 * built-in lock support in tests) rather than a hand-written Redis
 * `SET NX`/Lua algorithm. Bounded TTL and safe release (a `Lock`
 * instance can only be released by whoever holds its owner token,
 * unless `forceRelease()` is used) come from Laravel itself.
 *
 * Tenant-specific locks are namespaced `school:{school_id}:lock:
 * {operation}` (section 27) so School A can never block School B by
 * acquiring a lock for "the same" operation name -- always resolve a
 * lock through this class, never call `Cache::lock()` directly with a
 * hand-built key.
 */
class TenantLock
{
    public function forSchool(School $school, string $operation, int $ttlSeconds = 30): Lock
    {
        return Cache::lock("school:{$school->id}:lock:{$operation}", $ttlSeconds);
    }

    /**
     * For an operation with no School identity at all (a genuinely
     * cross-tenant/central concern, e.g. a future central dispatcher
     * lock) -- a distinct namespace so a central lock can never
     * collide with a School-scoped one even if the operation name is
     * reused.
     */
    public function central(string $operation, int $ttlSeconds = 30): Lock
    {
        return Cache::lock("platform:lock:{$operation}", $ttlSeconds);
    }
}
