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
 * - view the staff list: `school.members.view`;
 * - view the role catalogue and role assignments: `school.roles.view`
 *   (SR.2, ADR 0071 §10.4).
 *
 * Roles: only SYSTEM `scope = 'school'` roles (the closed School catalog; the
 * database triggers also refuse any other scope), resolved server-side from
 * keys -- never a client-supplied id, never a platform or Group role, never
 * a wildcard. WHO may grant or revoke which role is RoleGrantAuthority's
 * decision (SR.2, ADR 0071 §6.2: held or covered by a held grant right),
 * taken inside each mutation's transaction.
 */
final class StaffRoleCatalog
{
    public const VIEW = 'school.members.view';

    public const MEMBERS = 'school.members.manage';

    public const ROLES = 'school.roles.manage';

    public const VIEW_ROLES = 'school.roles.view';

    public function __construct(
        private readonly CapabilityResolver $capabilities,
        private readonly RoleGrantAuthority $authority,
    ) {}

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
     * SR.2 (ADR 0071 §10.4): viewing the role catalogue and role assignments.
     * Confers nothing else -- no grant, revoke or catalogue authority.
     */
    public function canViewRoles(User $actor, School $school): bool
    {
        return $this->holds($actor, $school, [self::VIEW_ROLES]);
    }

    /**
     * The offered School role catalogue, with whether $actor may grant each
     * now (RoleGrantAuthority, display only -- every mutation decides again
     * inside its transaction). Each entry: key, name, the `grantable` flag,
     * and (SR.3, ADR 0071 §20) its presentation from StaffRolePresentation --
     * functional group and group label, purpose, add-on marker and
     * sensitivity labels derived from capability CLASSES -- in a stable
     * functional order. Never a role's capability keys, grant rights or
     * another person's authority. Display only -- never an authorization
     * input.
     *
     * @return list<array{key: string, name: string, grantable: bool, group: string, groupLabel: string, purpose: ?string, addOn: bool, sensitivity: list<string>}>
     */
    public function catalogFor(User $actor, School $school): array
    {
        $roles = $this->schoolRoles();
        $decisions = $this->authority->forCatalogue($actor, $school, $roles);

        return $roles
            ->map(function (Role $role) use ($decisions): array {
                $presentation = StaffRolePresentation::describe($role->key, $role->capabilities->pluck('key')->all());

                return [
                    'key' => $role->key,
                    'name' => $role->name,
                    'grantable' => $decisions[$role->id]->allowed(),
                    'group' => $presentation['group'],
                    'groupLabel' => $presentation['groupLabel'],
                    'purpose' => $presentation['purpose'],
                    'addOn' => $presentation['addOn'],
                    'sensitivity' => $presentation['sensitivity'],
                    '_order' => $presentation['order'],
                ];
            })
            ->sortBy([['_order', 'asc'], ['name', 'asc']])
            ->map(function (array $entry): array {
                unset($entry['_order']);

                return $entry;
            })
            ->values()->all();
    }

    /**
     * Resolves submitted role keys to SYSTEM School-scope roles -- validation
     * only (`roles_required` / `role_unknown`, never audited). Retired and
     * emptied system roles resolve, so the authority decision refuses them
     * explicitly (`role_unavailable`, audited) instead of hiding the reason;
     * a non-system, Guardian, Group or platform key is simply unknown.
     *
     * @param  list<mixed>  $roleKeys
     * @return list<Role>
     *
     * @throws StaffAccountException
     */
    public function resolve(array $roleKeys): array
    {
        $keys = array_values(array_unique(array_filter($roleKeys, fn ($k) => is_string($k) && $k !== '')));

        if ($keys === [] || count($keys) !== count($roleKeys)) {
            throw new StaffAccountException($keys === [] ? 'roles_required' : 'role_unknown');
        }

        $roles = Role::query()
            ->where('scope', 'school')
            ->where('is_system', true)
            ->whereIn('key', $keys)
            ->orderBy('key')
            ->get();

        if ($roles->count() !== count($keys)) {
            throw new StaffAccountException('role_unknown');
        }

        return $roles->all();
    }

    /**
     * SR.1 (ADR 0071 §10.1, §11.5): the staff catalogue is the School-scope,
     * SYSTEM, non-retired roles with at least one capability -- never a
     * Guardian/platform/Group role, a retired role, an empty role or a
     * non-system (demo/test) row.
     *
     * @return Collection<int, Role>
     */
    private function schoolRoles(): Collection
    {
        return Role::query()
            ->where('scope', 'school')
            ->where('is_system', true)
            ->whereNull('retired_at')
            ->whereHas('capabilities')
            ->with('capabilities')
            ->orderBy('name')
            ->get();
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
