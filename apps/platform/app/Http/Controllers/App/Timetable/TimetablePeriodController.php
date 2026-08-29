<?php

namespace App\Http\Controllers\App\Timetable;

use App\Domain\Timetable\Application\TimetablePeriodService;
use App\Domain\Timetable\Infrastructure\TimetablePeriod;
use App\Http\Controllers\Controller;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Authorization\CapabilityResolver;
use App\Support\NormalizesCodeInput;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Phase 0H -- session-authenticated Inertia pages for the Timetable
 * Period catalogue. Mirrors
 * App\Http\Controllers\App\Canteen\CanteenOutletController's shape
 * exactly (thin controller, delegates every write to
 * App\Domain\Timetable\Application\TimetablePeriodService, which
 * authorizes internally -- see that class's own docblock).
 */
class TimetablePeriodController extends Controller
{
    use AuthorizesCapability, NormalizesCodeInput;

    public function index(Request $request, TenantContext $context, CapabilityResolver $capabilities): Response
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('timetable.periods.view', $school);

        $query = TimetablePeriod::query()->where('status', 'active')->orderBy('sort_order')->orderBy('code');

        $paginator = $query->paginate(20)->withQueryString();

        return Inertia::render('App/Timetable/Periods/Index', [
            'periods' => $paginator->through(fn (TimetablePeriod $p) => [
                'id' => $p->id,
                'code' => $p->code,
                'name' => $p->name,
                'startTime' => $p->start_time,
                'endTime' => $p->end_time,
                'status' => $p->status,
            ]),
            'canManage' => $capabilities->canInSchool($context->actor(), 'timetable.periods.manage', $school),
        ]);
    }

    public function create(TenantContext $context): Response
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('timetable.periods.manage', $school);

        return Inertia::render('App/Timetable/Periods/Create');
    }

    public function store(Request $request, TenantContext $context, TimetablePeriodService $service): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('timetable.periods.manage', $school);
        $this->normalizeCodeInput($request);

        $validated = $request->validate([
            'code' => ['required', 'string', 'max:64', Rule::unique('timetable_periods', 'code')->where('school_id', $school->id)],
            'name' => ['required', 'string', 'max:255'],
            'start_time' => ['required', 'date_format:H:i:s'],
            'end_time' => ['required', 'date_format:H:i:s'],
            'sort_order' => ['sometimes', 'nullable', 'integer'],
        ]);

        $period = $service->create($school, $validated, $context->actor());

        return redirect('/app/timetable-periods')->with('flash', "Period {$period->code} created.");
    }

    public function update(Request $request, TenantContext $context, string $timetablePeriod, TimetablePeriodService $service): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('timetable.periods.manage', $school);

        $period = TimetablePeriod::query()->findOrFail($timetablePeriod);

        $validated = $request->validate([
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
        ]);

        if (($validated['status'] ?? null) === 'inactive') {
            $service->deactivate($period, $context->actor());
        } elseif (($validated['status'] ?? null) === 'active') {
            $service->activate($period, $context->actor());
        }

        return redirect('/app/timetable-periods')->with('flash', 'Period updated.');
    }
}
