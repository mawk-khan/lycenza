<?php

namespace App\Http\Controllers\App;

use App\Domain\HR\Infrastructure\Employee;
use App\Domain\Transport\Application\Exceptions\ConcurrentRouteAssignmentConflictException;
use App\Domain\Transport\Application\Exceptions\DriverNotEligibleException;
use App\Domain\Transport\Application\Exceptions\RouteAssignmentAlreadyEndedException;
use App\Domain\Transport\Application\Exceptions\RouteNotAvailableException;
use App\Domain\Transport\Application\Exceptions\VehicleNotEligibleException;
use App\Domain\Transport\Application\TransportRouteAssignmentService;
use App\Domain\Transport\Infrastructure\TransportRoute;
use App\Domain\Transport\Infrastructure\TransportRouteAssignment;
use App\Domain\Transport\Infrastructure\TransportVehicle;
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
 * Phase 10B -- session-authenticated Inertia pages for a Route's
 * current Vehicle/Driver operational assignment. Every mutation
 * delegates to TransportRouteAssignmentService -- the exact same
 * service the JSON API controller uses, never duplicated here (matches
 * LibraryCirculationController/LibraryLoanService's established
 * split). The two search endpoints below explicitly call
 * authorizeCapability() -- checkpoint brief: "Remember the Phase 10A
 * Library security issue discovered in its circulation search
 * endpoints. Do not repeat that authorization mistake" (see
 * LibraryCirculationController::searchAvailableCopies()'s own
 * docblock for the incident this avoids).
 */
class TransportOperationsController extends Controller
{
    use AuthorizesCapability;

    public function index(Request $request, TenantContext $context, CapabilityResolver $capabilities): Response
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('transport.vehicles.view', $school);

        $routes = TransportRoute::query()
            ->where('status', 'active')
            ->with('activeRouteAssignment.vehicle', 'activeRouteAssignment.driver')
            ->orderBy('name')
            ->paginate(20);

        return Inertia::render('App/Transport/Operations/Index', [
            'routes' => $routes->through(fn (TransportRoute $r) => [
                'id' => $r->id,
                'code' => $r->code,
                'name' => $r->name,
                'currentAssignment' => $r->activeRouteAssignment ? [
                    'id' => $r->activeRouteAssignment->id,
                    'vehicleCode' => $r->activeRouteAssignment->vehicle->code,
                    'driverName' => $r->activeRouteAssignment->driver->full_name,
                ] : null,
            ]),
            'canManage' => $capabilities->canInSchool($context->actor(), 'transport.vehicles.manage', $school),
        ]);
    }

    public function show(TenantContext $context, CapabilityResolver $capabilities, string $transportRoute): Response
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('transport.vehicles.view', $school);

        $route = TransportRoute::query()->findOrFail($transportRoute);
        $assignments = $route->routeAssignments()->with(['vehicle', 'driver'])->orderByDesc('starts_on')->get();

        return Inertia::render('App/Transport/Operations/Show', [
            'route' => ['id' => $route->id, 'code' => $route->code, 'name' => $route->name],
            'assignments' => $assignments->map(fn (TransportRouteAssignment $a) => [
                'id' => $a->id,
                'status' => $a->status,
                'startsOn' => $a->starts_on->toDateString(),
                'endsOn' => $a->ends_on?->toDateString(),
                'vehicleCode' => $a->vehicle->code,
                'driverName' => $a->driver->full_name,
            ])->all(),
            'canManage' => $capabilities->canInSchool($context->actor(), 'transport.vehicles.manage', $school),
        ]);
    }

    public function searchVehicles(Request $request, TenantContext $context): JsonResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('transport.vehicles.manage', $school);

        $term = (string) $request->query('q', '');

        $vehicles = TransportVehicle::query()
            ->where('status', 'active')
            ->when($term !== '', fn ($q) => $q->where('code', 'ilike', "%{$term}%")
                ->orWhere('registration_number', 'ilike', "%{$term}%"))
            ->limit(10)
            ->get();

        return response()->json(['data' => $vehicles->map(fn (TransportVehicle $v) => [
            'id' => $v->id,
            'code' => $v->code,
            'registrationNumber' => $v->registration_number,
        ])->all()]);
    }

    public function searchDrivers(Request $request, TenantContext $context): JsonResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('transport.vehicles.manage', $school);

        $term = (string) $request->query('q', '');

        $employees = Employee::query()
            ->where('record_status', 'active')
            ->when($term !== '', fn ($q) => $q->where('employee_number', 'ilike', "%{$term}%")
                ->orWhere('full_name', 'ilike', "%{$term}%"))
            ->limit(10)
            ->get();

        return response()->json(['data' => $employees->map(fn (Employee $e) => [
            'id' => $e->id,
            'employeeNumber' => $e->employee_number,
            'fullName' => $e->full_name,
        ])->all()]);
    }

    public function store(Request $request, TenantContext $context, string $transportRoute, TransportRouteAssignmentService $service): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('transport.vehicles.manage', $school);

        $route = TransportRoute::query()->findOrFail($transportRoute);

        $validated = $request->validate([
            'vehicle_id' => ['required', 'string'],
            'driver_employee_id' => ['required', 'string'],
        ]);

        $vehicle = TransportVehicle::query()->findOrFail($validated['vehicle_id']);
        $driver = Employee::query()->findOrFail($validated['driver_employee_id']);

        try {
            $service->assign($route, $vehicle, $driver, $context->actor());
        } catch (VehicleNotEligibleException $e) {
            throw ValidationException::withMessages(['vehicle_id' => [$e->getMessage()]]);
        } catch (DriverNotEligibleException $e) {
            throw ValidationException::withMessages(['driver_employee_id' => [$e->getMessage()]]);
        } catch (RouteNotAvailableException|ConcurrentRouteAssignmentConflictException $e) {
            throw ValidationException::withMessages(['vehicle_id' => [$e->getMessage()]]);
        }

        return redirect("/app/transport/operations/{$route->id}")->with('flash', 'Vehicle/driver assigned.');
    }

    public function end(TenantContext $context, TransportRouteAssignmentService $service, string $transportRouteAssignment): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('transport.vehicles.manage', $school);

        $model = TransportRouteAssignment::query()->findOrFail($transportRouteAssignment);

        try {
            $service->end($model, $context->actor());
        } catch (RouteAssignmentAlreadyEndedException $e) {
            throw ValidationException::withMessages(['transport_route_assignment' => [$e->getMessage()]]);
        }

        return redirect("/app/transport/operations/{$model->route_id}")->with('flash', 'Assignment ended.');
    }
}
