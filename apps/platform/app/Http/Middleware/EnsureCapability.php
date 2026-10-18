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
 *
 * ORG.2 (ADR 0076 §28): `capability:a|b` passes when the actor holds AT
 * LEAST ONE of the listed existing capabilities -- each checked through the
 * same `capability` Gate, so this is a disjunction of existing checks, never
 * a new rule (the AuthorizesCapability::authorizeAnyCapability() precedent).
 * It is a route-level FLOOR for an action whose Application layer decides
 * the exact capability per call (e.g. a classification tier), so that the
 * request is authorized before `idempotent` can replay anything (rule 32).
 *
 * ORG.7A (ADR 0076 §33): `capability:x,campus` is the ENTRY floor of a
 * Campus-adopted route -- `x` held School-wide or in at least one active
 * Campus (the `campus-capability` Gate). It is never record authority: the
 * action applies the actor's CampusScope before it reads or writes a record.
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
        $gate = Gate::forUser($user);

        $ability = $mode === 'campus' ? 'campus-capability' : 'capability';

        foreach (explode('|', $capability) as $any) {
            if ($gate->allows($ability, [$any, $school])) {
                return $next($request);
            }
        }

        throw new AuthorizationException('This action is unauthorized.');
    }
}
