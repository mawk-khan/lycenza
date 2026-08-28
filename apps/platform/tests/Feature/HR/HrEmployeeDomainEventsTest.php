<?php

namespace Tests\Feature\HR;

use App\Domain\HR\Application\EmployeeAssignmentService;
use App\Domain\HR\Application\EmployeeLifecycleService;
use App\Domain\HR\Application\EmployeeService;
use App\Domain\HR\Application\EmploymentService;
use App\Domain\HR\Application\ReportingHierarchyService;
use App\Models\DomainEventOutbox;
use App\Support\Tenancy\TenantContext;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 8A closure correction (item 1) -- proves every one of the 9
 * planned-but-missing HR domain events is actually dispatched, at the
 * correct call site, through the existing transactional outbox
 * (App\Support\Events\ShouldBeOutboxed) -- never a second event
 * mechanism. Mirrors
 * Tests\Feature\Finance\LedgerServiceAtomicityTest's established
 * `DomainEventOutbox::query()->where('school_id', ...)->where('event_type', '<type>.v1')`
 * assertion shape.
 */
class HrEmployeeDomainEventsTest extends TestCase
{
    use CreatesTenancyFixtures;

    private function outboxCount(string $schoolId, string $eventType): int
    {
        return DomainEventOutbox::query()->where('school_id', $schoolId)->where('event_type', $eventType)->count();
    }

    #[Test]
    public function employee_updated_is_dispatched(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $actor = $this->fullHrActor($school);

        app(EmployeeService::class)->update($employee, ['full_name' => 'Updated Name'], $actor);

        $this->assertSame(1, $this->outboxCount($school->id, 'employee.updated.v1'));
    }

    #[Test]
    public function employee_archived_is_dispatched(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $actor = $this->fullHrActor($school);

        app(EmployeeService::class)->archive($employee, $actor);

        $this->assertSame(1, $this->outboxCount($school->id, 'employee.archived.v1'));
    }

    #[Test]
    public function employment_started_is_dispatched(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $actor = $this->fullHrActor($school);

        app(EmploymentService::class)->create($employee, ['employment_type' => 'full_time', 'starts_on' => '2026-06-01'], $actor);

        $this->assertSame(1, $this->outboxCount($school->id, 'employment.started.v1'));
    }

    #[Test]
    public function employment_ended_is_dispatched(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $actor = $this->fullHrActor($school);
        $employment = app(EmploymentService::class)->create($employee, ['employment_type' => 'full_time', 'starts_on' => '2026-06-01'], $actor);

        app(EmploymentService::class)->end($employment, '2026-07-01', $actor);

        $this->assertSame(1, $this->outboxCount($school->id, 'employment.ended.v1'));
    }

    #[Test]
    public function employee_rehired_is_dispatched(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $actor = $this->fullHrActor($school);
        $firstEmployment = app(EmploymentService::class)->create($employee, ['employment_type' => 'full_time', 'starts_on' => '2026-01-01'], $actor);
        app(EmploymentService::class)->end($firstEmployment, '2026-03-01', $actor);

        app(EmployeeLifecycleService::class)->rehire($employee, ['employment_type' => 'full_time', 'starts_on' => '2026-06-01'], $actor);

        $this->assertSame(1, $this->outboxCount($school->id, 'employee.rehired.v1'));
    }

    #[Test]
    public function assignment_started_is_dispatched(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $actor = $this->fullHrActor($school);
        $employment = app(EmploymentService::class)->create($employee, ['employment_type' => 'full_time', 'starts_on' => '2026-06-01'], $actor);
        $position = $this->createPosition($school);

        app(EmployeeAssignmentService::class)->create($employment, ['starts_on' => '2026-06-01'], $position, $actor);

        $this->assertSame(1, $this->outboxCount($school->id, 'assignment.started.v1'));
    }

    #[Test]
    public function assignment_ended_is_dispatched(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $actor = $this->fullHrActor($school);
        $employment = app(EmploymentService::class)->create($employee, ['employment_type' => 'full_time', 'starts_on' => '2026-06-01'], $actor);
        $position = $this->createPosition($school);
        $assignment = app(EmployeeAssignmentService::class)->create($employment, ['starts_on' => '2026-06-01'], $position, $actor);

        app(EmployeeAssignmentService::class)->end($assignment, '2026-07-01', $actor);

        $this->assertSame(1, $this->outboxCount($school->id, 'assignment.ended.v1'));
    }

    #[Test]
    public function assignment_primary_changed_is_dispatched(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $actor = $this->fullHrActor($school);
        $employment = app(EmploymentService::class)->create($employee, ['employment_type' => 'full_time', 'starts_on' => '2026-06-01'], $actor);
        $position = $this->createPosition($school);
        $assignment = app(EmployeeAssignmentService::class)->create($employment, ['starts_on' => '2026-06-01'], $position, $actor);

        app(EmployeeAssignmentService::class)->setPrimary($assignment, $actor);

        $this->assertSame(1, $this->outboxCount($school->id, 'assignment.primary_changed.v1'));
    }

    #[Test]
    public function assignment_manager_changed_is_dispatched(): void
    {
        $school = $this->createSchool();
        $employeeA = $this->createEmployee($school);
        $employeeB = $this->createEmployee($school);
        $actor = $this->fullHrActor($school);
        $position = $this->createPosition($school);

        $employmentA = app(EmploymentService::class)->create($employeeA, ['employment_type' => 'full_time', 'starts_on' => '2026-06-01'], $actor);
        $subordinate = app(EmployeeAssignmentService::class)->create($employmentA, ['starts_on' => '2026-06-01'], $position, $actor);

        $employmentB = app(EmploymentService::class)->create($employeeB, ['employment_type' => 'full_time', 'starts_on' => '2026-06-01'], $actor);
        $manager = app(EmployeeAssignmentService::class)->create($employmentB, ['starts_on' => '2026-06-01'], $position, $actor);

        app(ReportingHierarchyService::class)->setManager($subordinate, $manager, $actor);

        $this->assertSame(1, $this->outboxCount($school->id, 'assignment.manager_changed.v1'));
    }

    #[Test]
    public function employee_restore_dispatches_no_event(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $actor = $this->fullHrActor($school);
        app(EmployeeService::class)->archive($employee, $actor);

        app(TenantContext::class)->set($school);
        $before = DomainEventOutbox::query()->where('school_id', $school->id)->count();

        app(EmployeeService::class)->restore($employee, $actor);

        app(TenantContext::class)->set($school);
        $after = DomainEventOutbox::query()->where('school_id', $school->id)->count();

        $this->assertSame($before, $after, 'restore() is a deliberate architectural choice to dispatch no event -- it is not one of the 10 originally-planned events.');
    }
}
