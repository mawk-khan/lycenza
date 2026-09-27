<?php

namespace App\Http\Middleware;

use App\Domain\Platform\Application\Elevation\ElevationAudit;
use App\Domain\Platform\Application\Elevation\ElevationEndReason;
use App\Domain\Platform\Application\Elevation\SchoolElevationService;
use App\Models\SchoolElevation;
use App\Support\Audit\AuditRecorder;
use App\Support\Domains\HostClassification;
use App\Support\Tenancy\ElevationContext;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Phase 0N.3 (ADR 0044 sections 2-4, 10-11): turns the session's
 * elevation pointer into the request-scoped ElevationContext -- web
 * session requests only; the `api` group has no session and never runs
 * it.
 *
 * The session holds only the elevation id. Every request re-validates
 * the persistent record: owned by the signed-in actor, still `active`,
 * not expired, actor not disabled, `platform.schools.elevate` still held,
 * School active, actor not a member of it, MFA factor still active, and
 * no ordinary `active_school_id` alongside it. A failed check finishes
 * the record where it is still active (its lifecycle event is audited
 * exactly once), clears the pointer and rotates Inertia's history key, so
 * a stale pointer can never restore elevated context. A pointer to a
 * missing or foreign record is audited as `elevation_reference_invalid`.
 *
 * A valid elevation sets ElevationContext only. It does NOT put the
 * School into TenantContext -- RequireSchoolContext does that, on a route
 * that explicitly opted in, and nowhere else -- and it discards any School
 * a verified domain or the local X-School-Id header resolved for this
 * request, so a request is never both membership and elevated context. If
 * that School differed from the elevation's, the request is marked as a
 * target conflict and no School context can be established at all.
 *
 * Never on a custom School domain (Phase 0O.8A, ADR 0054 section 8.5).
 *
 * Priority-pinned after ResolveSchoolContext/DevOnlySchoolHeaderResolver
 * and before RequireSchoolContext (bootstrap/app.php).
 */
class ResolvePlatformElevation
{
    public const SESSION_KEY = 'platform_elevation_id';

    /** One-request flash read by the /app landing: how the elevation ended. */
    public const FLASH_KEY = 'platform_elevation.notice';

    public function __construct(
        private readonly SchoolElevationService $elevations,
        private readonly AuditRecorder $audit,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        // Never inherit a previous unit of work's elevation (a long-running
        // worker, or several requests in one test process).
        app(ElevationContext::class)->clear();

        // ADR 0054 section 8.5: elevation is a platform-host flow only. A
        // custom School domain never resolves one (its host-only session
        // could not have started one anyway; this makes that structural).
        if (HostClassification::schoolHostOf($request) !== null) {
            return $next($request);
        }

        if (! $request->hasSession() || ! $request->session()->has(self::SESSION_KEY)) {
            return $next($request);
        }

        $user = $request->user();
        $pointer = $request->session()->get(self::SESSION_KEY);

        if ($user === null) {
            $request->session()->forget(self::SESSION_KEY);

            return $next($request);
        }

        $elevation = is_string($pointer) && Str::isUuid($pointer) ? SchoolElevation::query()->find($pointer) : null;

        if ($elevation === null || $elevation->actor_user_id !== $user->id) {
            $this->audit->platform(ElevationAudit::DENIED, actor: $user, metadata: array_filter([
                'outcome_code' => 'elevation_reference_invalid',
                'elevation_id' => $elevation?->id,
            ]), ipAddress: $request->ip(), userAgent: $request->userAgent());

            $this->drop($request);

            return $next($request);
        }

        if (! $elevation->isActive()) {
            // Already finished elsewhere (exit from another session, the
            // sweep, a hook, a Group removal/revocation/archive) -- its end
            // was audited then; this session only learns why.
            $this->drop($request, $elevation->end_reason ?? 'ended');

            return $next($request);
        }

        $ordinarySelection = $request->session()->has(RequireSchoolContext::SESSION_KEY);
        $reason = $this->elevations->invalidityReason($elevation, $user)
            ?? ($ordinarySelection ? ElevationEndReason::MembershipConflict : null);

        if ($reason !== null) {
            $this->elevations->finish($elevation, $reason);

            if ($ordinarySelection) {
                // Both kinds of context at once is never resolved in favour
                // of either: drop the selection too, including the School
                // ResolveSchoolContext already set for THIS request.
                $request->session()->forget(RequireSchoolContext::SESSION_KEY);
                app(TenantContext::class)->clear();
            }

            $this->drop($request, $reason->value);

            return $next($request);
        }

        $tenant = app(TenantContext::class);
        $conflict = $tenant->hasSchool() && $tenant->schoolId() !== $elevation->school_id;

        if ($tenant->hasSchool()) {
            $tenant->clear();
        }

        app(ElevationContext::class)->set($elevation, $conflict);
        Context::add('elevation_id', $elevation->id);

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        app(ElevationContext::class)->clear();
        Context::forget('elevation_id');
    }

    private function drop(Request $request, ?string $notice = null): void
    {
        $request->session()->forget(self::SESSION_KEY);
        Inertia::clearHistory();

        if ($notice !== null) {
            $request->session()->flash(self::FLASH_KEY, $notice);
        }
    }
}
