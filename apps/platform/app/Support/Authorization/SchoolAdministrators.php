<?php

namespace App\Support\Authorization;

use App\Models\School;
use App\Models\SchoolMembership;
use App\Models\User;
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

    public function __construct(private readonly CapabilityResolver $capabilities) {}

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

    private function holdsCapabilities(User $user, School $school): bool
    {
        $this->capabilities->forgetCache($user, $school);

        return array_diff(self::CAPABILITIES, $this->capabilities->schoolCapabilities($user, $school)) === [];
    }
}
