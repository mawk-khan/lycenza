<?php

namespace App\Domain\Platform\Application\Groups;

use App\Models\GroupRoleAssignment;
use App\Models\Role;
use App\Models\SchoolGroup;
use App\Models\SchoolGroupRegion;
use App\Models\SchoolGroupZone;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Auth\Mfa\FreshMfaProof;
use App\Support\Authorization\CapabilityResolver;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * ORG.12E (ADR 0080 §10.5, OD-12.13/OD-12.14): the ONE writer of Network
 * roles on the existing Group grant (`group_role_assignments`).
 *
 * - `network_manager` is appointed and removed only by the platform
 *   (`platform.school_group_grants.manage`). A Network Manager never
 *   appoints another Network Manager -- here and in the database trigger.
 * - `network_viewer` (read-only) is granted, for the whole Group or exactly
 *   one Region or Zone of it, by the platform or by a whole-Group Network
 *   Manager of THAT Group (`group.network.operators.manage`).
 * - Every change: a closed reason, a fresh MFA proof (minted by
 *   FreshMfaRequirement after the floor check, before this service runs), the
 *   authority re-read inside the transaction, never to oneself (also a
 *   database CHECK), audited on the platform ledger with ids and codes only.
 * - The scope is fixed at grant: changing it is revoke + grant (one active
 *   grant per person, Group and role).
 * - Nothing here creates or reads a School membership, School role or tenant
 *   record. Group Admin grants keep their ADR 0045 path
 *   (SchoolGroupGovernanceService::grant/revoke).
 */
class NetworkAccessService
{
    public const MANAGER = 'network_manager';

    public const VIEWER = 'network_viewer';

    /** The two code-owned Network roles offered by the UI (decisions are by capability, below). */
    public const ROLES = [self::MANAGER, self::VIEWER];

    /** A role carrying Network management: appointed by the platform only. */
    public const CLASS_MANAGEMENT = 'management';

    /** A role whose every capability is group.network.view: read-only, scopable. */
    public const CLASS_OPERATOR = 'operator';

    public function __construct(
        private readonly CapabilityResolver $capabilities,
        private readonly GroupGovernanceAccess $access,
        private readonly SchoolGroupGovernanceService $groups,
        private readonly AuditRecorder $audit,
    ) {}

    /**
     * A Group role's Network class, decided by the capabilities it carries
     * (never its name, rule 24) -- the same rule as the database trigger.
     * Null for any other Group role (the Group Admin keeps its ADR 0045 path).
     */
    public static function classOf(Role $role): ?string
    {
        if ($role->scope !== Role::SCOPE_GROUP) {
            return null;
        }
        $keys = $role->capabilities()->pluck('key')->sort()->values()->all();
        if (array_intersect($keys, [GroupGovernanceAccess::MANAGE, GroupGovernanceAccess::MANAGE_OPERATORS]) !== []) {
            return self::CLASS_MANAGEMENT;
        }

        return $keys === [GroupGovernanceAccess::VIEW] ? self::CLASS_OPERATOR : null;
    }

    /** The Network class of a grant's role, or null when it is not a Network role. */
    public function classOfGrant(GroupRoleAssignment $grant): ?string
    {
        $role = Role::query()->find($grant->role_id);

        return $role === null ? null : self::classOf($role);
    }

    /** The cached floor for offering a grant/revoke of a role of $class (before MFA is spent). */
    public function assertMayAdminister(User $actor, SchoolGroup $group, ?string $class): void
    {
        $allowed = match ($class) {
            self::CLASS_MANAGEMENT => $this->capabilities->canPlatform($actor, SchoolGroupGovernanceService::MANAGE_GRANTS),
            self::CLASS_OPERATOR => $this->access->mayManageOperators($actor, $group),
            default => false,
        };
        if (! $allowed) {
            throw new AccessDeniedHttpException('This account cannot administer that Network role.');
        }
    }

    /** The active, system Network role named $roleKey, or null. */
    public function networkRole(string $roleKey): ?Role
    {
        $role = Role::query()->where('key', $roleKey)->where('scope', Role::SCOPE_GROUP)->where('is_system', true)->whereNull('retired_at')->first();

        return $role !== null && self::classOf($role) !== null ? $role : null;
    }

