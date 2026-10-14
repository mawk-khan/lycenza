<?php

namespace App\Support\Authorization;

use App\Models\School;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;

/**
 * Controller convenience trait (section 21). Usage:
 *
 *   $this->authorizeCapability('school.settings.manage');
 *   $this->authorizeCapability('platform.schools.manage', platform: true);
 *
 * Avoids ad hoc permission queries scattered through controllers --
 * every future module's controllers should use this (or the
 * `capability:` route middleware) rather than querying
 * CapabilityResolver directly.
 */
trait AuthorizesCapability
{
    protected function authorizeCapability(string $capability, ?School $school = null, bool $platform = false): void
    {
        $school ??= $platform ? null : app(TenantContext::class)->school();

        Gate::authorize('capability', [$capability, $school]);
    }

    /**
     * SWC.5 (ADR 0073 section 20.3): passes when the actor holds AT LEAST
     * ONE of the given existing capabilities in the School -- each checked
     * through the same `capability` gate as authorizeCapability(), so this
     * is a disjunction of existing checks, never a new rule. Used only by
     * pages that merely group links to sub-pages that each check their own
     * capability (the Finance, HR and Payroll hubs).
     *
     * @param  list<string>  $capabilities
     */
    protected function authorizeAnyCapability(array $capabilities, ?School $school = null): void
    {
        $school ??= app(TenantContext::class)->school();

        foreach ($capabilities as $capability) {
            if (Gate::allows('capability', [$capability, $school])) {
                return;
            }
        }

        throw new AuthorizationException;
    }

    /**
     * Phase 8A.10: authorizes an EXPLICIT actor rather than the
     * ambient authenticated request user -- for Application-layer
     * services (e.g. App\Domain\HR\Application\*) whose public methods
     * take a `User $actor` parameter and are called directly (from
     * tests, queued jobs, or a future controller) without necessarily
     * running inside an authenticated HTTP request. Reuses the exact
     * same `Gate::define('capability', ...)` mechanism
     * (AppServiceProvider::boot()) that `authorizeCapability()`/
     * `EnsureCapability` already use -- not a parallel authorization
     * engine. Throws Illuminate\Auth\Access\AuthorizationException on
     * denial, exactly like Gate::authorize().
     */
    protected function authorizeCapabilityFor(User $actor, string $capability, ?School $school): void
    {
        Gate::forUser($actor)->authorize('capability', [$capability, $school]);
    }
}
