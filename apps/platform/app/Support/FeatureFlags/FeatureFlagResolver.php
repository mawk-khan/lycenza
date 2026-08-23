<?php

namespace App\Support\FeatureFlags;

use App\Models\FeatureFlag;
use App\Models\FeatureFlagSchoolOverride;
use App\Models\School;
use App\Support\Tenancy\TenantCache;
use App\Support\Tenancy\TenantContext;

/**
 * Section 28/29. A flag hides/disables product capability -- it is
 * NEVER an authorization check (root CLAUDE.md's Phase 0C rule); a
 * disabled flag and a missing capability are different failure modes
 * with different causes, and code must not conflate them.
 *
 * Resolution order: School-specific override (if one exists) else the
 * flag's platform-wide default_enabled. Cached per (School, flag) via
 * TenantCache -- identical logical keys in different Schools never
 * collide (see TenantCacheTest's pattern; FeatureFlagCacheIsolationTest
 * proves the same property for this resolver specifically).
 */
class FeatureFlagResolver
{
    private const CACHE_TTL_SECONDS = 60;

    public function __construct(private readonly TenantContext $context) {}

    public function isEnabledForSchool(string $key, School $school): bool
    {
        return $this->context->withSchool($school, function () use ($key, $school) {
            $cache = app(TenantCache::class);

            return (bool) $cache->remember("feature_flag:{$key}", self::CACHE_TTL_SECONDS, function () use ($key, $school) {
                $override = FeatureFlagSchoolOverride::query()
                    ->where('school_id', $school->id)
                    ->where('feature_flag_key', $key)
                    ->first();

                if ($override !== null) {
                    return $override->enabled;
                }

                $flag = FeatureFlag::query()->find($key);

                return $flag !== null && $flag->default_enabled;
            });
        });
    }

    public function forgetCache(string $key, School $school): void
    {
        $this->context->withSchool($school, function () use ($key) {
            app(TenantCache::class)->forget("feature_flag:{$key}");
        });
    }
}
