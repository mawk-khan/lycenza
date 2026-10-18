<?php

namespace App\Support\Authorization\Campus;

use App\Models\School;
use App\Models\User;
use App\Support\Authorization\CapabilityResolver;

/**
 * ORG.7A (ADR 0076 §11.3, §11.7, §33): the scope-aware authorization path.
 * Adopting domains call it EXPLICITLY; the ordinary whole-School Gate /
 * CapabilityResolver::can() never consults Campus-scoped grants.
 *
 *   whole-School effective capability (enabled user, active membership,
 *   module entitlement)                              -> CampusScope::wholeSchool()
 *   else, for an ADOPTED capability, the ACTIVE Campuses of the actor's
 *   active Campus-scoped grants carrying it (same gates) -> CampusScope::campuses()
 *   else                                             -> CampusScope::none()
 */
class CampusScopeResolver
{
    public function __construct(private readonly CapabilityResolver $capabilities) {}

    public function scopeFor(User $actor, string $capability, School $school): CampusScope
    {
        if ($this->capabilities->canInSchool($actor, $capability, $school)) {
            return CampusScope::wholeSchool();
        }
        if (! CampusScopeCatalogue::isAdopted($capability)) {
            return CampusScope::none();
        }

        $campuses = [];
        foreach ($this->capabilities->campusCapabilities($actor, $school) as $campusId => $held) {
            if (in_array($capability, $held, true)) {
                $campuses[] = (string) $campusId;
            }
        }

        return CampusScope::campuses($campuses);
    }

    /**
     * Navigation only (ADR 0076 §11.7): the adopted capabilities the actor
     * holds in at least one active Campus. NEVER authorization by itself --
     * every destination re-checks with scopeFor().
     *
     * @return list<string>
     */
    public function heldInAnyCampus(User $actor, School $school): array
    {
        $held = [];
        foreach ($this->capabilities->campusCapabilities($actor, $school) as $capabilities) {
            array_push($held, ...$capabilities);
        }

        return array_values(array_unique($held));
    }
}
