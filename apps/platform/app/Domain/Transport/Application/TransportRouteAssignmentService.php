<?php

namespace App\Domain\Transport\Application;

use App\Domain\HR\Infrastructure\Employee;
use App\Domain\Transport\Application\Exceptions\ConcurrentRouteAssignmentConflictException;
use App\Domain\Transport\Application\Exceptions\DriverNotEligibleException;
use App\Domain\Transport\Application\Exceptions\RouteAssignmentAlreadyEndedException;
use App\Domain\Transport\Application\Exceptions\RouteNotAvailableException;
use App\Domain\Transport\Application\Exceptions\VehicleNotEligibleException;
use App\Domain\Transport\Infrastructure\TransportRoute;
use App\Domain\Transport\Infrastructure\TransportRouteAssignment;
use App\Domain\Transport\Infrastructure\TransportVehicle;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * The only sanctioned write path for a Route's Vehicle/Driver
 * operational configuration (docs/modules/TRANSPORT.md "Route
 * operational assignment decision"). Unlike Student assignment below,
 * `assign()` here follows the AcademicYearService::activate()
 * auto-replace precedent: assigning a new Vehicle/Driver to a Route
 * automatically ends whatever was previously active for that Route in
 * the SAME transaction -- day-to-day operational reassignment (a
 * replacement driver, a swapped vehicle) is administrative
 * housekeeping, not a fact worth forcing a separate explicit end()
 * call for.
 */
class TransportRouteAssignmentService
{
    public function __construct(
        private readonly AuditRecorder $audit,
    ) {}

    /**
     * `lockForUpdate()` on the Route row itself (not the assignment
     * row, which may not yet exist) serializes any two concurrent
     * assign() calls for the SAME Route before either reaches the
     * "previous active assignment" read -- the same locking discipline
     * AcademicYearService::activate() uses for AcademicYear. The
     * database's partial unique index
     * (`transport_route_assignments_one_active_per_route`) remains the
     * authoritative, always-on backstop regardless of this method's
     * own locking.
     */
    public function assign(TransportRoute $route, TransportVehicle $vehicle, Employee $driver, ?User $actor = null): TransportRouteAssignment
    {
        if (! $vehicle->isActive()) {
            throw new VehicleNotEligibleException;
        }

        if (! $driver->isActive()) {
            throw new DriverNotEligibleException;
        }

        try {
            return DB::transaction(function () use ($route, $vehicle, $driver, $actor) {
                $lockedRoute = TransportRoute::query()
                    ->where('id', $route->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                if (! $lockedRoute->isActive()) {
                    throw new RouteNotAvailableException;
                }

                $previousActive = TransportRouteAssignment::query()
                    ->where('route_id', $route->id)
                    ->where('status', 'active')
                    ->first();

                if ($previousActive !== null) {
                    $previousActive->update(['status' => 'ended', 'ends_on' => now()]);
                }

                $assignment = TransportRouteAssignment::query()->create([
                    'school_id' => $route->school_id,
                    'route_id' => $route->id,
                    'vehicle_id' => $vehicle->id,
                    'driver_employee_id' => $driver->id,
                    'status' => 'active',
                    'starts_on' => now(),
                    'ends_on' => null,
                ]);

                $this->audit->school($route->school, 'transport.route_assignment.assigned', actor: $actor, subject: $assignment, metadata: [
                    'transportRouteId' => $route->id,
                    'transportVehicleId' => $vehicle->id,
                    'driverEmployeeId' => $driver->id,
                    'previousAssignmentId' => $previousActive?->id,
                ]);

                return $assignment;
            });
        } catch (UniqueConstraintViolationException) {
            throw new ConcurrentRouteAssignmentConflictException;
        }
    }

    /**
     * A conditional `UPDATE ... WHERE status = 'active'` (not a blind
     * `$assignment->update(...)`), mirroring
     * LibraryLoanService::checkIn()'s same discipline.
     */
    public function end(TransportRouteAssignment $assignment, ?User $actor = null): TransportRouteAssignment
    {
        return DB::transaction(function () use ($assignment, $actor) {
            $affected = TransportRouteAssignment::query()
                ->where('id', $assignment->id)
                ->where('status', 'active')
                ->update(['status' => 'ended', 'ends_on' => now()]);

            if ($affected === 0) {
                throw new RouteAssignmentAlreadyEndedException;
            }

            $fresh = $assignment->refresh();

            $this->audit->school($assignment->school, 'transport.route_assignment.ended', actor: $actor, subject: $fresh, metadata: [
                'transportRouteId' => $assignment->route_id,
                'transportVehicleId' => $assignment->vehicle_id,
                'driverEmployeeId' => $assignment->driver_employee_id,
            ]);

            return $fresh;
        });
    }
}
