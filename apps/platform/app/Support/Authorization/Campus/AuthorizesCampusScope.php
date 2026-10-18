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
        Gate::authorize('campus-capability', [$capability, $school]);

        /** @var User $user the Gate refuses a guest */
        $user = auth()->user();

        return app(CampusScopeResolver::class)->campusScope($user, $capability, $school);
    }

    /** For an Application service called with an explicit actor (tests, jobs). */
    protected function authorizeCampusScopeFor(User $actor, string $capability, School $school): CampusScope
    {
        Gate::forUser($actor)->authorize('campus-capability', [$capability, $school]);

        return app(CampusScopeResolver::class)->campusScope($actor, $capability, $school);
    }
}
