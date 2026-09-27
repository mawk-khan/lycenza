<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Http\Middleware\RequireSchoolContext;
use App\Models\School;
use App\Models\SchoolMembership;
use App\Support\Audit\AuditRecorder;
use App\Support\Auth\CrossHostHandoff;
use App\Support\Auth\HandoffUnavailable;
use App\Support\Domains\CanonicalOrigin;
use App\Support\Domains\HostnameNormalizer;
use App\Support\Tenancy\ElevationContext;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Section 22 (access switching): a user belonging to multiple Schools
 * selects the active one. Requires a real, active membership -- "no
 * access via manually altered IDs" is enforced by re-querying
 * membership here, never trusting a client-supplied school_id alone.
 * Storing the selection resets tenant-sensitive session state
 * (ResolveSchoolContext re-derives TenantContext from this on every
 * subsequent request, it is never cached across the switch).
 *
 * Phase 0O.8A (ADR 0054 section 8.7): the browser then goes to the
 * target School's CANONICAL origin (its active primary custom domain, or
 * the platform host). On the same origin nothing changes (selection,
 * session regeneration, full /app load). On another origin the host-only
 * session cannot follow, so this issues a one-time sign-in handoff bound
 * to that School and exact hostname (CrossHostHandoff) and answers a full
 * navigation (Inertia::location) to the target's handoff endpoint; the
 * target re-checks everything. The audit event records the switch either
 * way (never the ticket).
 */
class SchoolSwitchController extends Controller
{
    public function store(
        Request $request,
        School $school,
        AuditRecorder $audit,
        ElevationContext $elevation,
        CanonicalOrigin $origins,
        CrossHostHandoff $handoff,
        HostnameNormalizer $names,
    ): Response {
        $user = $request->user();

        // Phase 0N.3 (ADR 0044 section 12): ordinary selection never
        // silently ends or replaces a platform elevation -- Exit first.
        if ($elevation->isElevated()) {
            throw ValidationException::withMessages([
                'school' => 'Exit elevated access before selecting a School.',
            ]);
        }

        $membership = SchoolMembership::query()
            ->where('user_id', $user->id)
            ->where('school_id', $school->id)
            ->where('status', 'active')
            ->first();

        if ($membership === null || ! $school->isActive()) {
            throw ValidationException::withMessages([
                'school' => 'You do not have active access to that School.',
            ]);
        }

        $origin = $origins->forSchool($school);
        $targetHost = CanonicalOrigin::hostOf($origin);

        if (hash_equals($targetHost, $names->fold($request->getHost()))) {
            $request->session()->put(RequireSchoolContext::SESSION_KEY, $school->id);
            $request->session()->regenerate();

            $audit->platform('school_context.activated', actor: $user, subject: $school);

            return redirect('/app');
        }

        try {
            $ticket = $handoff->issue($user, $request->session()->getId(), $school, $targetHost, $request->session()->get('mfa_verified_at'));
        } catch (HandoffUnavailable) {
            throw ValidationException::withMessages([
                'school' => 'Switching to that School is temporarily unavailable. Try again shortly.',
            ]);
        }

        $audit->platform('school_context.activated', actor: $user, subject: $school, metadata: ['cross_host' => true]);

        return Inertia::location($origin.'/session/handoff?ticket='.$ticket);
    }
}
