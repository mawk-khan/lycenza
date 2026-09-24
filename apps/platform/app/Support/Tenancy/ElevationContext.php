<?php

namespace App\Support\Tenancy;

use App\Models\SchoolElevation;

/**
 * Request-scoped platform elevation reference (Phase 0N.3, ADR 0044
 * section 2) -- separate from TenantContext and from membership. Set only
 * by App\Http\Middleware\ResolvePlatformElevation after the persistent
 * record passed every validity check; bound as `scoped()` like
 * TenantContext, so it never survives into another request or job.
 *
 * It lets code tell an ordinary membership request from a platform-
 * elevated one. Being elevated establishes no School context by itself:
 * RequireSchoolContext puts the elevation's School into TenantContext
 * only on a route that explicitly opted in (`school-context:elevated`),
 * and none does in Phase 0N.3. It never grants a School capability.
 */
class ElevationContext
{
    private ?SchoolElevation $elevation = null;

    private bool $targetConflict = false;

    public function set(SchoolElevation $elevation, bool $targetConflict = false): void
    {
        $this->elevation = $elevation;
        $this->targetConflict = $targetConflict;
    }

    public function clear(): void
    {
        $this->elevation = null;
        $this->targetConflict = false;
    }

    public function isElevated(): bool
    {
        return $this->elevation !== null;
    }

    public function elevation(): ?SchoolElevation
    {
        return $this->elevation;
    }

    public function elevationId(): ?string
    {
        return $this->elevation?->id;
    }

    /**
     * True when a verified domain or the local/testing X-School-Id header
     * resolved a DIFFERENT School than the elevation's for this request:
     * no School context may be established (fail closed), but the
     * elevation itself stays valid.
     */
    public function hasTargetConflict(): bool
    {
        return $this->targetConflict;
    }
}