    public function grant(User $actor, SchoolGroup $group, string $userIdentifier, string $roleKey, ?string $regionId, ?string $zoneId, NetworkRoleChangeReason $reason, FreshMfaProof $mfa): GroupRoleAssignment
    {
        $role = $this->networkRole($roleKey);
        if ($role === null) {
            throw ValidationException::withMessages(['role' => 'That is not a Network role.']);
        }
        $class = (string) self::classOf($role);
        $this->assertMayAdminister($actor, $group, $class);
        $this->requireProof($actor, $mfa);
        [$regionId, $zoneId] = [$this->uuid($regionId), $this->uuid($zoneId)];
        if ($class !== self::CLASS_OPERATOR && ($regionId !== null || $zoneId !== null)) {
            throw ValidationException::withMessages(['role' => 'Only a Network Viewer can be limited to a Region or Zone.']);
        }
        if ($regionId !== null && $zoneId !== null) {
            throw ValidationException::withMessages(['zone_id' => 'Choose a Region or a Zone, not both.']);
        }
        $user = $this->target($actor, $userIdentifier);

        try {
            return DB::transaction(function () use ($actor, $group, $user, $role, $class, $regionId, $zoneId, $reason, $mfa): GroupRoleAssignment {
                $this->groups->requireActiveLocked($group);
                $authority = $this->requireNow($actor, $group, $class);
                $this->requireScope($group, $regionId, $zoneId);

                $grant = GroupRoleAssignment::query()->create([
                    'user_id' => $user->id,
                    'school_group_id' => $group->id,
                    'role_id' => $role->id,
                    'granted_by_user_id' => $actor->id,
                    'granted_at' => now(),
                    'region_id' => $regionId,
                    'zone_id' => $zoneId,
                ]);

                $this->audit->platform('platform.school_group_grant.granted', actor: $actor, subject: $grant, metadata: [
                    'school_group_id' => $group->id,
                    'user_id' => $user->id,
                    'role_key' => $role->key,
                    'scope_type' => self::scopeType($regionId, $zoneId),
                    'region_id' => $regionId,
                    'zone_id' => $zoneId,
                    'reason_code' => $reason->value,
                    'mfa_verified_at' => $mfa->verifiedAt->toIso8601String(),
                    'authority' => $authority,
                ]);

                return $grant;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['user' => 'That person already holds this role in this Group. Revoke it first to change its scope.']);
        } catch (QueryException $e) {
            if (str_contains($e->getMessage(), 'group_role_assignments:')) {
                throw new AuthorizationException('This action is unauthorized.');
            }
            throw $e;
        }
    }

    public function revoke(User $actor, SchoolGroup $group, GroupRoleAssignment $grant, NetworkRoleChangeReason $reason, FreshMfaProof $mfa): void
    {
        $class = $this->classOfGrant($grant);
        if ($grant->school_group_id !== $group->id || $class === null) {
            throw ValidationException::withMessages(['grant' => 'That is not a Network role of this Group.']);
        }
        $roleKey = (string) Role::query()->whereKey($grant->role_id)->value('key');
        $this->assertMayAdminister($actor, $group, $class);
        $this->requireProof($actor, $mfa);

        DB::transaction(function () use ($actor, $group, $grant, $class, $roleKey, $reason, $mfa): void {
            $locked = GroupRoleAssignment::query()->whereKey($grant->id)->lockForUpdate()->firstOrFail();
            $authority = $this->requireNow($actor, $group, $class);
            if ($locked->revoked_at !== null) {
                throw ValidationException::withMessages(['grant' => 'That grant is already revoked.']);
            }
            $locked->forceFill(['revoked_at' => now(), 'revoked_by_user_id' => $actor->id])->save();

            $this->audit->platform('platform.school_group_grant.revoked', actor: $actor, subject: $locked, metadata: [
                'school_group_id' => $group->id,
                'user_id' => $locked->user_id,
                'role_key' => $roleKey,
                'scope_type' => self::scopeType($locked->region_id, $locked->zone_id),
                'terminated_elevation_count' => 0,
                'reason_code' => $reason->value,
                'mfa_verified_at' => $mfa->verifiedAt->toIso8601String(),
                'authority' => $authority,
            ]);
        });
    }

    /**
     * The Group's active Network-role grants, for the Network Access page --
     * shown only to the platform or a Network Manager of the Group (callers
     * check). Name and work email only (Sensitive, ADR 0080 §10.6).
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function assignments(SchoolGroup $group): Collection
    {
        // Network-role grants only (a role carrying group.network.view) -- never
        // the Group Admins; the address as the product shows it (publicEmail).
        /** @var Collection<int, array<string, mixed>> $rows */
        $rows = GroupRoleAssignment::query()->active()
            ->where('school_group_id', $group->id)
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('role_capabilities')
                ->whereColumn('role_capabilities.role_id', 'group_role_assignments.role_id')
                ->where('role_capabilities.capability_key', GroupGovernanceAccess::VIEW))
            ->with(['user', 'role:id,key,name', 'region:id,name', 'zone:id,name'])
            ->orderBy('granted_at')
            ->get()
            ->map(fn (GroupRoleAssignment $g): array => [
                'id' => $g->id,
                'role' => $g->role->key,
                'roleName' => $g->role->name,
                'userName' => $g->user->name,
                'userEmail' => $g->user->publicEmail(),
                'scope' => self::scopeLabel($g->region_id, $g->zone_id, $g->region?->name, $g->zone?->name),
                'effective' => ! $g->user->isDisabled(),
                'grantedAt' => $g->granted_at->toIso8601String(),
            ]);

