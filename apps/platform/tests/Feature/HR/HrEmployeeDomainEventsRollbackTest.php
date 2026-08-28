<?php

namespace Tests\Feature\HR;

use App\Domain\HR\Application\EmployeeAssignmentService;
use App\Domain\HR\Application\EmployeeLifecycleService;
use App\Domain\HR\Application\EmployeeService;
use App\Domain\HR\Application\EmploymentService;
use App\Domain\HR\Application\ReportingHierarchyService;
use App\Models\DomainEventOutbox;
use App\Models\School;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 8A closure correction (item 2, closure reconciliation) --
 * negative proof that all 10 HR domain events genuinely participate in
 * the SAME atomic transaction as their triggering mutation, mirroring
 * the repository's established pattern for this exact kind of proof:
 * Tests\Feature\AcademicStructure\AcademicYearLifecycleTest::
 * a_rolled_back_activation_leaves_neither_the_state_change_nor_the_event.
 *
 * This does NOT re-prove transactional-outbox atomicity itself -- that
 * guarantee is structural, not per-event. App\Listeners\
 * RecordDomainEventToOutbox is registered (in AppServiceProvider::boot())
 * against the ShouldBeOutboxed INTERFACE and runs SYNCHRONOUSLY, so its
 * outbox INSERT always shares whatever DB connection/transaction the
 * triggering event(new ...) call executes inside -- see that listener's
 * own docblock and docs/architecture/adr/0025-transactional-outbox.md.
 * What genuinely needs proving PER EVENT, and is not implied by any
 * other existing test, is that each of the 10 HR dispatch call sites
 * actually sits INSIDE the same DB::transaction() as its mutation, not
 * after it -- a bug that would let an outbox row survive a rollback
 * the mutation itself did not. One parameterized test (data provider
 * over all 10 event types, each backed by a small arrange/act method
 * pair reusing the exact fixture shapes already established in
 * HrEmployeeDomainEventsTest) covers this instead of duplicating the
 * AcademicYear pattern's boilerplate ten times.
 *
 * Each scenario's ARRANGE step (committed, outside the transaction
 * under test) builds only the prerequisite state the ACT step's call
 * needs -- exactly mirroring AcademicYearLifecycleTest's own split
 * between "create the year" (committed) and "activate() + throw"
 * (rolled back). The ACT step is what actually dispatches the event
 * under test, wrapped by this test's own outer DB::transaction() plus
 * a forced RuntimeException, matching the AcademicYear test's shape
 * exactly.
 */
class HrEmployeeDomainEventsRollbackTest extends TestCase
{
    use CreatesTenancyFixtures;

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function eventScenarios(): array
    {
        return [
            'employee.created.v1' => ['employee.created.v1', 'arrangeEmployeeCreated', 'actEmployeeCreated'],
            'employee.updated.v1' => ['employee.updated.v1', 'arrangeEmployeeUpdated', 'actEmployeeUpdated'],
            'employee.archived.v1' => ['employee.archived.v1', 'arrangeEmployeeArchived', 'actEmployeeArchived'],
            'employment.started.v1' => ['employment.started.v1', 'arrangeEmploymentStarted', 'actEmploymentStarted'],
            'employment.ended.v1' => ['employment.ended.v1', 'arrangeEmploymentEnded', 'actEmploymentEnded'],
            'employee.rehired.v1' => ['employee.rehired.v1', 'arrangeEmployeeRehired', 'actEmployeeRehired'],
            'assignment.started.v1' => ['assignment.started.v1', 'arrangeAssignmentStarted', 'actAssignmentStarted'],
            'assignment.ended.v1' => ['assignment.ended.v1', 'arrangeAssignmentEnded', 'actAssignmentEnded'],
            'assignment.primary_changed.v1' => ['assignment.primary_changed.v1', 'arrangeAssignmentPrimaryChanged', 'actAssignmentPrimaryChanged'],
            'assignment.manager_changed.v1' => ['assignment.manager_changed.v1', 'arrangeAssignmentManagerChanged', 'actAssignmentManagerChanged'],
        ];
    }

    #[Test]
    #[DataProvider('eventScenarios')]
    public function a_rolled_back_mutation_leaves_no_outboxed_event(string $eventType, string $arrangeMethod, string $actMethod): void
    {
        $school = $this->createSchool();
        $actor = $this->fullHrActor($school);

        $ctx = $this->$arrangeMethod($school, $actor);

        try {
            DB::transaction(function () use ($ctx, $actor, $actMethod): void {
                $this->$actMethod($ctx, $actor);
                throw new RuntimeException('Simulated failure after event dispatch.');
            });
            $this->fail('Expected the RuntimeException to propagate.');
        } catch (RuntimeException) {
            // expected
        }

        $count = app(TenantContext::class)->withSchool(
            $school,
            fn () => DomainEventOutbox::query()->where('school_id', $school->id)->where('event_type', $eventType)->count(),
        );

        $this->assertSame(
            0,
            $count,
            "{$eventType} must not survive a rolled-back transaction -- its dispatch site is not inside the mutation's own DB::transaction().",
        );
    }

    // --- Arrange: committed prerequisite state, mirroring
    // HrEmployeeDomainEventsTest's own arrange steps for each event.
    // Never triggers the event type under test -- only the ACT step
    // below does, inside the transaction that gets rolled back. -------

    private function arrangeEmployeeCreated(School $school, User $actor): array
    {
        return ['school' => $school];
    }

