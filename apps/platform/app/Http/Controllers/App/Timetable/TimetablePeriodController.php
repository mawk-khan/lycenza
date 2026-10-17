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

        // LC-2: a deactivated Period stays findable (and reactivatable).
        $status = $request->validate(['status' => ['sometimes', Rule::in(['active', 'inactive', 'all'])]])['status'] ?? 'active';
        $query = TimetablePeriod::query()
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->orderBy('sort_order')->orderBy('code');

        $paginator = $query->paginate(20)->withQueryString();

        return Inertia::render('App/Timetable/Periods/Index', [
            'periods' => $paginator->through(fn (TimetablePeriod $p) => [
                'id' => $p->id,
                'code' => $p->code,
                'name' => $p->name,
                'startTime' => $p->start_time,
                'endTime' => $p->end_time,
                'status' => $p->status,
                'sortOrder' => $p->sort_order,
            ]),
            'status' => $status,
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

    public function edit(TenantContext $context, string $timetablePeriod): Response
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('timetable.periods.manage', $school);

        $period = TimetablePeriod::query()->findOrFail($timetablePeriod);

        return Inertia::render('App/Timetable/Periods/Edit', ['period' => [
            'id' => $period->id,
            'code' => $period->code,
            'name' => $period->name,
            'startTime' => $period->start_time,
            'endTime' => $period->end_time,
            'sortOrder' => $period->sort_order,
            'status' => $period->status,
        ]]);
    }

    /**
     * Two forms, one route: `status` alone activates/deactivates (the list's
     * toggle); otherwise (LC-2) the Period's code, name, times and order are
     * edited through TimetablePeriodService::update() -- the same overlap
     * rule as creating one, and a time change is refused while active
     * classes use the Period (both shown by WebDomainRefusalResponder).
     */
    public function update(Request $request, TenantContext $context, string $timetablePeriod, TimetablePeriodService $service): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('timetable.periods.manage', $school);

        $period = TimetablePeriod::query()->findOrFail($timetablePeriod);

        if ($request->has('status')) {
            $validated = $request->validate(['status' => ['required', Rule::in(['active', 'inactive'])]]);
            $validated['status'] === 'inactive'
                ? $service->deactivate($period, $context->actor())
                : $service->activate($period, $context->actor());

            return back()->with('flash', $validated['status'] === 'inactive' ? 'Period deactivated.' : 'Period activated.');
        }

        $this->normalizeCodeInput($request);
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:64', Rule::unique('timetable_periods', 'code')->where('school_id', $school->id)->ignore($period->id)],
            'name' => ['required', 'string', 'max:255'],
            'start_time' => ['required', 'date_format:H:i:s'],
            'end_time' => ['required', 'date_format:H:i:s'],
            'sort_order' => ['sometimes', 'nullable', 'integer'],
        ]);

        // Only what changed: an unchanged start/end is not a time change, so
        // renaming a Period that classes use is never refused.
        $changes = array_filter(
            $validated,
            fn ($value, string $field) => $value !== ($field === 'start_time' ? $period->start_time : ($field === 'end_time' ? $period->end_time : $period->{$field})),
            ARRAY_FILTER_USE_BOTH,
        );
        if ($changes !== []) {
            $service->update($period, $changes, $context->actor());
        }

        return redirect('/app/timetable-periods')->with('flash', "Period {$validated['code']} updated.");
    }
}