        return $rows;
    }

    public static function scopeType(?string $regionId, ?string $zoneId): string
    {
        return $zoneId !== null ? 'zone' : ($regionId !== null ? 'region' : 'whole_group');
    }

    public static function scopeLabel(?string $regionId, ?string $zoneId, ?string $regionName, ?string $zoneName): string
    {
        return match (self::scopeType($regionId, $zoneId)) {
            'zone' => 'Zone: '.$zoneName,
            'region' => 'Region: '.$regionName,
            default => 'Whole Group',
        };
    }

    /** @return string 'platform' or 'network' */
    private function requireNow(User $actor, SchoolGroup $group, string $class): string
    {
        if ($class === self::CLASS_MANAGEMENT) {
            if (! $this->capabilities->holdsPlatformNow($actor, SchoolGroupGovernanceService::MANAGE_GRANTS)) {
                throw new AuthorizationException('This action is unauthorized.');
            }

            return 'platform';
        }

        return $this->access->requireOperatorsManageNow($actor, $group);
    }

    private function requireScope(SchoolGroup $group, ?string $regionId, ?string $zoneId): void
    {
        if ($regionId !== null) {
            $region = SchoolGroupRegion::query()->where('school_group_id', $group->id)->whereKey($regionId)->sharedLock()->first();
            if ($region === null || ! $region->isActive()) {
                throw ValidationException::withMessages(['region_id' => 'Choose an active Region of this Group.']);
            }
        }
        if ($zoneId !== null) {
            $zone = SchoolGroupZone::query()->where('school_group_id', $group->id)->whereKey($zoneId)->sharedLock()->first();
            if ($zone === null || ! $zone->isActive()) {
                throw ValidationException::withMessages(['zone_id' => 'Choose an active Zone of this Group.']);
            }
        }
    }

    private function target(User $actor, string $identifier): User
    {
        $identifier = trim($identifier);
        $user = Str::isUuid($identifier)
            ? User::query()->find(strtolower($identifier))
            : User::query()->where('email', strtolower($identifier))->first();
        if ($user === null || $user->isDisabled()) {
            throw ValidationException::withMessages(['user' => 'No active account has exactly that email or identifier.']);
        }
        if ($user->id === $actor->id) {
            throw ValidationException::withMessages(['user' => 'You cannot grant Network authority to yourself.']);
        }

        return $user;
    }

    private function requireProof(User $actor, FreshMfaProof $mfa): void
    {
        if (! $mfa->isFor($actor->id)) {
            throw new AuthorizationException('This action is unauthorized.');
        }
    }

    private function uuid(?string $value): ?string
    {
        $value = $value !== null ? strtolower(trim($value)) : null;

        return $value === '' ? null : $value;
    }
}
