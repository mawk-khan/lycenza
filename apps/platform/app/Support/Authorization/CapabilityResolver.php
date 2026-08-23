<?php

namespace App\Support\Authorization;

use App\Models\MembershipRoleAssignment;
use App\Models\School;
use App\Models\SchoolMembership;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Cache;

/**
 * The single authoritative service computing effective capabilities.
 * See docs/security/AUTHORIZATION.md.
 *
 * Platform capabilities and School capabilities are resolved and
 * cached SEPARATELY and never merged -- a platform capability grant
 * never implies a school.* capability (section 12/30: no invisible
 * "see every tenant row" mode). Cache keys always include every
 * security-relevant dimension (user, school) -- see
 * docs/architecture/adr/0022-tenant-context-propagation.md,
 * "tenant-aware cache" -- so permissions for User X in School A can
 * never be served from School B's cache entry.
 */
class CapabilityResolver
{
    private const CACHE_TTL_SECONDS = 60;

    public function __construct(private readonly TenantContext $context) {}

    /**
     * @return array<int, string>
     */
    public function platformCapabilities(User $actor): array
    {
        if ($actor->isDisabled()) {
            return [];
        }

        return Cache::remember(
            "platform:capabilities:user:{$actor->id}",
            self::CACHE_TTL_SECONDS,
            fn () => $actor->platformRoleAssignments()
                ->with('role.capabilities')
                ->get()
                ->flatMap(fn ($assignment) => $assignment->role->capabilities->pluck('key'))
                ->unique()
                ->values()
                ->all(),
        );
    }

    /**
     * @return array<int, string>
     */
    public function schoolCapabilities(User $actor, School $school): array
    {
        if ($actor->isDisabled()) {
            return [];
        }

        return Cache::remember(
            "school:{$school->id}:capabilities:user:{$actor->id}",
            self::CACHE_TTL_SECONDS,
            function () use ($actor, $school) {
                $membership = SchoolMembership::query()
                    ->where('user_id', $actor->id)
                    ->where('school_id', $school->id)
                    ->where('status', 'active')
                    ->first();

                if ($membership === null) {
                    return [];
                }

                // MembershipRoleAssignment is RLS-protected: read it
                // authoritatively for $school regardless of whatever
                // ambient TenantContext the caller currently has. See
                // TenantContext::withSchool()'s docblock.
                return $this->context->withSchool(
                    $school,
                    fn () => MembershipRoleAssignment::query()
                        ->where('school_membership_id', $membership->id)
                        ->with('role.capabilities')
                        ->get()
                        ->flatMap(fn ($assignment) => $assignment->role->capabilities->pluck('key'))
                        ->unique()
                        ->values()
                        ->all(),
                );
            },
        );
    }

    public function canPlatform(User $actor, string $capability): bool
    {
        return in_array($capability, $this->platformCapabilities($actor), true);
    }

    public function canInSchool(User $actor, string $capability, School $school): bool
    {
        return in_array($capability, $this->schoolCapabilities($actor, $school), true);
    }

    /**
     * Dispatches to platform or school resolution based on the
     * capability's own namespace prefix, so callers don't need to know
     * which table backs a given capability. A "platform.*" capability
     * is never satisfied by a school-scoped grant and vice versa.
     */
    public function can(User $actor, string $capability, ?School $school = null): bool
    {
        if (str_starts_with($capability, 'platform.')) {
            return $this->canPlatform($actor, $capability);
        }

        if ($school === null) {
            return false;
        }

        return $this->canInSchool($actor, $capability, $school);
    }

    public function forgetCache(User $actor, ?School $school = null): void
    {
        Cache::forget("platform:capabilities:user:{$actor->id}");
        if ($school !== null) {
            Cache::forget("school:{$school->id}:capabilities:user:{$actor->id}");
        }
    }
}
