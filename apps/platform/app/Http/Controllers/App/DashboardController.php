<?php

namespace App\Http\Controllers\App;

use App\Domain\Platform\Application\Elevation\SchoolElevationService;
use App\Http\Controllers\Controller;
use App\Http\Middleware\RequireSchoolContext;
use App\Http\Middleware\ResolvePlatformElevation;
use App\Models\SchoolMembership;
use App\Support\Authorization\CapabilityResolver;
use App\Support\Domains\HostClassification;
use App\Support\Tenancy\ElevationContext;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The signed-in landing (SWC.1, ADR 0073 section 7.2): the product heading,
 * the active School, School selection, elevation state and an overview of
 * the shared capability-driven navigation (App\Support\Navigation\
 * NavigationBuilder, shared by HandleInertiaRequests). It keeps no
 * navigation flags of its own and shows no business data.
 *
 * Phase 0N.1 (D9(a)): the context-neutral signed-in landing. It is
 * outside the `school-context` route group and must render with no
 * School selected: it lists only the User's own active memberships for
 * an explicit choice (never selecting one), and for an account with no
 * membership -- including a Platform Super Admin -- shows a neutral
 * state with no School data. `schoolContextNotice` is the one-request
 * flash RequireSchoolContext sets when it sent a request back here.
 */
class DashboardController extends Controller
{
    public function index(Request $request, TenantContext $context, CapabilityResolver $capabilities, ElevationContext $elevated, SchoolElevationService $elevations): Response
    {
        $user = $request->user();

        $memberships = SchoolMembership::query()
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->with('school')
            ->get()
            ->map(fn (SchoolMembership $m) => [
                'schoolId' => $m->school_id,
                'schoolName' => $m->school->name,
                'isActive' => $context->schoolId() === $m->school_id,
                // Phase 0N.9 (ADR 0047): a provisioning, suspended or
                // archived School is listed as unavailable (never why),
                // and cannot be selected (SchoolSwitchController refuses
                // it anyway).
                'available' => $m->school->isActive(),
            ]);

        $school = $context->school();

        if ($school === null) {
            RequireSchoolContext::forgetSelection($request);
        }

        $notice = $request->session()->get(RequireSchoolContext::FLASH_KEY);
        $elevationNotice = $request->session()->get(ResolvePlatformElevation::FLASH_KEY);
        // Elevation is a platform-host flow (ADR 0054 section 8.5): a School
        // custom host never offers it (SWC.1, ADR 0073 section 6.2).
        $canElevate = HostClassification::schoolHostOf($request) === null
            && $capabilities->canPlatform($user, SchoolElevationService::CAPABILITY);
        // An active elevation held by ANOTHER session of this actor (the
        // one-per-actor slot is taken until it is exited or expires).
        $elsewhere = ! $elevated->isElevated() && $canElevate ? $elevations->activeFor($user) : null;

        return Inertia::render('App/Dashboard', [
            'schoolContextNotice' => in_array($notice, ['select', 'not_saved'], true) ? $notice : null,
            // Whether the account holds any platform capability -- only
            // so the no-School state can say what kind of account this
            // is. Platform capabilities never grant School access
            // (CapabilityResolver keeps the two apart).
            'platformAccount' => $capabilities->platformCapabilities($user) !== [],
            // Phase 0N.3 (ADR 0044): platform elevation. The banner itself
            // is the shared `elevation` prop (HandleInertiaRequests).
            'platformElevation' => [
                'canStart' => $canElevate && ! $elevated->isElevated() && $elsewhere === null,
                'isElevated' => $elevated->isElevated(),
                'activeElsewhere' => $elsewhere ? [
                    'schoolName' => $elsewhere->school->name,
                    'expiresAt' => $elsewhere->expires_at->toIso8601String(),
                ] : null,
                'notice' => is_string($elevationNotice) ? $elevationNotice : null,
            ],
            'activeSchool' => $school ? [
                'id' => $school->id,
                'name' => $school->name,
            ] : null,
            'memberships' => $memberships,
        ]);
    }
}
