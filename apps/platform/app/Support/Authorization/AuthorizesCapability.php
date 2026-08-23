<?php

namespace App\Support\Authorization;

use App\Models\School;
use App\Support\Tenancy\TenantContext;
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
}
