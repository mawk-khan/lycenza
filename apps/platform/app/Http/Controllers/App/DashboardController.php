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
                // Phase 1A.6: Student/Guardian Identity administration.
                'canViewStudents' => $school !== null && $capabilities->canInSchool($user, 'students.view', $school),
                'canViewGuardians' => $school !== null && $capabilities->canInSchool($user, 'guardians.view', $school),
                // Phase 5A: Communication Hub.
                'canViewCommunications' => $school !== null && $capabilities->canInSchool($user, 'communications.view', $school),
                // Phase 1B.6: Enrollment administration.
                'canViewEnrollments' => $school !== null && $capabilities->canInSchool($user, 'enrollments.view', $school),
                // Phase 1B.7F: Enrollment Rollover -- dual capability,
                // both required (docs/modules/STUDENT-ENROLLMENT.md,
                // "Rollover Authorization & Administrative HTTP/API").
                'canViewEnrollmentRollovers' => $school !== null
                    && $capabilities->canInSchool($user, 'enrollments.view', $school)
                    && $capabilities->canInSchool($user, 'enrollments.rollovers.view', $school),
                // Phase 1D.6: Admissions administrative UI.
                'canViewAdmissions' => $school !== null && $capabilities->canInSchool($user, 'admissions.view', $school),
                // Phase 0G.7: Finance workspace -- any one of its three
                // view capabilities is enough to show the entry point;
                // the Finance hub page itself hides the sub-areas the
                // user cannot see (FinanceController::index()).
                'canViewFinance' => $school !== null
                    && ($capabilities->canInSchool($user, 'finance.ledger.view', $school)
                        || $capabilities->canInSchool($user, 'finance.charges.view', $school)
                        || $capabilities->canInSchool($user, 'finance.payments.view', $school)),
                // Phase 1H.1: SubjectOffering roster / elective administration.
                'canViewSubjectOfferings' => $school !== null && $capabilities->canInSchool($user, 'academics.subjects.view', $school),
                // Phase 8A closure correction (item 2): HR workspace --
                // any one of its three top-level view capabilities is
                // enough to show the entry point, mirroring Finance's
                // identical "any one view capability" nav-gate above;
                // the HR hub page itself hides sub-areas the actor
                // cannot see (HrController::index()).
                'canViewHr' => $school !== null
                    && ($capabilities->canInSchool($user, 'hr.employees.view', $school)
                        || $capabilities->canInSchool($user, 'hr.departments.view', $school)
                        || $capabilities->canInSchool($user, 'hr.positions.view', $school)),
                // Phase 10F: Canteen (Outlet/Item directory, billing
                // settings, Order lifecycle) administrative UI.
                'canViewCanteenDirectory' => $school !== null && $capabilities->canInSchool($user, 'canteen.directory.view', $school),
                'canViewCanteenOrders' => $school !== null && $capabilities->canInSchool($user, 'canteen.orders.view', $school),
                'canViewCanteenSettings' => $school !== null && $capabilities->canInSchool($user, 'canteen.settings.view', $school),
            ],
        ]);
    }
}
