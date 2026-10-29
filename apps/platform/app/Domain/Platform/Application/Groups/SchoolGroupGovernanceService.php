<?php

namespace App\Domain\Platform\Application\Groups;

use App\Domain\Platform\Application\Elevation\ElevationEndReason;
use App\Domain\Platform\Application\Elevation\ElevationTargetResolver;
use App\Domain\Platform\Application\Elevation\SchoolElevationService;
use App\Models\GroupRoleAssignment;
use App\Models\Role;
use App\Models\School;
use App\Models\SchoolGroup;
use App\Models\SchoolGroupRegion;
use App\Models\SchoolGroupZone;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Auth\Mfa\FreshMfaProof;
use App\Support\Authorization\CapabilityResolver;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Phase 0N.5 (ADR 0045 sections 5, 8, 10, 11): the platform-governed
 * School Group layer -- the ONLY code that creates, renames or archives a
 * Group, changes which Schools belong to one, or grants and revokes Group
 * authority. Each operation re-checks its platform capability here (the
 * route middleware is not the only gate), audits to the platform ledger
 * with identifiers only, and -- where it removes Group authority --
 * terminates the dependent Group-derived elevations in the same
 * transaction.
 *
 * Group Admins hold no capability that reaches any of this. Nothing here
 * creates, reads or changes a School membership, a School role or any
 * tenant data, and nothing enters TenantContext.
 */
class SchoolGroupGovernanceService
{
    public const VIEW = 'platform.school_groups.view';

    public const MANAGE = 'platform.school_groups.manage';

    public const MANAGE_GRANTS = 'platform.school_group_grants.manage';

    public function __construct(
        private readonly CapabilityResolver $capabilities,
        private readonly ElevationTargetResolver $schools,
        private readonly SchoolElevationService $elevations,
        private readonly AuditRecorder $audit,
        private readonly GroupGovernanceAccess $access,
    ) {}

    public function create(User $actor, string $name, string $slug): SchoolGroup
    {
        $this->require($actor, self::MANAGE);

        $name = trim($name);
        $slug = strtolower(trim($slug));

        if ($name === '' || mb_strlen($name) > 150) {
            throw ValidationException::withMessages(['name' => 'Enter a Group name (at most 150 characters).']);
        }

        if (preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $slug) !== 1 || strlen($slug) > 100) {
            throw ValidationException::withMessages(['slug' => 'Use lowercase letters, digits and single hyphens.']);
        }

        try {
            return DB::transaction(function () use ($actor, $name, $slug): SchoolGroup {
                $group = SchoolGroup::query()->create(['name' => $name, 'slug' => $slug]);
                $this->audit->platform('platform.school_group.created', actor: $actor, subject: $group);

                return $group;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['slug' => 'That slug is already used by another Group.']);
        }
    }

    public function rename(User $actor, SchoolGroup $group, string $name): void
    {
        $this->require($actor, self::MANAGE);
        $this->requireActive($group);

        $name = trim($name);

        if ($name === '' || mb_strlen($name) > 150) {
            throw ValidationException::withMessages(['name' => 'Enter a Group name (at most 150 characters).']);
        }

        DB::transaction(function () use ($actor, $group, $name): void {
            $group->forceFill(['name' => $name])->save();
            // Names are never copied into audit (ADR 0045 section 11).
            $this->audit->platform('platform.school_group.renamed', actor: $actor, subject: $group);
        });
    }

    /**
     * Archive (never delete): the Group stops being a source of authority.
     * Every unrevoked grant is revoked (each audited) and every active
     * elevation it authorized is terminated (group_inactive).
     */
    public function archive(User $actor, SchoolGroup $group): void
    {
        $this->require($actor, self::MANAGE);

        DB::transaction(function () use ($actor, $group): void {
            $archived = SchoolGroup::query()->whereKey($group->id)
                ->where('status', SchoolGroup::STATUS_ACTIVE)
                ->update(['status' => SchoolGroup::STATUS_ARCHIVED, 'updated_at' => now()]);

            if ($archived !== 1) {
                throw ValidationException::withMessages(['group' => 'That Group is already archived.']);
            }

            $revoked = 0;
            $terminated = 0;

            foreach (GroupRoleAssignment::query()->active()->where('school_group_id', $group->id)->lockForUpdate()->get() as $grant) {
                $ended = $this->revokeGrant($actor, $grant, ElevationEndReason::GroupInactive);

                if ($ended !== null) {
                    $revoked++;
                    $terminated += $ended;
                }
            }

            // Anything still active under this Group (defence in depth: every
            // Group-derived elevation names one of the grants revoked above).
            $terminated += $this->elevations->terminateWhere(
                fn (Builder $q) => $q->where('school_group_id', $group->id),
                ElevationEndReason::GroupInactive,
            );

            $this->audit->platform('platform.school_group.archived', actor: $actor, subject: $group, metadata: [
                'revoked_grant_count' => $revoked,
                'terminated_elevation_count' => $terminated,
            ]);
        });

        $group->refresh();
    }

