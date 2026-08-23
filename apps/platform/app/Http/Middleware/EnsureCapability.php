<?php

namespace App\Http\Middleware;

use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route-level capability enforcement (section 21).
 * Usage: Route::get(...)->middleware('capability:school.settings.manage');
 * Uses the current TenantContext's School automatically -- pass a
 * second, literal ":platform" segment for a platform-scoped capability
 * check that must ignore School context entirely, e.g.
 * 'capability:platform.schools.manage,platform'.
 */
class EnsureCapability
{
    public function handle(Request $request, Closure $next, string $capability, ?string $mode = null): Response
    {
        $user = $request->user();

        if ($user === null) {
            throw new AuthorizationException('Authentication required.');
        }

        $school = $mode === 'platform' ? null : app(TenantContext::class)->school();

        Gate::forUser($user)->authorize('capability', [$capability, $school]);

        return $next($request);
    }
}
