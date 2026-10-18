<?php

namespace App\Support\Authorization;

use App\Models\MembershipRoleAssignment;
use App\Models\School;
use App\Models\SchoolMembership;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Collection;

/**
 * The ONE definition of a School's qualifying administrator (ADR 0047
 * section 4, amended by ADR 0059 section 7.3), shared by School activation
 * (App\Domain\Platform\Application\Schools\SchoolLifecycleAuthority) and the
 * School's own last-administrator invariant (Phase 0O.12B off-boarding,
 * App\Domain\Identity\Application\Staff\StaffAccessService).
 *
 * A qualifying administrator is a User who
 * - is not disabled;
 * - has an established local credential (ADR 0059 section 7.3: an
 *   administrator who can never sign in would strand an active School);
 * - holds an ACTIVE membership in the School;
 * - whose ACTIVE School role grants give every CAPABILITIES key.
 *
 * A capability test, never a role name (CLAUDE.md rule 24). Read fresh: the
 * capability cache is forgotten first, so a grant revoked a moment ago in the
 * same transaction never still counts.
 */
final class SchoolAdministrators
{
    /** The capabilities that let a School administer itself after activation. */
    public const CAPABILITIES = ['school.members.manage', 'school.roles.manage'];

    /** ORG.3 (ADR 0076 §9.2): the platform registry's yes/no answer. */
    public const READY = 'ready';

    public const NOT_ACTIVATED = 'not_activated';

    public const MISSING = 'missing';

    public function __construct(
        private readonly CapabilityResolver $capabilities,
        private readonly TenantContext $context,
    ) {}

    /**
     * ORG.3 (ADR 0076 §9.2): whether the School has a qualifying, activated
     * administrator (`ready`), only one who has not activated their account
     * yet (`not_activated`), or none (`missing`). No identity leaves here.
     */
    public function readiness(School $school): string
    {
        $holding = $this->holdingAuthority($school);

        return match (true) {
            $holding->contains(fn (User $user): bool => $user->hasLocalCredential()) => self::READY,
            $holding->isNotEmpty() => self::NOT_ACTIVATED,
            default => self::MISSING,
        };
    }

    /** @return Collection<int, User> */
    public function qualifying(School $school): Collection
    {
        return $this->holdingAuthority($school)
            ->filter(fn (User $user): bool => $user->hasLocalCredential())
            ->values();
    }

    /**
     * Enabled active members holding the administrator capabilities, with or
     * without a credential -- lets School activation tell "no administrator"
     * (`admin_missing`) from "an administrator who has not activated their
     * account yet" (`admin_not_activated`).
     *
     * @return Collection<int, User>
     */
    public function holdingAuthority(School $school): Collection
    {
        return SchoolMembership::query()->active()
            ->where('school_id', $school->id)
            ->whereIn('id', $this->candidateMemberships($school))
            ->with('user')
            ->get()
            ->map(fn (SchoolMembership $m) => $m->user)
            ->filter(fn (?User $user): bool => $user !== null && ! $user->isDisabled() && $this->holdsCapabilities($user, $school))
            ->values();
    }

    public function isQualifying(User $user, School $school): bool
    {
        return ! $user->isDisabled()
            && $user->hasLocalCredential()
            && $this->holdsCapabilities($user, $school);
    }

    /**
     * ORG.3: a narrowing step only, never the decision. School capabilities
     * come solely from active role grants (CapabilityResolver::
     * schoolCapabilities()), so a member without an active grant carrying the
     * first administrator capability can never qualify; every candidate is
     * still checked by holdsCapabilities(). Keeps the check proportional to
     * the administrators, not to every member (Guardians included).
     *
     * @return list<string>
     */
    private function candidateMemberships(School $school): array
    {
        return $this->context->withSchool($school, fn (): array => MembershipRoleAssignment::query()
            ->active()
            ->where('school_id', $school->id)
            ->whereNull('campus_id') // ORG.7A: administrators are whole-School grants only
            ->whereHas('role.capabilities', fn ($query) => $query->where('capabilities.key', self::CAPABILITIES[0]))
            ->distinct()
            ->pluck('school_membership_id')
            ->all());
    }

    private function holdsCapabilities(User $user, School $school): bool
    {
        $this->capabilities->forgetCache($user, $school);

        return array_diff(self::CAPABILITIES, $this->capabilities->schoolCapabilitiesAmong($user, $school, self::CAPABILITIES)) === [];
    }
}
