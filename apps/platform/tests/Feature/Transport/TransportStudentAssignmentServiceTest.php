<?php

namespace Tests\Feature\Transport;

use App\Domain\Transport\Application\Exceptions\RouteNotAvailableException;
use App\Domain\Transport\Application\Exceptions\StopNotOnRouteException;
use App\Domain\Transport\Application\Exceptions\StudentAlreadyAssignedException;
use App\Domain\Transport\Application\Exceptions\StudentAssignmentAlreadyEndedException;
use App\Domain\Transport\Application\Exceptions\StudentNotEligibleException;
use App\Domain\Transport\Application\TransportStudentAssignmentService;
use App\Models\SchoolAuditEvent;
use App\Support\Tenancy\TenantContext;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 10B -- Student Transport assignment lifecycle. Every test
 * exercises the real
 * App\Domain\Transport\Application\TransportStudentAssignmentService,
 * never a raw model write, mirroring LibraryLoanServiceTest's
 * convention.
 */
class TransportStudentAssignmentServiceTest extends TestCase
{
    use CreatesTenancyFixtures;

    #[Test]
    public function assign_creates_an_active_assignment_and_an_audit_event(): void
    {
        [$admin, $school] = $this->createSchoolAdmin();
        $route = $this->createTransportRoute($school);
        $pickup = $this->createTransportStop($route, ['sequence' => 1]);
        $dropoff = $this->createTransportStop($route, ['sequence' => 2]);
        $student = $this->createStudent($school, ['status' => 'active']);

        $assignment = app(TenantContext::class)->withSchool($school, fn () => app(TransportStudentAssignmentService::class)->assign($student, $route, $pickup, $dropoff, $admin));

        $this->assertSame('active', $assignment->status);
        $this->assertSame($route->id, $assignment->route_id);
        $this->assertSame($pickup->id, $assignment->pickup_stop_id);
        $this->assertSame($dropoff->id, $assignment->dropoff_stop_id);
        $this->assertNull($assignment->ends_on);

        $event = app(TenantContext::class)->withSchool($school, fn () => SchoolAuditEvent::query()
            ->where('event_type', 'transport.student_assignment.assigned')
            ->where('subject_id', $assignment->id)
            ->first());
        $this->assertNotNull($event, 'A transport.student_assignment.assigned audit event must be recorded.');
        $this->assertSame($admin->id, $event->actor_user_id);
    }

    #[Test]
    public function assign_works_without_pickup_or_dropoff_stops(): void
    {
        [$admin, $school] = $this->createSchoolAdmin();
        $route = $this->createTransportRoute($school);
        $student = $this->createStudent($school, ['status' => 'active']);

        $assignment = app(TenantContext::class)->withSchool($school, fn () => app(TransportStudentAssignmentService::class)->assign($student, $route, null, null, $admin));

        $this->assertSame('active', $assignment->status);
        $this->assertNull($assignment->pickup_stop_id);
        $this->assertNull($assignment->dropoff_stop_id);
    }

    #[Test]
    public function assign_refuses_a_student_that_already_has_an_active_assignment(): void
    {
        [$admin, $school] = $this->createSchoolAdmin();
        $routeOne = $this->createTransportRoute($school);
        $routeTwo = $this->createTransportRoute($school);
        $student = $this->createStudent($school, ['status' => 'active']);

        app(TenantContext::class)->withSchool($school, fn () => app(TransportStudentAssignmentService::class)->assign($student, $routeOne, null, null, $admin));

        $this->expectException(StudentAlreadyAssignedException::class);
        app(TenantContext::class)->withSchool($school, fn () => app(TransportStudentAssignmentService::class)->assign($student, $routeTwo, null, null, $admin));
    }

    #[Test]
    public function assign_refuses_an_inactive_student(): void
    {
        [$admin, $school] = $this->createSchoolAdmin();
        $route = $this->createTransportRoute($school);
        $student = $this->createStudent($school, ['status' => 'inactive']);

        $this->expectException(StudentNotEligibleException::class);
        app(TenantContext::class)->withSchool($school, fn () => app(TransportStudentAssignmentService::class)->assign($student, $route, null, null, $admin));
    }

    #[Test]
    public function assign_refuses_an_inactive_route(): void
    {
        [$admin, $school] = $this->createSchoolAdmin();
        $route = $this->createTransportRoute($school, ['status' => 'inactive']);
        $student = $this->createStudent($school, ['status' => 'active']);

        $this->expectException(RouteNotAvailableException::class);
        app(TenantContext::class)->withSchool($school, fn () => app(TransportStudentAssignmentService::class)->assign($student, $route, null, null, $admin));
    }