    /**
     * Adds ONE exactly identified School (UUID or verified domain) to the Group.
     *
     * ORG.12D (ADR 0080 §9, OD-12.12): an assured change -- a classified
     * relationship (every new membership is classified), a closed reason, a
     * FreshMfaProof minted after the capability check, and the platform
     * capability re-read fresh inside the transaction. The membership grants
     * nothing in the School.
     */
    public function addSchool(User $actor, SchoolGroup $group, string $identifier, SchoolGroupRelationship $relationship, SchoolGroupMembershipReason $reason, FreshMfaProof $mfa, ?string $networkReference = null): School
    {
        $this->require($actor, self::MANAGE);
        $this->requireActive($group);
        $this->requireProof($actor, $mfa);
        $networkReference = $this->networkReference($networkReference);

        [$school] = $this->schools->resolve($identifier);

        if (! $school instanceof School) {
            throw ValidationException::withMessages(['school' => 'No School has exactly that identifier.']);
        }

        try {
            DB::transaction(function () use ($actor, $group, $school, $relationship, $reason, $mfa, $networkReference): void {
                $this->requireActiveLocked($group);
                $this->requireNow($actor);
                DB::table('school_group_members')->insert([
                    'id' => (string) Str::uuid7(),
                    'school_group_id' => $group->id,
                    'school_id' => $school->id,
                    'relationship_type' => $relationship->value,
                    'network_reference' => $networkReference,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $this->audit->platform('platform.school_group.school_added', actor: $actor, subject: $group, metadata: [
                    'school_id' => $school->id,
                    'relationship_type' => $relationship->value,
                    'reason_code' => $reason->value,
                    'mfa_verified_at' => $mfa->verifiedAt->toIso8601String(),
                ]);
            });
        } catch (UniqueConstraintViolationException $e) {
            // The constraint name, never the message (which carries the whole INSERT).
            throw ValidationException::withMessages(str_contains((string) ($e->errorInfo[2] ?? ''), 'school_group_members_network_reference_unique')
                ? ['network_reference' => 'That network reference is already used in this Group.']
                : ['school' => 'That School is already in this Group.']);
        }

        return $school;
    }

    /**
     * Removes the School from THIS Group only. Any active elevation that
     * Group authorized into that School is terminated (school_left_group) in
     * the same transaction; the School's memberships, roles and data are
     * untouched, as is its membership of any other Group.
     *
     * ORG.12D: assured like addSchool() (closed reason, fresh MFA, fresh
     * authority).
     */
    public function removeSchool(User $actor, SchoolGroup $group, School $school, SchoolGroupMembershipReason $reason, FreshMfaProof $mfa): void
    {
        $this->requireMayManage($actor, $group);
        $this->requireProof($actor, $mfa);

        DB::transaction(function () use ($actor, $group, $school, $reason, $mfa): void {
            $authority = $this->access->requireManageNow($actor, $group);
            // Waits for any in-flight Group-derived start holding this row
            // FOR SHARE, so that start is visible to the termination below.
            $removed = DB::table('school_group_members')
                ->where('school_group_id', $group->id)
                ->where('school_id', $school->id)
                ->delete();

            if ($removed !== 1) {
                throw ValidationException::withMessages(['school' => 'That School is not in this Group.']);
            }

            $terminated = $this->elevations->terminateWhere(
                fn (Builder $q) => $q->where('school_group_id', $group->id)->where('school_id', $school->id),
                ElevationEndReason::SchoolLeftGroup,
            );

            $this->audit->platform('platform.school_group.school_removed', actor: $actor, subject: $group, metadata: [
                'school_id' => $school->id,
                'terminated_elevation_count' => $terminated,
                'reason_code' => $reason->value,
                'mfa_verified_at' => $mfa->verifiedAt->toIso8601String(),
                'authority' => $authority,
            ]);
        });
    }

    /**
     * ORG.12D (ADR 0080 §9, OD-12.12): changes a member School's relationship
     * classification and/or its Group-specific network reference -- assured
     * (closed reason, fresh MFA, fresh authority) and stale-safe: the values
     * the form opened with must still be stored. Changes nothing in the
     * School. Returns the changed fields ([] when nothing changed).
     *
     * @param  array{relationship_type: ?string, network_reference: ?string}  $expected
     * @return list<string>
     */
    public function classifyMembership(User $actor, SchoolGroup $group, School $school, SchoolGroupRelationship $relationship, ?string $networkReference, array $expected, SchoolGroupMembershipReason $reason, FreshMfaProof $mfa): array
    {
        $this->requireMayManage($actor, $group);
        $this->requireActive($group);
        $this->requireProof($actor, $mfa);
        $networkReference = $this->networkReference($networkReference);

        try {
            return DB::transaction(function () use ($actor, $group, $school, $relationship, $networkReference, $expected, $reason, $mfa): array {
                $this->requireActiveLocked($group);
                $member = $this->lockedMember($group, $school);
                $authority = $this->access->requireManageNow($actor, $group);
                if (($expected['relationship_type'] ?? null) !== $member->relationship_type || $this->networkReference($expected['network_reference'] ?? null) !== $member->network_reference) {
                    throw ValidationException::withMessages(['membership' => 'This School\'s classification changed since you opened the form. Reload and try again.']);
                }

                $after = ['relationship_type' => $relationship->value, 'network_reference' => $networkReference];
                $changed = array_keys(array_filter($after, fn ($value, string $key): bool => $value !== $member->{$key}, ARRAY_FILTER_USE_BOTH));
                if ($changed === []) {
                    return [];
                }

                DB::table('school_group_members')->where('id', $member->id)->update([...$after, 'updated_at' => now()]);
                $this->audit->platform('platform.school_group.membership_classified', actor: $actor, subject: $group, metadata: [
                    'school_id' => $school->id,
                    'changed' => $changed,
                    'relationship_before' => $member->relationship_type,
                    'relationship_after' => $relationship->value,
                    'reason_code' => $reason->value,
                    'mfa_verified_at' => $mfa->verifiedAt->toIso8601String(),
                    'authority' => $authority,
                ]);

                return $changed;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['network_reference' => 'That network reference is already used in this Group.']);
        }
    }

    /**
     * ORG.12D (ADR 0080 §9): places a member School in a Region and/or Zone of
     * THIS Group (or clears them) -- a closed reason and the fresh platform
     * authority; no MFA (OD-12.12 keeps MFA for classification). A Zone sets
     * its Region; a new placement must be active (an existing one stays when
     * its Region/Zone is inactivated). Stale-safe like classifyMembership().
     *
     * @param  array{region_id: ?string, zone_id: ?string}  $expected
     * @return list<string>
     */
    public function placeMembership(User $actor, SchoolGroup $group, School $school, ?string $regionId, ?string $zoneId, array $expected, SchoolGroupMembershipReason $reason): array
    {
        $this->requireMayManage($actor, $group);
        $this->requireActive($group);

        // UUIDs compare case-sensitively; PostgreSQL returns lower case.
        [$regionId, $zoneId] = [$regionId !== null ? strtolower($regionId) : null, $zoneId !== null ? strtolower($zoneId) : null];
        $expected = array_map(fn ($id) => is_string($id) ? strtolower($id) : $id, $expected);

        return DB::transaction(function () use ($actor, $group, $school, $regionId, $zoneId, $expected, $reason): array {
            $this->requireActiveLocked($group);
            $member = $this->lockedMember($group, $school);
            $authority = $this->access->requireManageNow($actor, $group);
            if (($expected['region_id'] ?? null) !== $member->region_id || ($expected['zone_id'] ?? null) !== $member->zone_id) {
                throw ValidationException::withMessages(['membership' => 'This School\'s placement changed since you opened the form. Reload and try again.']);
            }

            if ($zoneId !== null) {
                $zone = SchoolGroupZone::query()->where('school_group_id', $group->id)->whereKey($zoneId)->sharedLock()->first();
                if ($zone === null) {
                    throw ValidationException::withMessages(['zone_id' => 'Choose a Zone of this Group.']);
                }
                if ($regionId !== null && $regionId !== $zone->region_id) {
                    throw ValidationException::withMessages(['zone_id' => 'That Zone belongs to another Region.']);
                }
                $regionId = $zone->region_id;
                if ($zoneId !== $member->zone_id && ! $zone->isActive()) {
                    throw ValidationException::withMessages(['zone_id' => 'Choose an active Zone.']);
                }
            }
            if ($regionId !== null) {
                $region = SchoolGroupRegion::query()->where('school_group_id', $group->id)->whereKey($regionId)->sharedLock()->first();
                if ($region === null) {
                    throw ValidationException::withMessages(['region_id' => 'Choose a Region of this Group.']);
                }
                if ($regionId !== $member->region_id && ! $region->isActive()) {
                    throw ValidationException::withMessages(['region_id' => 'Choose an active Region.']);
                }
            }

            $after = ['region_id' => $regionId, 'zone_id' => $zoneId];
            $changed = array_keys(array_filter($after, fn ($value, string $key): bool => $value !== $member->{$key}, ARRAY_FILTER_USE_BOTH));
            if ($changed === []) {
                return [];
            }

            DB::table('school_group_members')->where('id', $member->id)->update([...$after, 'updated_at' => now()]);
            $this->audit->platform('platform.school_group.membership_placed', actor: $actor, subject: $group, metadata: [
                'school_id' => $school->id,
                'region_before' => $member->region_id,
                'region_after' => $regionId,
                'zone_before' => $member->zone_id,
                'zone_after' => $zoneId,
                'reason_code' => $reason->value,
                'authority' => $authority,
            ]);

            return $changed;
        });
    }

    /** The early, cached gate: refuse before a fresh MFA code is ever spent. */
    /** Platform-only steps (create, rename, archive, add a School): the platform capability. */
    public function assertMayManage(User $actor): void
    {
        $this->require($actor, self::MANAGE);
    }

    /**
     * ORG.12E (ADR 0080 §10.4): the steps a whole-Group Network Manager may
     * also take for its OWN Group -- remove, classify, place (the cached
     * floor; each re-reads inside its transaction).
     */
    public function requireMayManage(User $actor, SchoolGroup $group): void
    {
        if (! $this->access->mayManage($actor, $group)) {
            throw new AccessDeniedHttpException('This account cannot govern this School Group.');
        }
    }

    private function requireNow(User $actor): void
    {
        if (! $this->capabilities->holdsPlatformNow($actor, self::MANAGE)) {
            throw new AuthorizationException('This action is unauthorized.');
        }
    }

    private function requireProof(User $actor, FreshMfaProof $mfa): void
    {
        if (! $mfa->isFor($actor->id)) {
            throw new AuthorizationException('This action is unauthorized.');
        }
    }

    private function lockedMember(SchoolGroup $group, School $school): object
    {
        $member = DB::table('school_group_members')->where('school_group_id', $group->id)->where('school_id', $school->id)->lockForUpdate()->first();
        if ($member === null) {
            throw ValidationException::withMessages(['school' => 'That School is not in this Group.']);
        }

        return $member;
    }

    private function networkReference(?string $value): ?string
    {
        $value = $value === null ? null : strtoupper(trim($value));
        if ($value === null || $value === '') {
            return null;
        }
        if (preg_match('/^[A-Z0-9][A-Z0-9._\/-]{0,39}$/', $value) !== 1) {
            throw ValidationException::withMessages(['network_reference' => 'Use up to 40 letters, digits, dots, hyphens, underscores or slashes.']);
        }

        return $value;
    }

    /**
     * Grants $roleKey (a `group`-scope role) in the Group to the user named
     * by exact email or UUID. Never to oneself (also a database CHECK).
     */
    public function grant(User $actor, SchoolGroup $group, string $userIdentifier, string $roleKey = 'group_admin'): GroupRoleAssignment
    {
        $this->require($actor, self::MANAGE_GRANTS);
        $this->requireActive($group);

        $identifier = trim($userIdentifier);
        $user = Str::isUuid($identifier)
            ? User::query()->find(strtolower($identifier))
            : User::query()->where('email', strtolower($identifier))->first();

        if ($user === null) {
            throw ValidationException::withMessages(['user' => 'No account has exactly that email or identifier.']);
        }

        if ($user->id === $actor->id) {
            throw ValidationException::withMessages(['user' => 'You cannot grant Group authority to yourself.']);
        }

        $role = Role::query()->where('key', $roleKey)->where('scope', 'group')->first();

        if ($role === null) {
            throw ValidationException::withMessages(['role' => 'That is not a Group role.']);
        }
        // ORG.12E: Network roles have their own assured path (reason, fresh MFA).
        if (NetworkAccessService::classOf($role) !== null) {
            throw ValidationException::withMessages(['role' => 'Network roles are granted through Network role administration.']);
        }

        try {
            return DB::transaction(function () use ($actor, $group, $user, $role): GroupRoleAssignment {
                $grant = GroupRoleAssignment::query()->create([
                    'user_id' => $user->id,
                    'school_group_id' => $group->id,
                    'role_id' => $role->id,
                    'granted_by_user_id' => $actor->id,
                    'granted_at' => now(),
                ]);

                $this->audit->platform('platform.school_group_grant.granted', actor: $actor, subject: $grant, metadata: [
                    'school_group_id' => $group->id,
                    'user_id' => $user->id,
                    'role_key' => $role->key,
                ]);

                return $grant;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['user' => 'That person already holds this role in this Group.']);
        }
    }

    /**
     * Revokes the grant (kept as history) and terminates every active
     * elevation it authorized (group_authority_revoked).
     */
    public function revoke(User $actor, GroupRoleAssignment $grant): void
    {
        $this->require($actor, self::MANAGE_GRANTS);
        // ORG.12E: a Network role is revoked only through its assured path.
        $role = Role::query()->find($grant->role_id);
        if ($role !== null && NetworkAccessService::classOf($role) !== null) {
            throw ValidationException::withMessages(['grant' => 'Network roles are revoked through Network role administration.']);
        }

        DB::transaction(function () use ($actor, $grant): void {
            if ($this->revokeGrant($actor, $grant, ElevationEndReason::GroupAuthorityRevoked) === null) {
                throw ValidationException::withMessages(['grant' => 'That grant is already revoked.']);
            }
        });
    }

    /**
     * @return int|null the number of elevations terminated, or null when the
     *                  grant was already revoked
     */
    private function revokeGrant(User $actor, GroupRoleAssignment $grant, ElevationEndReason $reason): ?int
    {
        // Waits for any in-flight Group-derived start holding this grant FOR
        // SHARE, so that start is visible to the termination below.
        $revoked = GroupRoleAssignment::query()->whereKey($grant->id)->active()
            ->update(['revoked_at' => now(), 'revoked_by_user_id' => $actor->id, 'updated_at' => now()]);

        if ($revoked !== 1) {
            return null;
        }

        $terminated = $this->elevations->terminateWhere(
            fn (Builder $q) => $q->where('group_role_assignment_id', $grant->id),
            $reason,
        );

        $this->audit->platform('platform.school_group_grant.revoked', actor: $actor, subject: $grant, metadata: [
            'school_group_id' => $grant->school_group_id,
            'user_id' => $grant->user_id,
            'terminated_elevation_count' => $terminated,
        ]);

        return $terminated;
    }

    private function require(User $actor, string $capability): void
    {
        if (! $this->capabilities->canPlatform($actor, $capability)) {
            throw new AccessDeniedHttpException('This account cannot govern School Groups.');
        }
    }

    private function requireActive(SchoolGroup $group): void
    {
        if (SchoolGroup::query()->whereKey($group->id)->value('status') !== SchoolGroup::STATUS_ACTIVE) {
            throw ValidationException::withMessages(['group' => 'That Group is archived.']);
        }
    }

    /**
     * ORG.12D: the in-transaction re-check -- FOR SHARE conflicts with
     * archive()'s UPDATE, so a concurrent archive is ordered before or after
     * this write, never between the check and the write.
     */
    public function requireActiveLocked(SchoolGroup $group): void
    {
        if (SchoolGroup::query()->whereKey($group->id)->sharedLock()->value('status') !== SchoolGroup::STATUS_ACTIVE) {
            throw ValidationException::withMessages(['group' => 'That Group is archived.']);
        }
    }
}
