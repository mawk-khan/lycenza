<?php

namespace Tests\Feature\Transport;

use App\Domain\Transport\Application\Exceptions\DriverNotEligibleException;
use App\Domain\Transport\Application\Exceptions\RouteAssignmentAlreadyEndedException;
use App\Domain\Transport\Application\Exceptions\RouteNotAvailableException;
use App\Domain\Transport\Application\Exceptions\VehicleNotEligibleException;
use App\Domain\Transport\Application\TransportRouteAssignmentService;
use App\Models\SchoolAuditEvent;
use App\Support\Tenancy\TenantContext;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 10B -- Route operational (Vehicle/Driver) assignment lifecycle.
 * Every test exercises the real
 * App\Domain\Transport\Application\TransportRouteAssignmentService,
 * never a raw model write, mirroring LibraryLoanServiceTest's
 * convention.
 */
class TransportRouteAssignmentServiceTest extends TestCase
{
    use CreatesTenancyFixtures;

    #[Test]
    public function assign_creates_an_active_assignment_and_an_audit_event(): void
    {
        [$admin, $school] = $this->createSchoolAdmin();
        $route = $this->createTransportRoute($school);
        $vehicle = $this->createTransportVehicle($school);
        $driver = $this->createEmployee($school);

        $assignment = app(TenantContext::class)->withSchool($school, fn () => app(TransportRouteAssignmentService::class)->assign($route, $vehicle, $driver, $admin));

        $this->assertSame('active', $assignment->status);
        $this->assertSame($vehicle->id, $assignment->vehicle_id);
        $this->assertSame($driver->id, $assignment->driver_employee_id);
        $this->assertNull($assignment->ends_on);

        $event = app(TenantContext::class)->withSchool($school, fn () => SchoolAuditEvent::query()
            ->where('event_type', 'transport.route_assignment.assigned')
            ->where('subject_id', $assignment->id)
            ->first());
        $this->assertNotNull($event, 'A transport.route_assignment.assigned audit event must be recorded.');
        $this->assertSame($admin->id, $event->actor_user_id);
    }

    #[Test]
    public function assigning_a_new_vehicle_driver_auto_replaces_and_ends_the_previous_assignment(): void
    {
        [$admin, $school] = $this->createSchoolAdmin();
        $route = $this->createTransportRoute($school);
        $vehicleOne = $this->createTransportVehicle($school);
        $vehicleTwo = $this->createTransportVehicle($school);
        $driverOne = $this->createEmployee($school);
        $driverTwo = $this->createEmployee($school);
        $service = app(TransportRouteAssignmentService::class);

        $first = app(TenantContext::class)->withSchool($school, fn () => $service->assign($route, $vehicleOne, $driverOne, $admin));
        $second = app(TenantContext::class)->withSchool($school, fn () => $service->assign($route, $vehicleTwo, $driverTwo, $admin));

        $freshFirst = app(TenantContext::class)->withSchool($school, fn () => $first->fresh());
        $this->assertSame('ended', $freshFirst->status);
        $this->assertNotNull($freshFirst->ends_on);
        $this->assertSame('active', $second->status);
    }

    #[Test]
    public function assign_refuses_an_inactive_vehicle(): void
    {
        [$admin, $school] = $this->createSchoolAdmin();
        $route = $this->createTransportRoute($school);
        $vehicle = $this->createTransportVehicle($school, ['status' => 'inactive']);
        $driver = $this->createEmployee($school);

        $this->expectException(VehicleNotEligibleException::class);
        app(TenantContext::class)->withSchool($school, fn () => app(TransportRouteAssignmentService::class)->assign($route, $vehicle, $driver, $admin));
    }

    #[Test]
    public function assign_refuses_an_inactive_driver_employee(): void
    {
        [$admin, $school] = $this->createSchoolAdmin();
        $route = $this->createTransportRoute($school);
        $vehicle = $this->createTransportVehicle($school);
        $driver = $this->createEmployee($school, ['record_status' => 'archived']);

        $this->expectException(DriverNotEligibleException::class);
        app(TenantContext::class)->withSchool($school, fn () => app(TransportRouteAssignmentService::class)->assign($route, $vehicle, $driver, $admin));
    }

    #[Test]
    public function assign_refuses_an_inactive_route(): void
    {
        [$admin, $school] = $this->createSchoolAdmin();
        $route = $this->createTransportRoute($school, ['status' => 'inactive']);
        $vehicle = $this->createTransportVehicle($school);
        $driver = $this->createEmployee($school);

        $this->expectException(RouteNotAvailableException::class);
        app(TenantContext::class)->withSchool($school, fn () => app(TransportRouteAssignmentService::class)->assign($route, $vehicle, $driver, $admin));
    }

    #[Test]
    public function end_marks_the_assignment_ended_and_records_an_audit_event(): void
    {
        [$admin, $school] = $this->createSchoolAdmin();
        $route = $this->createTransportRoute($school);
        $vehicle = $this->createTransportVehicle($school);
        $driver = $this->createEmployee($school);
        $assignment = app(TenantContext::class)->withSchool($school, fn () => app(TransportRouteAssignmentService::class)->assign($route, $vehicle, $driver, $admin));

        $ended = app(TenantContext::class)->withSchool($school, fn () => app(TransportRouteAssignmentService::class)->end($assignment, $admin));

        $this->assertSame('ended', $ended->status);
        $this->assertNotNull($ended->ends_on);

        $event = app(TenantContext::class)->withSchool($school, fn () => SchoolAuditEvent::query()
            ->where('event_type', 'transport.route_assignment.ended')
            ->where('subject_id', $assignment->id)
            ->first());
        $this->assertNotNull($event);
    }

    #[Test]
    public function end_cannot_be_performed_twice(): void
    {
        [$admin, $school] = $this->createSchoolAdmin();
        $route = $this->createTransportRoute($school);
        $vehicle = $this->createTransportVehicle($school);
        $driver = $this->createEmployee($school);
        $assignment = app(TenantContext::class)->withSchool($school, fn () => app(TransportRouteAssignmentService::class)->assign($route, $vehicle, $driver, $admin));

        app(TenantContext::class)->withSchool($school, fn () => app(TransportRouteAssignmentService::class)->end($assignment, $admin));

        $this->expectException(RouteAssignmentAlreadyEndedException::class);
        app(TenantContext::class)->withSchool($school, fn () => app(TransportRouteAssignmentService::class)->end($assignment, $admin));
    }

    #[Test]
    public function historical_assignments_remain_valid_after_the_vehicle_and_route_are_deactivated(): void
    {
        [$admin, $school] = $this->createSchoolAdmin();
        $route = $this->createTransportRoute($school);
        $vehicle = $this->createTransportVehicle($school);
        $driver = $this->createEmployee($school);
        $assignment = app(TenantContext::class)->withSchool($school, fn () => app(TransportRouteAssignmentService::class)->assign($route, $vehicle, $driver, $admin));
        app(TenantContext::class)->withSchool($school, fn () => app(TransportRouteAssignmentService::class)->end($assignment, $admin));

        $vehicle->update(['status' => 'inactive']);
        $route->update(['status' => 'inactive']);

        $fresh = app(TenantContext::class)->withSchool($school, fn () => $assignment->fresh());
        $this->assertSame('ended', $fresh->status);
        $this->assertSame($vehicle->id, $fresh->vehicle_id);
        $this->assertSame($route->id, $fresh->route_id);
    }
}
