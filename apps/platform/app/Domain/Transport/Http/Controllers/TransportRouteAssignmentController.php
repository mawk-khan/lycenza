<?php

namespace App\Domain\Transport\Http\Controllers;

use App\Domain\HR\Infrastructure\Employee;
use App\Domain\Transport\Application\TransportRouteAssignmentService;
use App\Domain\Transport\Infrastructure\TransportRoute;
use App\Domain\Transport\Infrastructure\TransportRouteAssignment;
use App\Domain\Transport\Infrastructure\TransportVehicle;
use App\Http\Controllers\Controller;
use App\Models\School;
use App\Support\Authorization\AuthorizesCapability;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Phase 10B -- a Route's operational (Vehicle/Driver) assignment
 * administrative API. store() (the consequential assign mutation)
 * carries the `idempotent` middleware in routes/api.php, mirroring
 * LibraryLoanController::store() -- see TransportRouteAssignmentService's
 * docblock for the concurrency guarantee this endpoint delegates to.
 */
class TransportRouteAssignmentController extends Controller
{
    use AuthorizesCapability;

    public function index(Request $request, School $school, string $transportRoute): JsonResponse
    {
        $this->authorizeCapability('transport.vehicles.view', $school);

        $route = TransportRoute::query()->findOrFail($transportRoute);

        $assignments = $route->routeAssignments()->with(['vehicle', 'driver'])->orderByDesc('starts_on')->get();

        return response()->json(['data' => $assignments->map(fn (TransportRouteAssignment $a) => $this->present($a))->all()]);
    }

    public function store(Request $request, School $school, string $transportRoute, TransportRouteAssignmentService $service): JsonResponse
    {
        $this->authorizeCapability('transport.vehicles.manage', $school);

        $route = TransportRoute::query()->findOrFail($transportRoute);

        $validated = $request->validate([
            'vehicle_id' => ['required', 'string'],
            'driver_employee_id' => ['required', 'string'],
        ]);

        $vehicle = TransportVehicle::query()->findOrFail($validated['vehicle_id']);
        $driver = Employee::query()->findOrFail($validated['driver_employee_id']);

        $assignment = $service->assign($route, $vehicle, $driver, $request->user());

        return response()->json(['data' => $this->present($assignment->load(['vehicle', 'driver']))], 201);
    }

    public function end(Request $request, School $school, string $transportRouteAssignment, TransportRouteAssignmentService $service): JsonResponse
    {
        $this->authorizeCapability('transport.vehicles.manage', $school);

        $model = TransportRouteAssignment::query()->findOrFail($transportRouteAssignment);

        $assignment = $service->end($model, $request->user());

        return response()->json(['data' => $this->present($assignment->load(['vehicle', 'driver']))]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(TransportRouteAssignment $assignment): array
    {
        return [
            'id' => $assignment->id,
            'routeId' => $assignment->route_id,
            'status' => $assignment->status,
            'startsOn' => $assignment->starts_on->toIso8601String(),
            'endsOn' => $assignment->ends_on?->toIso8601String(),
            'vehicle' => [
                'id' => $assignment->vehicle->id,
                'code' => $assignment->vehicle->code,
                'registrationNumber' => $assignment->vehicle->registration_number,
            ],
            'driver' => [
                'id' => $assignment->driver->id,
                'employeeNumber' => $assignment->driver->employee_number,
                'fullName' => $assignment->driver->full_name,
            ],
        ];
    }
}
