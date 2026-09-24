<?php

namespace App\Http\Controllers\App;

use App\Domain\Platform\Application\Elevation\SchoolElevationService;
use App\Http\Controllers\Controller;
use App\Http\Middleware\RequireSchoolContext;
use App\Http\Middleware\ResolvePlatformElevation;
use App\Models\SchoolMembership;
use App\Support\Authorization\CapabilityResolver;
use App\Support\Tenancy\ElevationContext;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Phase 0B primitive (section 39): proves authenticated session ->
 * active School context -> permission-aware navigation rendering.
 * Deliberately not a real dashboard -- no business data, no widgets.
 *
 * Phase 0N.1 (D9(a)): also the context-neutral signed-in landing. It is
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
            ]);

        $school = $context->school();

        if ($school === null) {
            RequireSchoolContext::forgetSelection($request);
        }

        $notice = $request->session()->get(RequireSchoolContext::FLASH_KEY);
        $elevationNotice = $request->session()->get(ResolvePlatformElevation::FLASH_KEY);
        $canElevate = $capabilities->canPlatform($user, SchoolElevationService::CAPABILITY);
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
                // Phase 0H: Timetable (Period catalogue + weekly schedule).
                'canViewTimetablePeriods' => $school !== null && $capabilities->canInSchool($user, 'timetable.periods.view', $school),
                'canViewTimetableSchedule' => $school !== null && $capabilities->canInSchool($user, 'timetable.schedule.view', $school),
                // Phase 9.9: Payroll workspace -- any one of its four
                // top-level view capabilities is enough to show the entry
                // point, mirroring Finance/HR's identical "any one view
                // capability" nav-gate above; the Payroll hub page itself
                // hides sub-areas the actor cannot see (PayrollController::index()).
                'canViewPayroll' => $school !== null
                    && ($capabilities->canInSchool($user, 'payroll.structures.view', $school)
                        || $capabilities->canInSchool($user, 'payroll.compensation.view', $school)
                        || $capabilities->canInSchool($user, 'payroll.runs.view', $school)
                        || $capabilities->canInSchool($user, 'payroll.accounting.manage', $school)),
                // Phase 0L.2-1: Analytics (Curriculum Coverage report).
                'canViewAnalytics' => $school !== null && $capabilities->canInSchool($user, 'analytics.view', $school),
                // Phase 0L.4: Compliance -- School audit-log review.
                'canViewAuditLog' => $school !== null && $capabilities->canInSchool($user, 'school.audit.view', $school),
                // Phase 0L.6: Automation (Academic year set-up review rule).
                'canViewAutomation' => $school !== null && $capabilities->canInSchool($user, 'automation.view', $school),
            ],
        ]);
    }
}
