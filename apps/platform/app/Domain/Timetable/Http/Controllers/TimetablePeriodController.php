<?php

namespace App\Domain\Timetable\Http\Controllers;

use App\Domain\Timetable\Application\TimetablePeriodService;
use App\Domain\Timetable\Infrastructure\TimetablePeriod;
use App\Http\Controllers\Controller;
use App\Models\School;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\NormalizesCodeInput;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Phase 0H -- the Timetable Period administrative API. Mirrors
 * App\Domain\Canteen\Http\Controllers\CanteenOutletController's shape
 * exactly: this controller stays thin (validate input, call the
 * Application service, present the response) and authorization is
 * double-layered -- the `capability:` route middleware gates every
 * mutating route (routes/api.php), while
 * App\Domain\Timetable\Application\TimetablePeriodService ALSO
 * authorizes internally via `authorizeCapabilityFor()` (see that
 * class's own docblock) -- exactly the same "service already
 * authorizes, route middleware also gates" double-layering every other
 * module in this codebase (Canteen, Inventory, Hostel, ...) already
 * established. GET routes carry no route-level `capability:` middleware
 * (matching CanteenOutletController's identical precedent) -- this
 * controller's own `authorizeCapability()` call is what gates them.
 */
class TimetablePeriodController extends Controller
{
    use AuthorizesCapability, NormalizesCodeInput;

    public function index(Request $request, School $school): JsonResponse
    {
        $this->authorizeCapability('timetable.periods.view', $school);

        $query = TimetablePeriod::query()->orderBy('sort_order')->orderBy('code');

        if (! $request->boolean('include_inactive')) {
            $query->where('status', 'active');
        }

        $paginator = $query->paginate(20)->withQueryString();

        return response()->json([
            'data' => $paginator->through(fn (TimetablePeriod $p) => $this->present($p))->items(),
            'meta' => [
                'currentPage' => $paginator->currentPage(),
                'lastPage' => $paginator->lastPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function store(Request $request, School $school, TimetablePeriodService $service): JsonResponse
    {
        $this->authorizeCapability('timetable.periods.manage', $school);
        $this->normalizeCodeInput($request);

        $validated = $request->validate([
            'code' => ['required', 'string', 'max:64', Rule::unique('timetable_periods', 'code')->where('school_id', $school->id)],
            'name' => ['required', 'string', 'max:255'],
            'start_time' => ['required', 'date_format:H:i:s'],
            'end_time' => ['required', 'date_format:H:i:s'],
            'sort_order' => ['sometimes', 'nullable', 'integer'],
        ]);

        $period = $service->create($school, $validated, $request->user());

        return response()->json(['data' => $this->present($period)], 201);
    }

    public function show(School $school, string $timetablePeriod): JsonResponse
    {
        $this->authorizeCapability('timetable.periods.view', $school);

        $period = TimetablePeriod::query()->findOrFail($timetablePeriod);

        return response()->json(['data' => $this->present($period)]);
    }

    public function update(Request $request, School $school, string $timetablePeriod, TimetablePeriodService $service): JsonResponse
    {
        $this->authorizeCapability('timetable.periods.manage', $school);

        $period = TimetablePeriod::query()->findOrFail($timetablePeriod);
        $this->normalizeCodeInput($request);

        $validated = $request->validate([
            'code' => ['sometimes', 'string', 'max:64', Rule::unique('timetable_periods', 'code')->where('school_id', $school->id)->ignore($period->id)],
            'name' => ['sometimes', 'string', 'max:255'],
            'start_time' => ['sometimes', 'date_format:H:i:s'],
            'end_time' => ['sometimes', 'date_format:H:i:s'],
            'sort_order' => ['sometimes', 'nullable', 'integer'],
        ]);

        $period = $service->update($period, $validated, $request->user());

        return response()->json(['data' => $this->present($period)]);
    }

    public function activate(Request $request, School $school, string $timetablePeriod, TimetablePeriodService $service): JsonResponse
    {
        $this->authorizeCapability('timetable.periods.manage', $school);

        $period = TimetablePeriod::query()->findOrFail($timetablePeriod);
        $period = $service->activate($period, $request->user());

        return response()->json(['data' => $this->present($period)]);
    }

    public function deactivate(Request $request, School $school, string $timetablePeriod, TimetablePeriodService $service): JsonResponse
    {
        $this->authorizeCapability('timetable.periods.manage', $school);

        $period = TimetablePeriod::query()->findOrFail($timetablePeriod);
        $period = $service->deactivate($period, $request->user());

        return response()->json(['data' => $this->present($period)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(TimetablePeriod $period): array
    {
        return [
            'id' => $period->id,
            'code' => $period->code,
            'name' => $period->name,
            'startTime' => $period->start_time,
            'endTime' => $period->end_time,
            'sortOrder' => $period->sort_order,
            'status' => $period->status,
        ];
    }
}