    #[Test]
    public function assign_refuses_a_pickup_stop_that_belongs_to_a_different_route(): void
    {
        [$admin, $school] = $this->createSchoolAdmin();
        $routeA = $this->createTransportRoute($school);
        $routeB = $this->createTransportRoute($school);
        $stopOnRouteB = $this->createTransportStop($routeB);
        $student = $this->createStudent($school, ['status' => 'active']);

        $this->expectException(StopNotOnRouteException::class);
        app(TenantContext::class)->withSchool($school, fn () => app(TransportStudentAssignmentService::class)->assign($student, $routeA, $stopOnRouteB, null, $admin));
    }

    #[Test]
    public function assign_refuses_a_dropoff_stop_that_belongs_to_a_different_route(): void
    {
        [$admin, $school] = $this->createSchoolAdmin();
        $routeA = $this->createTransportRoute($school);
        $routeB = $this->createTransportRoute($school);
        $stopOnRouteB = $this->createTransportStop($routeB);
        $student = $this->createStudent($school, ['status' => 'active']);

        $this->expectException(StopNotOnRouteException::class);
        app(TenantContext::class)->withSchool($school, fn () => app(TransportStudentAssignmentService::class)->assign($student, $routeA, null, $stopOnRouteB, $admin));
    }

    #[Test]
    public function end_marks_the_assignment_ended_and_records_an_audit_event(): void
    {
        [$admin, $school] = $this->createSchoolAdmin();
        $route = $this->createTransportRoute($school);
        $student = $this->createStudent($school, ['status' => 'active']);
        $assignment = app(TenantContext::class)->withSchool($school, fn () => app(TransportStudentAssignmentService::class)->assign($student, $route, null, null, $admin));

        $ended = app(TenantContext::class)->withSchool($school, fn () => app(TransportStudentAssignmentService::class)->end($assignment, $admin));

        $this->assertSame('ended', $ended->status);
        $this->assertNotNull($ended->ends_on);

        $event = app(TenantContext::class)->withSchool($school, fn () => SchoolAuditEvent::query()
            ->where('event_type', 'transport.student_assignment.ended')
            ->where('subject_id', $assignment->id)
            ->first());
        $this->assertNotNull($event);
    }

    #[Test]
    public function end_cannot_be_performed_twice(): void
    {
        [$admin, $school] = $this->createSchoolAdmin();
        $route = $this->createTransportRoute($school);
        $student = $this->createStudent($school, ['status' => 'active']);
        $assignment = app(TenantContext::class)->withSchool($school, fn () => app(TransportStudentAssignmentService::class)->assign($student, $route, null, null, $admin));

        app(TenantContext::class)->withSchool($school, fn () => app(TransportStudentAssignmentService::class)->end($assignment, $admin));

        $this->expectException(StudentAssignmentAlreadyEndedException::class);
        app(TenantContext::class)->withSchool($school, fn () => app(TransportStudentAssignmentService::class)->end($assignment, $admin));
    }

    #[Test]
    public function after_ending_an_assignment_the_student_can_be_assigned_to_a_new_route(): void
    {
        [$admin, $school] = $this->createSchoolAdmin();
        $routeOne = $this->createTransportRoute($school);
        $routeTwo = $this->createTransportRoute($school);
        $student = $this->createStudent($school, ['status' => 'active']);
        $service = app(TransportStudentAssignmentService::class);

        $first = app(TenantContext::class)->withSchool($school, fn () => $service->assign($student, $routeOne, null, null, $admin));
        app(TenantContext::class)->withSchool($school, fn () => $service->end($first, $admin));

        $second = app(TenantContext::class)->withSchool($school, fn () => $service->assign($student, $routeTwo, null, null, $admin));

        $this->assertSame('active', $second->status);
        $this->assertSame($routeTwo->id, $second->route_id);
        $this->assertNotSame($first->id, $second->id, 'A new assignment must create a fresh row, never reuse/mutate the ended one.');
    }

    #[Test]
    public function historical_assignments_remain_valid_after_the_route_is_deactivated(): void
    {
        [$admin, $school] = $this->createSchoolAdmin();
        $route = $this->createTransportRoute($school);
        $student = $this->createStudent($school, ['status' => 'active']);
        $assignment = app(TenantContext::class)->withSchool($school, fn () => app(TransportStudentAssignmentService::class)->assign($student, $route, null, null, $admin));
        app(TenantContext::class)->withSchool($school, fn () => app(TransportStudentAssignmentService::class)->end($assignment, $admin));

        $route->update(['status' => 'inactive']);

        $fresh = app(TenantContext::class)->withSchool($school, fn () => $assignment->fresh());
        $this->assertSame('ended', $fresh->status);
        $this->assertSame($route->id, $fresh->route_id);
    }
}
