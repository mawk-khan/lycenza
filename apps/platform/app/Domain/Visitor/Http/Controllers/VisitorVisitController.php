<?php

namespace App\Domain\Visitor\Http\Controllers;

use App\Domain\HR\Infrastructure\Employee;
use App\Domain\Visitor\Application\VisitorVisitService;
use App\Domain\Visitor\Infrastructure\Visitor;
use App\Domain\Visitor\Infrastructure\VisitorVisit;
use App\Http\Controllers\Controller;
use App\Models\Campus;
use App\Models\School;
use App\Support\Authorization\AuthorizesCapability;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Phase 10C -- a Visitor's check-in/check-out administrative API.
 * store() (the consequential check-in mutation) carries the
 * `idempotent` middleware in routes/api.php, mirroring
 * App\Domain\Transport\Http\Controllers\TransportStudentAssignmentController::store()
 * -- see VisitorVisitService's docblock for the concurrency guarantee
 * this endpoint delegates to. `end()` (check-out) deliberately does
 * NOT carry idempotency -- see docs/modules/VISITOR.md "Check-out
 * idempotency decision".
 */
class VisitorVisitController extends Controller
{
    use AuthorizesCapability;

    public function index(Request $request, School $school): JsonResponse
    {
        $this->authorizeCapability('visitor.visits.view', $school);

        $validated = $request->validate([
            'status' => ['sometimes', Rule::in(['checked_in', 'checked_out'])],
            'visitor_id' => ['sometimes', 'string'],
        ]);

        $query = VisitorVisit::query()
            ->with(['visitor', 'campus', 'hostEmployee'])
            ->orderByDesc('checked_in_at');

        $query->where('status', $validated['status'] ?? 'checked_in');

        if (isset($validated['visitor_id'])) {
            $query->where('visitor_id', $validated['visitor_id']);
        }

        $paginator = $query->paginate(20)->withQueryString();

        return response()->json([
            'data' => $paginator->through(fn (VisitorVisit $v) => $this->present($v))->items(),
            'meta' => [
                'currentPage' => $paginator->currentPage(),
                'lastPage' => $paginator->lastPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function store(Request $request, School $school, VisitorVisitService $service): JsonResponse
    {
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

        $visit = $service->checkIn($visitor, $campus, $host, $validated['purpose'], $validated['gate_pass_number'] ?? null, $request->user());

        return response()->json(['data' => $this->present($visit->load(['visitor', 'campus', 'hostEmployee']))], 201);
    }

    public function show(School $school, string $visitorVisit): JsonResponse
    {
        $this->authorizeCapability('visitor.visits.view', $school);

        $model = VisitorVisit::query()->with(['visitor', 'campus', 'hostEmployee'])->findOrFail($visitorVisit);

        return response()->json(['data' => $this->present($model)]);
    }

    public function end(Request $request, School $school, string $visitorVisit, VisitorVisitService $service): JsonResponse
    {
        $this->authorizeCapability('visitor.visits.manage', $school);

        $model = VisitorVisit::query()->findOrFail($visitorVisit);

        $visit = $service->checkOut($model, $request->user());

        return response()->json(['data' => $this->present($visit->load(['visitor', 'campus', 'hostEmployee']))]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(VisitorVisit $visit): array
    {
        return [
            'id' => $visit->id,
            'status' => $visit->status,
            'purpose' => $visit->purpose,
            'gatePassNumber' => $visit->gate_pass_number,
            'checkedInAt' => $visit->checked_in_at->toIso8601String(),
            'checkedOutAt' => $visit->checked_out_at?->toIso8601String(),
            'visitor' => [
                'id' => $visit->visitor->id,
                'fullName' => $visit->visitor->full_name,
                'phone' => $visit->visitor->phone,
            ],
            'campus' => [
                'id' => $visit->campus->id,
                'name' => $visit->campus->name,
            ],
            'hostEmployee' => $visit->hostEmployee ? [
                'id' => $visit->hostEmployee->id,
                'fullName' => $visit->hostEmployee->full_name,
            ] : null,
        ];
    }
}
