<?php

namespace App\Http\Middleware;

use App\Models\SchoolMembership;
use App\Support\Tenancy\ElevationContext;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Phase 0N.1 (D10(a), docs/architecture/PHASE-0N-READINESS.md section
 * 11): the one School-context prerequisite for School-scoped WEB routes,
 * registered as alias `school-context` and applied to the School route
 * group in routes/web.php -- never per controller. A controller behind
 * it may assume `TenantContext::requireSchool()` succeeds; a
 * TenantContextRequiredException there is a bug, not a user state.
 *
 * It does not resolve the School itself. ResolveSchoolContext (and, in
 * local/testing only, DevOnlySchoolHeaderResolver) already establish
 * TenantContext from trusted sources and re-validate the session's
 * `active_school_id` against an active membership and an active School
 * on every request; a stale value resolves to no School. This step
 * then requires that a School was established, that it is active, that
 * the actor is not disabled and holds an active membership in it (the
 * verified-domain path does not check membership itself). It never
 * picks a School: no fallback to another membership, no auto-selection.
 *
 * Without a valid School, the controller never runs:
 *  - GET/HEAD page request (plain or Inertia): 302 to the /app landing
 *    with a one-request "Select a School" notice.
 *  - JSON request (any method, not Inertia): 409 with the stable code
 *    `school_context_required`, in the `{"error": {...}}` envelope.
 *  - Any other method (a mutation): 409 as well -- for an Inertia visit
 *    with X-Inertia-Location: /app (Inertia::location(), a hard visit
 *    to the landing with a "not saved" notice), otherwise a plain 409.
 *    A mutation is never answered with a success-looking redirect.
 * A stale `active_school_id` is removed from the session and Inertia's
 * history key is rotated, so Back cannot redraw that School's pages.
 *
 * Ordering (bootstrap/app.php priority list): after StartSession, auth,
 * ResolveSchoolContext and DevOnlySchoolHeaderResolver, and BEFORE
 * SubstituteBindings -- so no School-scoped route model is bound (and
 * no 404 is produced for it) before the School context is known --
 * and before every `capability:`/`mfa` route middleware. /api/v1 does
 * not use it: the School is in the URL there (`school-membership`).
 *
 * Phase 0N.3: a request carrying a valid platform elevation
 * (ElevationContext) never takes the membership path below -- it is
 * refused with 403 `school_elevation_not_permitted` unless the route
 * explicitly opted in with `school-context:elevated` (none does yet).
 * A request is membership context or elevated context, never both.
 * See docs/architecture/TENANCY.md ("School-scoped web routes").
 */
class RequireSchoolContext
{
    /** One-request flash read by the landing page (DashboardController). */
    public const FLASH_KEY = 'school_context.required';

    public const ERROR_CODE = 'school_context_required';

    public const STATUS = 409;

    public const SESSION_KEY = 'active_school_id';

    /**
     * Route parameter (`school-context:elevated`) by which a School route
     * explicitly accepts a platform-elevated request. No production route
     * uses it in Phase 0N.3 (SchoolContextRouteGuardTest); each future use
     * needs its own ADR (ADR 0044 section 8).
     */
    public const MODE_ELEVATED = 'elevated';

    public const ELEVATION_NOT_PERMITTED = 'school_elevation_not_permitted';

    public function handle(Request $request, Closure $next, ?string $mode = null): Response
    {
        $user = $request->user();

        if ($user === null) {
            throw new AuthenticationException;
        }

        $elevation = app(ElevationContext::class);

        if ($elevation->isElevated()) {
            return $this->handleElevated($request, $next, $elevation, $mode === self::MODE_ELEVATED);
        }

        $school = app(TenantContext::class)->school();

        $valid = $school !== null
            && $school->isActive()
            && ! $user->isDisabled()
            && SchoolMembership::query()
                ->active()
                ->where('user_id', $user->id)
                ->where('school_id', $school->id)
                ->exists();

        if ($valid) {
            return $next($request);
        }

        return $this->refuse($request);
    }

    /**
     * Phase 0N.3 (ADR 0044 section 7): a platform-elevated request. It is
     * refused (403) on every School route that has not explicitly opted in
     * with `school-context:elevated` -- the default for every existing
     * School route -- because elevation grants no School capability and
     * some School pages are gated by membership or context alone. On an
     * opted-in route the elevation's ONE School is established through the
     * ordinary TenantContext (same GUC, same RLS), unless a verified domain
     * or the local header resolved a different School for this request.
     */
    private function handleElevated(Request $request, Closure $next, ElevationContext $elevation, bool $optedIn): Response
    {
        $record = $elevation->elevation();

        if (! $optedIn || $elevation->hasTargetConflict() || $record === null || $record->school === null) {
            if ($request->expectsJson() && $request->header('X-Inertia') !== 'true') {
                return response()->json([
                    'error' => [
                        'message' => 'Elevated access does not grant access to this School page.',
                        'status' => 403,
                        'code' => self::ELEVATION_NOT_PERMITTED,
                        'requestId' => $request->attributes->get('request_id'),
                        'errors' => null,
                    ],
                ], 403);
            }

            abort(403, 'Elevated access does not grant access to this School page.');
        }

        app(TenantContext::class)->set($record->school);

        return $next($request);
    }

    /**
     * Drops a session School selection that no longer resolves (membership
     * removed or suspended, School suspended or gone, a tampered id) so it
     * can never come back into effect on its own -- the User selects again
     * -- and rotates Inertia's history key so Back cannot redraw pages
     * rendered under it. No-op when nothing is stored. Also called by the
     * /app landing (DashboardController) when no School resolved.
     */
    public static function forgetSelection(Request $request): void
    {
        if ($request->hasSession() && $request->session()->has(self::SESSION_KEY)) {
            $request->session()->forget(self::SESSION_KEY);
            Inertia::clearHistory();
        }
    }

    private function refuse(Request $request): Response
    {
        $isPageRead = $request->isMethod('GET') || $request->isMethod('HEAD');
        $isInertia = $request->header('X-Inertia') === 'true';
        $isJson = $request->expectsJson() && ! $isInertia;

        self::forgetSelection($request);

        if (! $isJson && $request->hasSession()) {
            $request->session()->flash(self::FLASH_KEY, $isPageRead ? 'select' : 'not_saved');
        }

        if ($isJson) {
            return response()->json([
                'error' => [
                    'message' => 'Select a School to continue.',
                    'status' => self::STATUS,
                    'code' => self::ERROR_CODE,
                    'requestId' => $request->attributes->get('request_id'),
                    'errors' => null,
                ],
            ], self::STATUS);
        }

        if ($isPageRead) {
            return redirect()->route('app.dashboard');
        }

        if ($isInertia) {
            return Inertia::location(route('app.dashboard'));
        }

        return response('Select a School to continue.', self::STATUS, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }
}
