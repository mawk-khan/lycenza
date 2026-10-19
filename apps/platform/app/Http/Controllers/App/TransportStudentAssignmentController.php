<?php

namespace App\Http\Controllers\App;

use App\Domain\Students\Application\StudentLookupCandidate;
use App\Domain\Transport\Application\Exceptions\ConcurrentStudentAssignmentConflictException;
use App\Domain\Transport\Application\Exceptions\RouteNotAvailableException;
use App\Domain\Transport\Application\Exceptions\StopNotOnRouteException;
use App\Domain\Transport\Application\Exceptions\StudentAlreadyAssignedException;
use App\Domain\Transport\Application\Exceptions\StudentAssignmentAlreadyEndedException;
use App\Domain\Transport\Application\Exceptions\StudentNotEligibleException;
use App\Domain\Transport\Application\Exceptions\TransportAssignmentUnavailableException;
use App\Domain\Transport\Application\TransportRiderAccess;
use App\Domain\Transport\Application\TransportStudentAssignmentService;
use App\Domain\Transport\Infrastructure\TransportRoute;
use App\Domain\Transport\Infrastructure\TransportStop;
use App\Domain\Transport\Infrastructure\TransportStudentAssignment;
use App\Http\Controllers\Controller;
use App\Support\Authorization\Campus\AuthorizesCampusScope;
use App\Support\Authorization\Campus\CampusScopeResolver;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Phase 10B -- session-authenticated Inertia pages for Student
 * Transport assignment. Every mutation delegates to
 * TransportStudentAssignmentService, the exact same service the JSON
 * API controller uses. The Student search endpoint below explicitly
 * calls authorizeCapability() -- checkpoint brief: "Remember the Phase
 * 10A Library security issue discovered in its circulation search
 * endpoints. Do not repeat that authorization mistake."
 *
 * ORG.7D-5 (ADR 0076 §41): `transport.assignments.*` are Campus-adopted --
 * every action resolves its CampusScope and goes through
 * TransportRiderAccess: riders by their route's Campus, the route and stop
 * pickers over Campus-bound routes in scope, the Student picker over current
 * operational Students of those Campuses; a Campus-scoped assignment is
 * proven under lock (TransportRiderCampusGuard).
 */
class TransportStudentAssignmentController extends Controller
{
    use AuthorizesCampusScope;

    public function index(Request $request, TenantContext $context, CampusScopeResolver $scopes, TransportRiderAccess $access): Response
    {
        $school = $context->requireSchool();
        $scope = $this->authorizeCampusScope('transport.assignments.view', $school);

        $assignments = $access->assignments($scope)
            ->with(['student', 'route', 'pickupStop', 'dropoffStop'])
            ->where('status', 'active')
            ->orderByDesc('starts_on')
            ->paginate(20);

        return Inertia::render('App/Transport/Assignments/Index', [
            'assignments' => $assignments->through(fn (TransportStudentAssignment $a) => $this->present($a)),
            'canManage' => ! $scopes->campusScope($context->actor(), 'transport.assignments.manage', $school)->isNone(),
        ]);
    }

    public function create(TenantContext $context): Response
    {
        $school = $context->requireSchool();
        $this->authorizeCampusScope('transport.assignments.manage', $school);

        return Inertia::render('App/Transport/Assignments/Create');
    }

    public function searchStudents(Request $request, TenantContext $context, TransportRiderAccess $access): JsonResponse
    {
        $school = $context->requireSchool();
        $scope = $this->authorizeCampusScope('transport.assignments.manage', $school);

        $term = (string) $request->query('q', '');

        return response()->json(['data' => array_map(fn (StudentLookupCandidate $c) => [
            'id' => $c->id,
            'studentNumber' => $c->studentNumber,
            'name' => $c->displayName,
        ], $access->riderCandidates($school, $scope, $term))]);
    }

