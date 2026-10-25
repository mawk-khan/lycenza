<?php

namespace App\Domain\Communications\Http\Controllers;

use App\Domain\Communications\Application\Events\CommunicationEventCalendar;
use App\Http\Controllers\Controller;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * ORG.9D-3 (ADR 0076 §75): the staff School calendar
 * (`capability:communications.events.view`, ORG.9D-3R1 §76 -- consumption,
 * never Communications administration): published School-wide Events, and
 * Staff Events while the viewer is current staff -- resolved for the viewer
 * on every read (CommunicationEventCalendar::forStaff()). It shows no
 * management action.
 */
class CommunicationCalendarController extends Controller
{
    public function index(Request $request, TenantContext $context, CommunicationEventCalendar $calendar): Response
    {
        $school = $context->requireSchool();
        $filters = $request->validate([
            'when' => ['sometimes', Rule::in([CommunicationEventCalendar::UPCOMING, CommunicationEventCalendar::PAST])],
            'month' => ['sometimes', 'nullable', 'date_format:Y-m'],
        ]);
        $when = $filters['when'] ?? CommunicationEventCalendar::UPCOMING;

        return Inertia::render('App/Communications/Calendar', [
            'events' => $calendar->forStaff($school, $context->actor(), $when, $filters['month'] ?? null),
            'filters' => ['when' => $when, 'month' => $filters['month'] ?? ''],
            'schoolTimezone' => $school->timezone,
        ]);
    }
}
