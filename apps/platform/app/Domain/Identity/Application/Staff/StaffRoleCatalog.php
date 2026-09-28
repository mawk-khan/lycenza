<?php

namespace App\Domain\Identity\Application\Staff;

use App\Models\Role;
use App\Models\School;
use App\Models\User;
use App\Support\Authorization\CapabilityResolver;
use Illuminate\Support\Collection;

/**
 * Phase 0O.12B (ADR 0059 sections 6, 6.1; owner amendment): who may manage
 * staff accounts, and which School roles they may grant.
 *
 * Capabilities (existing, `school_admin` only by default -- never a role
 * name, CLAUDE.md rule 24):
 * - invite, suspend (off-board), reactivate: `school.members.manage` AND
 *   `school.roles.manage`;
 * - resend / revoke an invitation: `school.members.manage`;
 * - grant / revoke one School role: `school.roles.manage`;
 * - view: `school.members.view`.
 *
 * Roles: only `scope = 'school'` roles (the closed School catalog; the
 * database triggers also refuse any other scope), resolved server-side from
 * keys -- never a client-supplied id, never a platform or Group role, never
 * a wildcard -- and only roles whose every capability the actor holds in
 * that School right now (no escalation).
 */
final class StaffRoleCatalog
{
    public const VIEW = 'school.members.view';

    public const MEMBERS = 'school.members.manage';

    public const ROLES = 'school.roles.manage';

    public function __construct(private readonly CapabilityResolver $capabilities) {}

    public function canView(User $actor, School $school): bool
    {
        return $this->holds($actor, $school, [self::VIEW]);
    }

    public function canManageMembers(User $actor, School $school): bool
    {
        return $this->holds($actor, $school, [self::MEMBERS]);
    }

    public function canManageRoles(User $actor, School $school): bool
    {
        return $this->holds($actor, $school, [self::ROLES]);
    }

    public function canAdminister(User $actor, School $school): bool
    {
        return $this->holds($actor, $school, [self::MEMBERS, self::ROLES]);
    }

    /** Invite, off-board, reactivate. @throws StaffAccountException */
    public function requireIssuer(User $actor, School $school): void
    {
        $this->requireFresh($actor, $school, [self::MEMBERS, self::ROLES]);
    }

    /** Resend / revoke an invitation. @throws StaffAccountException */
    public function requireMemberManager(User $actor, School $school): void
    {
        $this->requireFresh($actor, $school, [self::MEMBERS]);
    }

    /** Grant / revoke one role. @throws StaffAccountException */
    public function requireRoleManager(User $actor, School $school): void
    {
        $this->requireFresh($actor, $school, [self::ROLES]);
    }

    /**
     * The closed School role catalog, with whether $actor may grant each.
     *
     * @return list<array{key: string, name: string, grantable: bool}>
     */
    public function catalogFor(User $actor, School $school): array
    {
        $held = $this->fresh($actor, $school);

        return $this->schoolRoles()
            ->map(fn (Role $role) => [
                'key' => $role->key,
                'name' => $role->name,
                'grantable' => $this->within($role, $held),
            ])->values()->all();
    }

    /**
     * Resolves role keys to School roles the actor may grant.
     *
     * @param  list<mixed>  $roleKeys
     * @return list<Role>
     *
     * @throws StaffAccountException
     */
    public function grantable(User $actor, School $school, array $roleKeys): array
    {
        $keys = array_values(array_unique(array_filter($roleKeys, fn ($k) => is_string($k) && $k !== '')));

        if ($keys === [] || count($keys) !== count($roleKeys)) {
            throw new StaffAccountException($keys === [] ? 'roles_required' : 'role_unknown');
        }

        $roles = $this->schoolRoles()->whereIn('key', $keys)->values();

        if ($roles->count() !== count($keys)) {
            throw new StaffAccountException('role_unknown');
        }

        $held = $this->fresh($actor, $school);

        foreach ($roles as $role) {
            if (! $this->within($role, $held)) {
                throw new StaffAccountException('role_escalation');
            }
        }

        return $roles->all();
    }

    /**
     * Whether every one of $roles is still within $actor's capabilities in
     * $school -- re-checked when an invitation is accepted.
     *
     * @param  iterable<Role>  $roles
     */
    public function stillGrantable(User $actor, School $school, iterable $roles): bool
    {
        $held = $this->fresh($actor, $school);

        foreach ($roles as $role) {
            if ($role->scope !== 'school' || ! $this->within($role, $held)) {
                return false;
            }
        }

        return true;
    }

    /** @return Collection<int, Role> */
    private function schoolRoles(): Collection
    {
        return Role::query()->where('scope', 'school')->with('capabilities')->orderBy('name')->get();
    }

    /**
     * @param  list<string>  $held
     */
    private function within(Role $role, array $held): bool
    {
        return array_diff($role->capabilities->pluck('key')->all(), $held) === [];
    }

    /** @return list<string> */
    private function fresh(User $actor, School $school): array
    {
        $this->capabilities->forgetCache($actor, $school);

        return array_values($this->capabilities->schoolCapabilities($actor, $school));
    }

    /**
     * A mutation is authorized on FRESH capabilities (cache forgotten), so a
     * grant revoked a moment ago never still authorizes one.
     *
     * @param  list<string>  $capabilities
     *
     * @throws StaffAccountException
     */
    private function requireFresh(User $actor, School $school, array $capabilities): void
    {
        if ($actor->isDisabled() || array_diff($capabilities, $this->fresh($actor, $school)) !== []) {
            throw new StaffAccountException('not_authorized');
        }
    }

    /**
     * @param  list<string>  $capabilities
     */
    private function holds(User $actor, School $school, array $capabilities): bool
    {
        if ($actor->isDisabled()) {
            return false;
        }

        return array_diff($capabilities, $this->capabilities->schoolCapabilities($actor, $school)) === [];
    }
}
