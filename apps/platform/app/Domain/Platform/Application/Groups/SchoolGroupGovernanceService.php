<?php

namespace App\Domain\Platform\Application\Groups;

use App\Domain\Platform\Application\Elevation\ElevationEndReason;
use App\Domain\Platform\Application\Elevation\ElevationTargetResolver;
use App\Domain\Platform\Application\Elevation\SchoolElevationService;
use App\Models\GroupRoleAssignment;
use App\Models\Role;
use App\Models\School;
use App\Models\SchoolGroup;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Authorization\CapabilityResolver;
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

    /** Adds ONE exactly identified School (UUID or verified domain) to the Group. */
    public function addSchool(User $actor, SchoolGroup $group, string $identifier): School
    {
        $this->require($actor, self::MANAGE);
        $this->requireActive($group);

        [$school] = $this->schools->resolve($identifier);

        if (! $school instanceof School) {
            throw ValidationException::withMessages(['school' => 'No School has exactly that identifier.']);
        }

        try {
            DB::transaction(function () use ($actor, $group, $school): void {
                DB::table('school_group_members')->insert([
                    'id' => (string) Str::uuid7(),
                    'school_group_id' => $group->id,
                    'school_id' => $school->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $this->audit->platform('platform.school_group.school_added', actor: $actor, subject: $group, metadata: ['school_id' => $school->id]);
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['school' => 'That School is already in this Group.']);
        }

        return $school;
    }

    /**
     * Removes the School from THIS Group only. Any active elevation that
     * Group authorized into that School is terminated (school_left_group) in
     * the same transaction; the School's memberships, roles and data are
     * untouched, as is its membership of any other Group.
     */
    public function removeSchool(User $actor, SchoolGroup $group, School $school): void
    {
        $this->require($actor, self::MANAGE);

        DB::transaction(function () use ($actor, $group, $school): void {
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
            ]);
        });
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
}