    private function arrangeEmployeeUpdated(School $school, User $actor): array
    {
        return ['employee' => $this->createEmployee($school)];
    }

    private function arrangeEmployeeArchived(School $school, User $actor): array
    {
        return ['employee' => $this->createEmployee($school)];
    }

    private function arrangeEmploymentStarted(School $school, User $actor): array
    {
        return ['employee' => $this->createEmployee($school)];
    }

    private function arrangeEmploymentEnded(School $school, User $actor): array
    {
        $employee = $this->createEmployee($school);
        $employment = app(EmploymentService::class)->create($employee, ['employment_type' => 'full_time', 'starts_on' => '2026-06-01'], $actor);

        return ['employment' => $employment];
    }

    private function arrangeEmployeeRehired(School $school, User $actor): array
    {
        $employee = $this->createEmployee($school);
        $firstEmployment = app(EmploymentService::class)->create($employee, ['employment_type' => 'full_time', 'starts_on' => '2026-01-01'], $actor);
        app(EmploymentService::class)->end($firstEmployment, '2026-03-01', $actor);

        return ['employee' => $employee];
    }

    private function arrangeAssignmentStarted(School $school, User $actor): array
    {
        $employee = $this->createEmployee($school);
        $employment = app(EmploymentService::class)->create($employee, ['employment_type' => 'full_time', 'starts_on' => '2026-06-01'], $actor);
        $position = $this->createPosition($school);

        return ['employment' => $employment, 'position' => $position];
    }

    private function arrangeAssignmentEnded(School $school, User $actor): array
    {
        $employee = $this->createEmployee($school);
        $employment = app(EmploymentService::class)->create($employee, ['employment_type' => 'full_time', 'starts_on' => '2026-06-01'], $actor);
        $position = $this->createPosition($school);
        $assignment = app(EmployeeAssignmentService::class)->create($employment, ['starts_on' => '2026-06-01'], $position, $actor);

        return ['assignment' => $assignment];
    }

    private function arrangeAssignmentPrimaryChanged(School $school, User $actor): array
    {
        $employee = $this->createEmployee($school);
        $employment = app(EmploymentService::class)->create($employee, ['employment_type' => 'full_time', 'starts_on' => '2026-06-01'], $actor);
        $position = $this->createPosition($school);
        $assignment = app(EmployeeAssignmentService::class)->create($employment, ['starts_on' => '2026-06-01'], $position, $actor);

        return ['assignment' => $assignment];
    }

    private function arrangeAssignmentManagerChanged(School $school, User $actor): array
    {
        $employeeA = $this->createEmployee($school);
        $employeeB = $this->createEmployee($school);
        $position = $this->createPosition($school);

        $employmentA = app(EmploymentService::class)->create($employeeA, ['employment_type' => 'full_time', 'starts_on' => '2026-06-01'], $actor);
        $subordinate = app(EmployeeAssignmentService::class)->create($employmentA, ['starts_on' => '2026-06-01'], $position, $actor);

        $employmentB = app(EmploymentService::class)->create($employeeB, ['employment_type' => 'full_time', 'starts_on' => '2026-06-01'], $actor);
        $manager = app(EmployeeAssignmentService::class)->create($employmentB, ['starts_on' => '2026-06-01'], $position, $actor);

        return ['subordinate' => $subordinate, 'manager' => $manager];
    }

    // --- Act: the single call under test, wrapped by the test
    // method's own outer DB::transaction() + forced failure. ----------

    private function actEmployeeCreated(array $ctx, User $actor): void
    {
        app(EmployeeService::class)->create($ctx['school'], ['full_name' => 'Rollback Test'], $actor);
    }

    private function actEmployeeUpdated(array $ctx, User $actor): void
    {
        app(EmployeeService::class)->update($ctx['employee'], ['full_name' => 'Updated Name'], $actor);
    }

    private function actEmployeeArchived(array $ctx, User $actor): void
    {
        app(EmployeeService::class)->archive($ctx['employee'], $actor);
    }

    private function actEmploymentStarted(array $ctx, User $actor): void
    {
        app(EmploymentService::class)->create($ctx['employee'], ['employment_type' => 'full_time', 'starts_on' => '2026-06-01'], $actor);
    }

    private function actEmploymentEnded(array $ctx, User $actor): void
    {
        app(EmploymentService::class)->end($ctx['employment'], '2026-07-01', $actor);
    }

    private function actEmployeeRehired(array $ctx, User $actor): void
    {
        app(EmployeeLifecycleService::class)->rehire($ctx['employee'], ['employment_type' => 'full_time', 'starts_on' => '2026-06-01'], $actor);
    }

    private function actAssignmentStarted(array $ctx, User $actor): void
    {
        app(EmployeeAssignmentService::class)->create($ctx['employment'], ['starts_on' => '2026-06-01'], $ctx['position'], $actor);
    }

    private function actAssignmentEnded(array $ctx, User $actor): void
    {
        app(EmployeeAssignmentService::class)->end($ctx['assignment'], '2026-07-01', $actor);
    }

    private function actAssignmentPrimaryChanged(array $ctx, User $actor): void
    {
        app(EmployeeAssignmentService::class)->setPrimary($ctx['assignment'], $actor);
    }

    private function actAssignmentManagerChanged(array $ctx, User $actor): void
    {
        app(ReportingHierarchyService::class)->setManager($ctx['subordinate'], $ctx['manager'], $actor);
    }
}
