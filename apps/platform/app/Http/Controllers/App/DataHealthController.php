<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Authorization\CapabilityResolver;
use App\Support\DataHealth\DataHealthService;
use App\Support\Navigation\StaffNavigation;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * V11.5 (ADR 0075 section 10): the read-only Data Health hub. The page
 * opens for a holder of any check's capability (the navigation hub
 * requirement); each check and finding is then filtered by
 * DataHealthService. No action here changes data.
 */
class DataHealthController extends Controller
{
    use AuthorizesCapability;

    public function index(TenantContext $context, CapabilityResolver $capabilities, DataHealthService $health): Response
    {
        $school = $context->requireSchool();
        $actor = $context->actor();
        $this->authorizeAnyCapability(StaffNavigation::hubRequirement('data-health'), $school);

        return Inertia::render('App/DataHealth/Index', [
            'checks' => $health->overview($school, $actor),
            // One flag per hub requirement capability: each opens at least one check.
            'can' => [
                'viewPlacementChecks' => $capabilities->canInSchool($actor, 'enrollments.view', $school),
                'viewYearChecks' => $capabilities->canInSchool($actor, 'academics.years.view', $school),
                'viewSectionChecks' => $capabilities->canInSchool($actor, 'academics.structure.view', $school),
                'viewTimetableChecks' => $capabilities->canInSchool($actor, 'timetable.schedule.view', $school),
                'viewTeachingChecks' => $capabilities->canInSchool($actor, 'teaching.assignments.view', $school),
            ],
        ]);
    }

    public function show(Request $request, TenantContext $context, DataHealthService $health, string $check): Response
    {
        $school = $context->requireSchool();
        $this->authorizeAnyCapability(StaffNavigation::hubRequirement('data-health'), $school);
        $page = (int) ($request->validate(['page' => ['sometimes', 'integer', 'min:1']])['page'] ?? 1);

        $result = $health->findings($school, $check, $context->actor(), max($page, 1));
        abort_if($result === null, 404);

        return Inertia::render('App/DataHealth/Show', [
            'check' => $result['definition'],
            'findings' => $result['findings']->withQueryString(),
        ]);
    }
}
