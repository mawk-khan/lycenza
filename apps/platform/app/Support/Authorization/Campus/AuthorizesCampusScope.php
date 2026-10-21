<?php

namespace App\Support\Authorization\Campus;

use App\Models\School;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Gate;

/**
 * ORG.7A (ADR 0076 §11.7, §33): the controller entry for a Campus-adopted
 * action. Refuses through the `campus-capability` Gate (403) when the actor
 * holds the capability nowhere, and otherwise RETURNS the actor's
 * CampusScope, which the action must apply to every query (restrict()) and
 * every target record (covers()) -- before any row is read or written.
 * The scope comes only from persisted grants, never from the request or
 * TenantContext::campus().
 */
trait AuthorizesCampusScope
{
    protected function authorizeCampusScope(string $capability, ?School $school = null): CampusScope
    {
        $school ??= app(TenantContext::class)->requireSchool();
        $user = auth()->user();
        // ORG.7D-1: the scope is resolved once. Only "none" goes through the
        // `campus-capability` Gate, which then refuses exactly as before (a
        // guest included); a held scope is what the Gate would have allowed.
        $scope = $user instanceof User ? app(CampusScopeResolver::class)->campusScope($user, $capability, $school) : CampusScope::none();
        if ($scope->isNone()) {
            Gate::authorize('campus-capability', [$capability, $school]);
        }

        return $scope;
    }

    /**
     * ORG.7G-1 (ADR 0076 §50): a declared WHOLE-SCHOOL-ONLY read of an adopted
     * capability inside an Application service (e.g. a School-wide report or
     * the full fee statement): only a whole-School holder passes; a
     * Campus-only holder is refused exactly like someone without the key
     * (403), so a Campus grant never reaches School-wide data.
     */
    protected function authorizeWholeSchoolScopeFor(User $actor, string $capability, School $school): void
    {
        if (! app(CampusScopeResolver::class)->campusScope($actor, $capability, $school)->isWholeSchool()) {
            Gate::forUser($actor)->authorize('capability', [$capability, $school]);
        }
    }

    /** For an Application service called with an explicit actor (tests, jobs). */
    protected function authorizeCampusScopeFor(User $actor, string $capability, School $school): CampusScope
    {
        $scope = app(CampusScopeResolver::class)->campusScope($actor, $capability, $school);
        if ($scope->isNone()) {
            Gate::forUser($actor)->authorize('campus-capability', [$capability, $school]);
        }

        return $scope;
    }
}
