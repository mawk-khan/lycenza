<?php

namespace App\Http\Controllers\App;

use App\Domain\HR\Infrastructure\Employee;
use App\Domain\Visitor\Application\Exceptions\HostEmployeeNotEligibleException;
use App\Domain\Visitor\Application\Exceptions\VisitAlreadyCheckedOutException;
use App\Domain\Visitor\Application\Exceptions\VisitorAlreadyCheckedInException;
use App\Domain\Visitor\Application\Exceptions\VisitorNotEligibleException;
use App\Domain\Visitor\Application\VisitorVisitService;
use App\Domain\Visitor\Infrastructure\Visitor;
use App\Domain\Visitor\Infrastructure\VisitorVisit;
use App\Http\Controllers\Controller;
use App\Models\Campus;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Authorization\CapabilityResolver;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Phase 10C -- session-authenticated Inertia pages for Visitor
 * check-in/check-out. Every mutation delegates to
 * VisitorVisitService, the exact same service the JSON API controller
 * uses. The Visitor/Employee-host search endpoints below explicitly
 * call authorizeCapability() BEFORE querying -- checkpoint brief
 * section 25: "Remember the Phase 10A Library security issue
 * discovered in its circulation search endpoints. Do not repeat that
 * authorization mistake," verbatim the same discipline
 * App\Http\Controllers\App\TransportStudentAssignmentController
 * already established.
 */
class VisitorVisitController extends Controller
{
    use AuthorizesCapability;

    public function index(Request $request, TenantContext $context, CapabilityResolver $capabilities): Response
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('visitor.visits.view', $school);

        $validated = $request->validate([
            'status' => ['sometimes', Rule::in(['checked_in', 'checked_out'])],
        ]);

        $visits = VisitorVisit::query()
            ->with(['visitor', 'campus', 'hostEmployee'])
            ->where('status', $validated['status'] ?? 'checked_in')
            ->orderByDesc('checked_in_at')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('App/Visitor/Visits/Index', [
            'visits' => $visits->through(fn (VisitorVisit $v) => $this->present($v)),
            'filters' => ['status' => $validated['status'] ?? 'checked_in'],
            'canManage' => $capabilities->canInSchool($context->actor(), 'visitor.visits.manage', $school),
        ]);
    }

    public function create(TenantContext $context): Response
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('visitor.visits.manage', $school);

        return Inertia::render('App/Visitor/Visits/Create', [
            'campuses' => Campus::query()->orderBy('name')->get()
                ->map(fn (Campus $c) => ['id' => $c->id, 'name' => $c->name])
                ->all(),
        ]);
    }

    public function searchVisitors(Request $request, TenantContext $context): JsonResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('visitor.visits.manage', $school);

        $term = (string) $request->query('q', '');

        $visitors = Visitor::query()
            ->where('status', 'active')
            ->when($term !== '', fn ($q) => $q->where(fn ($w) => $w->where('full_name', 'ilike', "%{$term}%")
                ->orWhere('phone', 'ilike', "%{$term}%")))
            ->limit(10)
            ->get();

        return response()->json(['data' => $visitors->map(fn (Visitor $v) => [
            'id' => $v->id,
            'fullName' => $v->full_name,
            'phone' => $v->phone,
        ])->all()]);
    }

    public function searchHosts(Request $request, TenantContext $context): JsonResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('visitor.visits.manage', $school);

        $term = (string) $request->query('q', '');

        $employees = Employee::query()
            ->where('record_status', 'active')
            ->when($term !== '', fn ($q) => $q->where('full_name', 'ilike', "%{$term}%"))
            ->limit(10)
            ->get();

        return response()->json(['data' => $employees->map(fn (Employee $e) => [
            'id' => $e->id,
            'fullName' => $e->full_name,
        ])->all()]);
    }

    public function store(Request $request, TenantContext $context, VisitorVisitService $service): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('visitor.visits.manage', $school);

        $validated = $request->validate([
            'visitor_id' => ['required', 'string'],
            'campus_id' => ['required', 'string'],
            'host_employee_id' => ['nullable', 'string'],
            'purpose' => ['required', 'string', 'max:500'],
            'gate_pass_number' => [
                'nullable', 'string', 'max:64',
                Rule::unique('visitor_visits', 'gate_pass_number')->where('school_id', $school->id),
            ],
        ]);

        $visitor = Visitor::query()->findOrFail($validated['visitor_id']);
        $campus = Campus::query()->findOrFail($validated['campus_id']);
        $host = isset($validated['host_employee_id']) ? Employee::query()->findOrFail($validated['host_employee_id']) : null;

        try {
            $service->checkIn($visitor, $campus, $host, $validated['purpose'], $validated['gate_pass_number'] ?? null, $context->actor());
        } catch (VisitorNotEligibleException|VisitorAlreadyCheckedInException $e) {
            throw ValidationException::withMessages(['visitor_id' => [$e->getMessage()]]);
        } catch (HostEmployeeNotEligibleException $e) {
            throw ValidationException::withMessages(['host_employee_id' => [$e->getMessage()]]);
        }

        return redirect('/app/visitor/visits')->with('flash', 'Visitor checked in.');
    }

    public function end(TenantContext $context, VisitorVisitService $service, string $visitorVisit): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('visitor.visits.manage', $school);

        $model = VisitorVisit::query()->findOrFail($visitorVisit);

        try {
            $service->checkOut($model, $context->actor());
        } catch (VisitAlreadyCheckedOutException $e) {
            throw ValidationException::withMessages(['visitor_visit' => [$e->getMessage()]]);
        }

        return redirect('/app/visitor/visits')->with('flash', 'Visitor checked out.');
    }

    /**
     * @return array<string, mixed>
     */
    private function present(VisitorVisit $visit): array
    {
        return [
            'id' => $visit->id,
            'visitorName' => $visit->visitor->full_name,
            'campusName' => $visit->campus->name,
            'hostName' => $visit->hostEmployee?->full_name,
            'purpose' => $visit->purpose,
            'checkedInAt' => $visit->checked_in_at->toIso8601String(),
            'checkedOutAt' => $visit->checked_out_at?->toIso8601String(),
        ];
    }
}
