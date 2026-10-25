<?php

namespace App\Http\Controllers\App\Portal;

use App\Domain\Communications\Application\Events\CommunicationEventCalendar;
use App\Domain\Identity\Application\Portal\ActingGuardianResolver;
use App\Http\Controllers\Controller;
use App\Support\Portal\PortalAvailability;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * ORG.9D-3 (ADR 0076 §75): the Guardian's School calendar -- development
 * only until E46 (`portal-development-only` + `portal.communications.view`
 * on the route, PortalAvailability here), the ActingGuardian resolved fresh.
 * School-wide Events and the Events of the Grades/Sections their eligible
 * Students are currently in; never a Staff Event.
 */
class GuardianCalendarController extends Controller
{
    public function index(Request $request, TenantContext $context, ActingGuardianResolver $guardians, CommunicationEventCalendar $calendar): Response
    {
        PortalAvailability::assertAvailable();
        $school = $context->requireSchool();
        $guardian = $guardians->require($context->actor(), $school);
        $filters = $request->validate([
            'when' => ['sometimes', Rule::in([CommunicationEventCalendar::UPCOMING, CommunicationEventCalendar::PAST])],
            'month' => ['sometimes', 'nullable', 'date_format:Y-m'],
        ]);
        $when = $filters['when'] ?? CommunicationEventCalendar::UPCOMING;

        return Inertia::render('App/Portal/Calendar', [
            'schoolName' => $school->name,
            'events' => $calendar->forGuardian($school, $guardian, $when, $filters['month'] ?? null),
            'filters' => ['when' => $when, 'month' => $filters['month'] ?? ''],
            'schoolTimezone' => $school->timezone,
        ]);
    }
}
