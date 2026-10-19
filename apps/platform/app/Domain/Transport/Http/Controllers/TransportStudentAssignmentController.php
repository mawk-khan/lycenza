<?php

namespace App\Domain\Transport\Http\Controllers;

use App\Domain\Transport\Application\TransportRiderAccess;
use App\Domain\Transport\Application\TransportStudentAssignmentService;
use App\Domain\Transport\Infrastructure\TransportStudentAssignment;
use App\Http\Controllers\Controller;
use App\Models\School;
use App\Support\Authorization\Campus\AuthorizesCampusScope;
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
 *
 * ORG.7D-5 (ADR 0076 §41): `transport.assignments.*` are Campus-adopted --
 * every action resolves its CampusScope and goes through
 * TransportRiderAccess (by the route's Campus; out of scope = NOT FOUND); a
 * Campus-scoped assignment is proven under lock (TransportRiderCampusGuard).
 */
class TransportStudentAssignmentController extends Controller
{
    use AuthorizesCampusScope;

    public function index(Request $request, School $school, TransportRiderAccess $access): JsonResponse
    {
        $scope = $this->authorizeCampusScope('transport.assignments.view', $school);

        $validated = $request->validate([
            'status' => ['sometimes', Rule::in(['active', 'ended'])],
            'student_id' => ['sometimes', 'string'],
        ]);

        // The student_id filter only narrows the scoped set.
        $query = $access->assignments($scope)
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

    public function store(Request $request, School $school, TransportStudentAssignmentService $service, TransportRiderAccess $access): JsonResponse
    {
        $scope = $this->authorizeCampusScope('transport.assignments.manage', $school);

        $validated = $request->validate([
            'student_id' => ['required', 'string'],
            'route_id' => ['required', 'string'],
            'pickup_stop_id' => ['nullable', 'string'],
            'dropoff_stop_id' => ['nullable', 'string'],
        ]);

        $route = $access->scopedRoute($scope, $validated['route_id']);
        $pickupStop = isset($validated['pickup_stop_id']) ? $access->stop($scope, $validated['pickup_stop_id']) : null;
        $dropoffStop = isset($validated['dropoff_stop_id']) ? $access->stop($scope, $validated['dropoff_stop_id']) : null;
        // Student-independent refusals first (never an out-of-scope Student oracle).
        $service->assertRouteAndStops($route, $pickupStop, $dropoffStop);
        $student = $access->rider($scope, $validated['student_id']);

        $assignment = $service->assign($student, $route, $pickupStop, $dropoffStop, $request->user(), $access->writeGuard($school, $scope));

        return response()->json(['data' => $this->present($assignment->load(['student', 'route', 'pickupStop', 'dropoffStop']))], 201);
    }

    public function show(School $school, string $transportStudentAssignment, TransportRiderAccess $access): JsonResponse
    {
        $scope = $this->authorizeCampusScope('transport.assignments.view', $school);

        $model = $access->assignment($scope, $transportStudentAssignment)->load(['student', 'route', 'pickupStop', 'dropoffStop']);

        return response()->json(['data' => $this->present($model)]);
    }

    public function end(Request $request, School $school, string $transportStudentAssignment, TransportStudentAssignmentService $service, TransportRiderAccess $access): JsonResponse
    {
        $scope = $this->authorizeCampusScope('transport.assignments.manage', $school);

        $model = $access->assignment($scope, $transportStudentAssignment);

        $assignment = $service->end($model, $request->user(), $access->writeGuard($school, $scope));

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
