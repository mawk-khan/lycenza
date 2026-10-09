<?php

namespace App\Domain\Identity\Application\Staff;

use App\Models\MembershipRoleAssignment;
use App\Models\Role;
use App\Models\School;
use App\Models\SchoolMembership;
use App\Models\User;
use App\Support\Authorization\CapabilityClasses;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * SR.2 (ADR 0071 §6.2, §10): the ONE application decision of who may grant
 * (or revoke) a School role -- the application mirror of the SR.1 database
 * grantor trigger (`assert_membership_role_assignment_grantor`), which stays
 * the final backstop.
 *
 * A School role may be granted by an issuer in a School only when:
 * 1. the role is not retired and carries at least one capability (grant
 *    only; a retired or emptied role stays REVOCABLE);
 * 2. the issuer is not the grantee (self-administration);
 * 3. the issuer is an enabled User with an ACTIVE membership in THIS School;
 * 4. the issuer HOLDS `school.roles.manage` there (held, never covered);
 * 5. every capability of the role is either held by the issuer there, or
 *    covered by a grant right (`capabilities.grant_right`) the issuer holds.
 *
 * "Held" is read FRESH from the database -- never the capability cache --
 * and only from the issuer's active `school`-scope grants: a `guardian`-scope
 * grant never counts (exactly the trigger's join). With `$lock` (inside the
 * mutation transaction, after the target's locks) the issuer's membership and
 * active grants are read FOR SHARE, as the trigger does, so a concurrent
 * revocation of the issuer's authority (or of a grant right) either commits
 * first and the grant is refused, or waits for the grant to commit.
 *
 * Capability tests only; no role key is ever an authorization input
 * (CLAUDE.md rule 24). The ADR 0047 bootstrap is not a School-issuer grant
 * and never reaches this class (SchoolBootstrapAdministrationService).
 */
final class RoleGrantAuthority
{
    public const ROLE_MANAGER = 'school.roles.manage';

    public function __construct(private readonly TenantContext $context) {}

    /** Grant (invitation issue/resend/acceptance, grantRole, reactivation). */
    public function forGrant(User $issuer, School $school, Role $role, ?string $granteeUserId = null, bool $lock = false): RoleGrantDecision
    {
        [$retired, $capabilities] = $this->roleState($role);

        if ($retired) {
            return RoleGrantDecision::refuse('retired');
        }

        if ($capabilities === []) {
            return RoleGrantDecision::refuse('empty_role');
        }

        return $this->coverage($issuer, $capabilities, $granteeUserId, fn (): ?array => $this->held($issuer, $school, $lock));
    }

    /**
     * Revoke ONE role (ADR 0071 §10.2): the issuer must be able to grant it
     * now -- but a retired or emptied role stays revocable (retirement stops
     * new grants only). Whole-membership off-boarding is NOT subject to this
     * (§10.3): it is the emergency path that always removes every staff role.
     */
    public function forRevoke(User $issuer, School $school, Role $role, ?string $holderUserId = null, bool $lock = false): RoleGrantDecision
    {
        [, $capabilities] = $this->roleState($role);

        return $this->coverage($issuer, $capabilities, $holderUserId, fn (): ?array => $this->held($issuer, $school, $lock));
    }

    /**
     * Catalogue flags: one fresh, unlocked read of the issuer's authority for
     * every offered role (display only; every mutation decides again inside
     * its transaction).
     *
     * @param  iterable<Role>  $roles
     * @return array<string, RoleGrantDecision> role id => decision
     */
    public function forCatalogue(User $issuer, School $school, iterable $roles): array
    {
        $held = $this->held($issuer, $school);
        $decisions = [];

        foreach ($roles as $role) {
            $decisions[$role->id] = $this->forGrantWithHeld($issuer, $role, $held);
        }

        return $decisions;
    }

    /**
     * The issuer's capabilities in $school from their ACTIVE membership's
     * active `school`-scope grants, or null when they have no authority there
     * at all (disabled, or no active membership in this School).
     *
     * @return list<string>|null
     */
    public function held(User $issuer, School $school, bool $lock = false): ?array
    {
        if (! User::query()->whereKey($issuer->id)->where('is_disabled', false)->exists()) {
            return null;
        }

        return $this->context->withSchool($school, function () use ($issuer, $school, $lock): ?array {
            $membership = SchoolMembership::query()
                ->where('school_id', $school->id)
                ->where('user_id', $issuer->id)
                ->active()
                ->when($lock, fn ($query) => $query->sharedLock())
                ->first();

            if ($membership === null) {
                return null;
            }

            if ($lock) {
                MembershipRoleAssignment::query()
                    ->where('school_membership_id', $membership->id)
                    ->active()
                    ->sharedLock()
                    ->pluck('id');
            }

            return DB::table('membership_role_assignments as a')
                ->join('roles as r', fn ($join) => $join->on('r.id', '=', 'a.role_id')->where('r.scope', 'school'))
                ->join('role_capabilities as rc', 'rc.role_id', '=', 'a.role_id')
                ->where('a.school_membership_id', $membership->id)
                ->whereNull('a.revoked_at')
                ->distinct()
                ->pluck('rc.capability_key')
                ->all();
        });
    }

    /**
     * Runs a grant INSERT, translating a refusal by the database grantor
     * trigger (never expected after an allowed decision under the same locks)
     * into a `database_backstop` refusal -- one audited refusal, never a raw
     * database error to the user.
     *
     * @template T
     *
     * @param  Closure(): T  $insert
     * @param  array<string, mixed>  $context  stage / roleKey / schoolMembershipId / invitationId
     * @return T
     *
     * @throws StaffAccountException
     */
    public static function backstopped(Closure $insert, array $context): mixed
    {
        try {
            return $insert();
        } catch (QueryException $e) {
            if (preg_match('/membership_role_assignments: (role \S+ (is retired|has no capabilities)|the assigning user|a runtime School-role grant)/', $e->getMessage()) === 1) {
                throw StaffAccountException::refusedGrant(RoleGrantDecision::refuse('database_backstop'), $context);
            }

            throw $e;
        }
    }

    private function forGrantWithHeld(User $issuer, Role $role, ?array $held): RoleGrantDecision
    {
        [$retired, $capabilities] = $this->roleState($role);

        if ($retired) {
            return RoleGrantDecision::refuse('retired');
        }

        if ($capabilities === []) {
            return RoleGrantDecision::refuse('empty_role');
        }

        return $this->coverage($issuer, $capabilities, null, fn (): ?array => $held);
    }

    /**
     * @param  array<string, ?string>  $capabilities  capability => its grant right
     * @param  Closure(): (list<string>|null)  $held
     */
    private function coverage(User $issuer, array $capabilities, ?string $otherUserId, Closure $held): RoleGrantDecision
    {
        if ($otherUserId !== null && $otherUserId === $issuer->id) {
            return RoleGrantDecision::refuse('self_administration');
        }

        $held = $held();

        if ($held === null) {
            return RoleGrantDecision::refuse('inactive_issuer');
        }

        if (! in_array(self::ROLE_MANAGER, $held, true)) {
            return RoleGrantDecision::refuse('not_role_manager');
        }

        $uncovered = [];
        $rights = [];
        $covered = [];

        foreach ($capabilities as $capability => $grantRight) {
            if (in_array($capability, $held, true)) {
                continue;
            }

            if ($grantRight !== null && in_array($grantRight, $held, true)) {
                $rights[$grantRight] = true;
                $covered[] = $capability;

                continue;
            }

            $uncovered[] = $capability;
        }

        if ($uncovered !== []) {
            return RoleGrantDecision::refuse('not_covered', CapabilityClasses::union($uncovered));
        }

        $rights = array_keys($rights);
        sort($rights);

        return RoleGrantDecision::allow($rights, CapabilityClasses::union($covered));
    }

    /**
     * The role's CURRENT state, read fresh: retired, and capability => grant
     * right (from `capabilities.grant_right`, exactly what the trigger reads).
     *
     * @return array{0: bool, 1: array<string, ?string>}
     */
    private function roleState(Role $role): array
    {
        $retired = Role::query()->whereKey($role->id)->whereNotNull('retired_at')->exists();

        /** @var array<string, ?string> $capabilities */
        $capabilities = DB::table('role_capabilities as rc')
            ->join('capabilities as c', 'c.key', '=', 'rc.capability_key')
            ->where('rc.role_id', $role->id)
            ->orderBy('rc.capability_key')
            ->pluck('c.grant_right', 'rc.capability_key')
            ->all();

        return [$retired, $capabilities];
    }
}