    public function searchRoutes(Request $request, TenantContext $context, TransportRiderAccess $access): JsonResponse
    {
        $school = $context->requireSchool();
        $scope = $this->authorizeCampusScope('transport.assignments.manage', $school);

        $term = (string) $request->query('q', '');
        $like = '%'.addcslashes($term, '%_\\').'%';

        $routes = $access->routes($scope)
            ->where('status', 'active')
            ->when($term !== '', fn ($q) => $q->where(fn ($w) => $w->where('code', 'ilike', $like)
                ->orWhere('name', 'ilike', $like)))
            ->limit(10)
            ->get();

        return response()->json(['data' => $routes->map(fn (TransportRoute $r) => [
            'id' => $r->id,
            'code' => $r->code,
            'name' => $r->name,
        ])->all()]);
    }

    public function routeStops(TenantContext $context, TransportRiderAccess $access, string $transportRoute): JsonResponse
    {
        $school = $context->requireSchool();
        $scope = $this->authorizeCampusScope('transport.assignments.manage', $school);

        $route = $access->route($scope, $transportRoute);

        $stops = $route->stops()->where('status', 'active')->orderBy('sequence')->get();

        return response()->json(['data' => $stops->map(fn (TransportStop $s) => [
            'id' => $s->id,
            'name' => $s->name,
            'sequence' => $s->sequence,
        ])->all()]);
    }

    public function store(Request $request, TenantContext $context, TransportStudentAssignmentService $service, TransportRiderAccess $access): RedirectResponse
    {
        $school = $context->requireSchool();
        $scope = $this->authorizeCampusScope('transport.assignments.manage', $school);

        $validated = $request->validate([
            'student_id' => ['required', 'string'],
            'route_id' => ['required', 'string'],
            'pickup_stop_id' => ['nullable', 'string'],
            'dropoff_stop_id' => ['nullable', 'string'],
        ]);

        $route = $access->route($scope, $validated['route_id']);
        $pickupStop = isset($validated['pickup_stop_id']) ? $access->stop($scope, $validated['pickup_stop_id']) : null;
        $dropoffStop = isset($validated['dropoff_stop_id']) ? $access->stop($scope, $validated['dropoff_stop_id']) : null;

        try {
            // Student-independent refusals first (never an out-of-scope Student oracle).
            $service->assertRouteAndStops($route, $pickupStop, $dropoffStop);
            $student = $access->rider($scope, $validated['student_id']);
            $service->assign($student, $route, $pickupStop, $dropoffStop, $context->actor(), $access->writeGuard($school, $scope));
        } catch (TransportAssignmentUnavailableException $e) {
            throw ValidationException::withMessages(['student_id' => [$e->getMessage()]]);
        } catch (StudentNotEligibleException|StudentAlreadyAssignedException|ConcurrentStudentAssignmentConflictException $e) {
            throw ValidationException::withMessages(['student_id' => [$e->getMessage()]]);
        } catch (RouteNotAvailableException $e) {
            throw ValidationException::withMessages(['route_id' => [$e->getMessage()]]);
        } catch (StopNotOnRouteException $e) {
            throw ValidationException::withMessages(['pickup_stop_id' => [$e->getMessage()]]);
        }

        return redirect('/app/transport/assignments')->with('flash', 'Student assigned to Transport.');
    }

    public function end(TenantContext $context, TransportStudentAssignmentService $service, TransportRiderAccess $access, string $transportStudentAssignment): RedirectResponse
    {
        $school = $context->requireSchool();
        $scope = $this->authorizeCampusScope('transport.assignments.manage', $school);

        $model = $access->assignment($scope, $transportStudentAssignment);

        try {
            $service->end($model, $context->actor(), $access->writeGuard($school, $scope));
        } catch (StudentAssignmentAlreadyEndedException $e) {
            throw ValidationException::withMessages(['transport_student_assignment' => [$e->getMessage()]]);
        }

        return redirect('/app/transport/assignments')->with('flash', 'Transport assignment ended.');
    }

    /**
     * @return array<string, mixed>
     */
    private function present(TransportStudentAssignment $assignment): array
    {
        return [
            'id' => $assignment->id,
            'studentName' => trim($assignment->student->first_name.' '.$assignment->student->last_name),
            'studentNumber' => $assignment->student->student_number,
            'routeName' => $assignment->route->name,
            'pickupStopName' => $assignment->pickupStop?->name,
            'dropoffStopName' => $assignment->dropoffStop?->name,
            'startsOn' => $assignment->starts_on->toDateString(),
        ];
    }
}
