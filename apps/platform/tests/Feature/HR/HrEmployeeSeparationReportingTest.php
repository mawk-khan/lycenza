<?php

namespace Tests\Feature\HR;

use App\Domain\HR\Application\EmployeeAssignmentService;
use App\Domain\HR\Application\EmployeeLifecycleService;
use App\Domain\HR\Application\EmploymentService;
use App\Domain\HR\Application\ReportingHierarchyService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 8A.13 -- REQUIRED Assignment-closure and reporting-hierarchy
 * cleanup proof for separation (checkpoint brief sections 16/17/62).
 * `EmployeeLifecycleService::separate()` reuses `EmploymentService::end()`
 * unchanged for this behavior -- these tests exercise it through the
 * lifecycle command layer specifically, proving the reused cascade
 * behaves identically when reached via the new command.
 */
class HrEmployeeSeparationReportingTest extends TestCase
{
    use CreatesTenancyFixtures;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    #[Test]
    public function separation_closes_both_primary_and_secondary_open_assignments(): void
    {
        Carbon::setTestNow('2026-06-15');
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $positionA = $this->createPosition($school, ['code' => 'PRI']);
        $positionB = $this->createPosition($school, ['code' => 'SEC']);
        $actor = $this->fullHrActor($school);
        $employment = app(EmploymentService::class)->create($employee, ['employment_type' => 'permanent', 'starts_on' => '2022-01-01'], $actor);
        $primary = app(EmployeeAssignmentService::class)->create($employment, ['starts_on' => '2022-01-01'], $positionA, $actor);
        app(EmployeeAssignmentService::class)->setPrimary($primary, $actor);
        $secondary = app(EmployeeAssignmentService::class)->create($employment, ['starts_on' => '2022-01-01'], $positionB, $actor);

        app(EmployeeLifecycleService::class)->separate($employment, '2026-06-15', $actor);

        app(TenantContext::class)->set($school);
        $this->assertSame('2026-06-15', $primary->fresh()->ends_on->toDateString());
        $this->assertSame('2026-06-15', $secondary->fresh()->ends_on->toDateString());
    }

    #[Test]
    public function separating_a_manager_clears_subordinates_manager_pointer_but_leaves_the_subordinate_assignment_open(): void
    {
        Carbon::setTestNow('2026-06-15');
        $school = $this->createSchool();
        $manager = $this->createEmployee($school);
        $subordinate = $this->createEmployee($school);
        $position = $this->createPosition($school);
        $actor = $this->fullHrActor($school);

        $managerEmployment = app(EmploymentService::class)->create($manager, ['employment_type' => 'permanent', 'starts_on' => '2022-01-01'], $actor);
        $managerAssignment = app(EmployeeAssignmentService::class)->create($managerEmployment, ['starts_on' => '2022-01-01'], $position, $actor);

        $subordinateEmployment = app(EmploymentService::class)->create($subordinate, ['employment_type' => 'permanent', 'starts_on' => '2022-01-01'], $actor);
        $subordinateAssignment = app(EmployeeAssignmentService::class)->create($subordinateEmployment, ['starts_on' => '2022-01-01'], $position, $actor);
        app(ReportingHierarchyService::class)->setManager($subordinateAssignment, $managerAssignment, $actor);

        app(EmployeeLifecycleService::class)->separate($managerEmployment, '2026-06-15', $actor);

        app(TenantContext::class)->set($school);
        $freshSubordinate = $subordinateAssignment->fresh();
        $this->assertNull($freshSubordinate->ends_on, 'The subordinate Assignment must remain open -- separating the manager never closes it.');
        $this->assertNull($freshSubordinate->manager_assignment_id, 'The now-dangling manager pointer must be cleared.');
        $this->assertSame('2026-06-15', $managerAssignment->fresh()->ends_on->toDateString());
    }

    #[Test]
    public function separating_a_subordinate_never_affects_the_managers_own_assignment(): void
    {
        Carbon::setTestNow('2026-06-15');
        $school = $this->createSchool();
        $manager = $this->createEmployee($school);
        $subordinate = $this->createEmployee($school);
        $position = $this->createPosition($school);
        $actor = $this->fullHrActor($school);

        $managerEmployment = app(EmploymentService::class)->create($manager, ['employment_type' => 'permanent', 'starts_on' => '2022-01-01'], $actor);
        $managerAssignment = app(EmployeeAssignmentService::class)->create($managerEmployment, ['starts_on' => '2022-01-01'], $position, $actor);

        $subordinateEmployment = app(EmploymentService::class)->create($subordinate, ['employment_type' => 'permanent', 'starts_on' => '2022-01-01'], $actor);
        $subordinateAssignment = app(EmployeeAssignmentService::class)->create($subordinateEmployment, ['starts_on' => '2022-01-01'], $position, $actor);
        app(ReportingHierarchyService::class)->setManager($subordinateAssignment, $managerAssignment, $actor);

        app(EmployeeLifecycleService::class)->separate($subordinateEmployment, '2026-06-15', $actor);

        app(TenantContext::class)->set($school);
        $freshManager = $managerAssignment->fresh();
        $this->assertNull($freshManager->ends_on, 'The manager Assignment must be completely unaffected by the subordinates separation.');
        $this->assertSame('2026-06-15', $subordinateAssignment->fresh()->ends_on->toDateString());
    }

    #[Test]
    public function an_ended_assignments_own_historical_manager_pointer_is_left_untouched(): void
    {
        Carbon::setTestNow('2026-06-15');
        $school = $this->createSchool();
        $manager = $this->createEmployee($school);
        $subordinate = $this->createEmployee($school);
        $position = $this->createPosition($school);
        $actor = $this->fullHrActor($school);

        $managerEmployment = app(EmploymentService::class)->create($manager, ['employment_type' => 'permanent', 'starts_on' => '2022-01-01'], $actor);
        $managerAssignment = app(EmployeeAssignmentService::class)->create($managerEmployment, ['starts_on' => '2022-01-01'], $position, $actor);

        $subordinateEmployment = app(EmploymentService::class)->create($subordinate, ['employment_type' => 'permanent', 'starts_on' => '2022-01-01'], $actor);
        $subordinateAssignment = app(EmployeeAssignmentService::class)->create($subordinateEmployment, ['starts_on' => '2022-01-01'], $position, $actor);
        app(ReportingHierarchyService::class)->setManager($subordinateAssignment, $managerAssignment, $actor);

        app(EmployeeLifecycleService::class)->separate($subordinateEmployment, '2026-06-15', $actor);

        app(TenantContext::class)->set($school);
        $this->assertSame($managerAssignment->id, $subordinateAssignment->fresh()->manager_assignment_id, 'Who the now-ended Assignment used to report to remains valid history.');
    }
}
