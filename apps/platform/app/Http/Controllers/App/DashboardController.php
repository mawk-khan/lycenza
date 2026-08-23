<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\SchoolMembership;
use App\Support\Authorization\CapabilityResolver;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Phase 0B primitive (section 39): proves authenticated session ->
 * active School context -> permission-aware navigation rendering.
 * Deliberately not a real dashboard -- no business data, no widgets.
 */
class DashboardController extends Controller
{
    public function index(Request $request, TenantContext $context, CapabilityResolver $capabilities): Response
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
            ]);

        $school = $context->school();

        return Inertia::render('App/Dashboard', [
            'activeSchool' => $school ? [
                'id' => $school->id,
                'name' => $school->name,
            ] : null,
            'memberships' => $memberships,
            // Permission-aware navigation is UX only -- every linked
            // destination re-checks authorization server-side
            // regardless of what this map says (root CLAUDE.md rule 6).
            'nav' => [
                'canViewSchoolSettings' => $school !== null && $capabilities->canInSchool($user, 'school.settings.view', $school),
                'canManageSchoolSettings' => $school !== null && $capabilities->canInSchool($user, 'school.settings.manage', $school),
                'canManagePlatformSchools' => $capabilities->canPlatform($user, 'platform.schools.manage'),
                'canViewCommunications' => $school !== null && $capabilities->canInSchool($user, 'communications.view', $school),
            ],
        ]);
    }
}
