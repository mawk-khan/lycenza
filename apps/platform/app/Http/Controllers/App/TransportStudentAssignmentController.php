<?php

namespace App\Http\Controllers\App;

use App\Domain\Students\Infrastructure\Student;
use App\Domain\Transport\Application\Exceptions\ConcurrentStudentAssignmentConflictException;
use App\Domain\Transport\Application\Exceptions\RouteNotAvailableException;
use App\Domain\Transport\Application\Exceptions\StopNotOnRouteException;
use App\Domain\Transport\Application\Exceptions\StudentAlreadyAssignedException;
use App\Domain\Transport\Application\Exceptions\StudentAssignmentAlreadyEndedException;
use App\Domain\Transport\Application\Exceptions\StudentNotEligibleException;
use App\Domain\Transport\Application\TransportStudentAssignmentService;
use App\Domain\Transport\Infrastructure\TransportRoute;
use App\Domain\Transport\Infrastructure\TransportStop;
use App\Domain\Transport\Infrastructure\TransportStudentAssignment;
use App\Http\Controllers\Controller;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Authorization\CapabilityResolver;
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
 */
class TransportStudentAssignmentController extends Controller
{
    use AuthorizesCapability;

    public function index(Request $request, TenantContext $context, CapabilityResolver $capabilities): Response
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('transport.assignments.view', $school);

        $assignments = TransportStudentAssignment::query()
            ->with(['student', 'route', 'pickupStop', 'dropoffStop'])
            ->where('status', 'active')
            ->orderByDesc('starts_on')
            ->paginate(20);

        return Inertia::render('App/Transport/Assignments/Index', [
            'assignments' => $assignments->through(fn (TransportStudentAssignment $a) => $this->present($a)),
            'canManage' => $capabilities->canInSchool($context->actor(), 'transport.assignments.manage', $school),
        ]);
    }

    public function create(TenantContext $context): Response
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('transport.assignments.manage', $school);

        return Inertia::render('App/Transport/Assignments/Create');
    }

    public function searchStudents(Request $request, TenantContext $context): JsonResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('transport.assignments.manage', $school);

        $term = (string) $request->query('q', '');

        $students = Student::query()
            ->where('status', 'active')
            ->when($term !== '', fn ($q) => $q->where('student_number', 'ilike', "%{$term}%")
                ->orWhere('first_name', 'ilike', "%{$term}%")
                ->orWhere('last_name', 'ilike', "%{$term}%"))
            ->limit(10)
            ->get();

        return response()->json(['data' => $students->map(fn (Student $s) => [
            'id' => $s->id,
            'studentNumber' => $s->student_number,
            'name' => trim($s->first_name.' '.$s->last_name),
        ])->all()]);
    }

    public function searchRoutes(Request $request, TenantContext $context): JsonResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('transport.assignments.manage', $school);

        $term = (string) $request->query('q', '');

        $routes = TransportRoute::query()
            ->where('status', 'active')
            ->when($term !== '', fn ($q) => $q->where('code', 'ilike', "%{$term}%")
                ->orWhere('name', 'ilike', "%{$term}%"))
            ->limit(10)
            ->get();

        return response()->json(['data' => $routes->map(fn (TransportRoute $r) => [
            'id' => $r->id,
            'code' => $r->code,
            'name' => $r->name,
        ])->all()]);
    }

    public function routeStops(TenantContext $context, string $transportRoute): JsonResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('transport.assignments.manage', $school);

        $route = TransportRoute::query()->findOrFail($transportRoute);

        $stops = $route->stops()->where('status', 'active')->orderBy('sequence')->get();

        return response()->json(['data' => $stops->map(fn (TransportStop $s) => [
            'id' => $s->id,
            'name' => $s->name,
            'sequence' => $s->sequence,
        ])->all()]);
    }

    public function store(Request $request, TenantContext $context, TransportStudentAssignmentService $service): RedirectResponse
    {
        $school = $context->requireSchool();
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

        try {
            $service->assign($student, $route, $pickupStop, $dropoffStop, $context->actor());
        } catch (StudentNotEligibleException|StudentAlreadyAssignedException|ConcurrentStudentAssignmentConflictException $e) {
            throw ValidationException::withMessages(['student_id' => [$e->getMessage()]]);
        } catch (RouteNotAvailableException $e) {
            throw ValidationException::withMessages(['route_id' => [$e->getMessage()]]);
        } catch (StopNotOnRouteException $e) {
            throw ValidationException::withMessages(['pickup_stop_id' => [$e->getMessage()]]);
        }

        return redirect('/app/transport/assignments')->with('flash', 'Student assigned to Transport.');
    }

    public function end(TenantContext $context, TransportStudentAssignmentService $service, string $transportStudentAssignment): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('transport.assignments.manage', $school);

        $model = TransportStudentAssignment::query()->findOrFail($transportStudentAssignment);

        try {
            $service->end($model, $context->actor());
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
