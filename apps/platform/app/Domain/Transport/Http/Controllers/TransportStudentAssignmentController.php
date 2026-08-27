<?php

namespace App\Domain\Transport\Http\Controllers;

use App\Domain\Students\Infrastructure\Student;
use App\Domain\Transport\Application\TransportStudentAssignmentService;
use App\Domain\Transport\Infrastructure\TransportRoute;
use App\Domain\Transport\Infrastructure\TransportStop;
use App\Domain\Transport\Infrastructure\TransportStudentAssignment;
use App\Http\Controllers\Controller;
use App\Models\School;
use App\Support\Authorization\AuthorizesCapability;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Phase 10B -- a Student's Transport assignment administrative API.
 * store() (the consequential assign mutation) carries the
 * `idempotent` middleware in routes/api.php, mirroring
 * LibraryLoanController::store() -- see
 * TransportStudentAssignmentService's docblock for the concurrency
 * guarantee this endpoint delegates to.
 */
class TransportStudentAssignmentController extends Controller
{
    use AuthorizesCapability;

    public function index(Request $request, School $school): JsonResponse
    {
        $this->authorizeCapability('transport.assignments.view', $school);

        $validated = $request->validate([
            'status' => ['sometimes', Rule::in(['active', 'ended'])],
            'student_id' => ['sometimes', 'string'],
        ]);

        $query = TransportStudentAssignment::query()
            ->with(['student', 'route', 'pickupStop', 'dropoffStop'])
            ->orderByDesc('starts_on');

        $query->where('status', $validated['status'] ?? 'active');

        if (isset($validated['student_id'])) {
            $query->where('student_id', $validated['student_id']);
        }

        $paginator = $query->paginate(20)->withQueryString();

        return response()->json([
            'data' => $paginator->through(fn (TransportStudentAssignment $a) => $this->present($a))->items(),
            'meta' => [
                'currentPage' => $paginator->currentPage(),
                'lastPage' => $paginator->lastPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function store(Request $request, School $school, TransportStudentAssignmentService $service): JsonResponse
    {
        $this->authorizeCapability('transport.assignments.manage', $school);

        $validated = $request->validate([
            'student_id' => ['required', 'string'],
            'route_id' => ['required', 'string'],
            'pickup_stop_id' => ['nullable', 'string'],
            'dropoff_stop_id' => ['nullable', 'string'],
        ]);

        $student = Student::query()->findOrFail($validated['student_id']);
        $route = TransportRoute::query()->findOrFail($validated['route_id']);
        $pickupStop = isset($validated['pickup_stop_id']) ? TransportStop::query()->findOrFail($validated['pickup_stop_id']) : null;
        $dropoffStop = isset($validated['dropoff_stop_id']) ? TransportStop::query()->findOrFail($validated['dropoff_stop_id']) : null;

        $assignment = $service->assign($student, $route, $pickupStop, $dropoffStop, $request->user());

        return response()->json(['data' => $this->present($assignment->load(['student', 'route', 'pickupStop', 'dropoffStop']))], 201);
    }

    public function show(School $school, string $transportStudentAssignment): JsonResponse
    {
        $this->authorizeCapability('transport.assignments.view', $school);

        $model = TransportStudentAssignment::query()->with(['student', 'route', 'pickupStop', 'dropoffStop'])->findOrFail($transportStudentAssignment);

        return response()->json(['data' => $this->present($model)]);
    }

    public function end(Request $request, School $school, string $transportStudentAssignment, TransportStudentAssignmentService $service): JsonResponse
    {
        $this->authorizeCapability('transport.assignments.manage', $school);

        $model = TransportStudentAssignment::query()->findOrFail($transportStudentAssignment);

        $assignment = $service->end($model, $request->user());

        return response()->json(['data' => $this->present($assignment->load(['student', 'route', 'pickupStop', 'dropoffStop']))]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(TransportStudentAssignment $assignment): array
    {
        return [
            'id' => $assignment->id,
            'status' => $assignment->status,
            'startsOn' => $assignment->starts_on->toIso8601String(),
            'endsOn' => $assignment->ends_on?->toIso8601String(),
            'student' => [
                'id' => $assignment->student->id,
                'studentNumber' => $assignment->student->student_number,
                'firstName' => $assignment->student->first_name,
                'lastName' => $assignment->student->last_name,
            ],
            'route' => [
                'id' => $assignment->route->id,
                'code' => $assignment->route->code,
                'name' => $assignment->route->name,
            ],
            'pickupStop' => $assignment->pickupStop ? [
                'id' => $assignment->pickupStop->id,
                'name' => $assignment->pickupStop->name,
            ] : null,
            'dropoffStop' => $assignment->dropoffStop ? [
                'id' => $assignment->dropoffStop->id,
                'name' => $assignment->dropoffStop->name,
            ] : null,
        ];
    }
}
