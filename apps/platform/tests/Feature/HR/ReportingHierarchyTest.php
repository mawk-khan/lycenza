<?php

namespace Tests\Feature\HR;

use App\Domain\HR\Application\EmployeeAssignmentService;
use App\Domain\HR\Application\EmploymentService;
use App\Domain\HR\Application\Exceptions\AssignmentManagerMismatchException;
use App\Domain\HR\Application\Exceptions\ReportingHierarchyCycleException;
use App\Domain\HR\Application\Exceptions\SameEmployeeReportingException;
use App\Domain\HR\Application\Exceptions\SelfReportingException;
use App\Domain\HR\Application\ReportingHierarchyService;
use App\Domain\HR\Infrastructure\EmployeeAssignment;
use App\Domain\HR\Infrastructure\Position;
use App\Models\MembershipRoleAssignment;
use App\Models\PlatformRoleAssignment;
use App\Models\Role;
use App\Models\School;
use App\Models\SchoolAuditEvent;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 8A.5: proves the Reporting Hierarchy -- `employee_assignments.
 * manager_assignment_id` (docs/modules/HR.md "Reporting hierarchy
 * strategy": a direct, nullable, self-referencing, same-School
 * composite FK, NOT a separate table -- see the migration's own
 * docblock for the full reconciliation of this checkpoint's brief
 * against the committed architecture). Covers relationship resolution,
 * same-School enforcement, self-report/cycle prevention (including a
 * real two-process concurrency proof), manager-change semantics,
 * Assignment/Employment-ending interaction, and the authorization
 * boundary. See tests/Feature/Postgres/HrRawIsolationTest for the
 * independent raw-SQL/RLS and composite-FK proof.
 */
class ReportingHierarchyTest extends TestCase
{
    use CreatesTenancyFixtures;

    // --- Schema / relationship resolution --------------------------------

    #[Test]
    public function manager_and_direct_reports_relationships_resolve_correctly(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $employment = $this->createEmploymentRecord($employee);
        $position = $this->createPosition($school);
        $manager = $this->createEmployeeAssignment($employment, $position);
        $subordinate = $this->createEmployeeAssignment($employment, $position, ['manager_assignment_id' => $manager->id]);

        app(TenantContext::class)->set($school);

        $this->assertSame($manager->id, $subordinate->fresh()->manager->id);
        $this->assertTrue($manager->directReports()->get()->contains('id', $subordinate->id));
    }

    #[Test]
    public function a_new_assignment_has_no_manager_by_default(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $employment = $this->createEmploymentRecord($employee);
        $position = $this->createPosition($school);
        $assignment = $this->createEmployeeAssignment($employment, $position);

        $this->assertNull($assignment->manager_assignment_id);
    }

    // --- Same-School enforcement ------------------------------------------

    #[Test]
    public function setting_a_same_school_manager_works(): void
    {
        $school = $this->createSchool();
        $employeeA = $this->createEmployee($school);
        $employeeB = $this->createEmployee($school);
        $employmentA = $this->createEmploymentRecord($employeeA);
        $employmentB = $this->createEmploymentRecord($employeeB);
        $position = $this->createPosition($school);
        $subordinate = $this->createEmployeeAssignment($employmentA, $position);
        $manager = $this->createEmployeeAssignment($employmentB, $position);

        $updated = app(ReportingHierarchyService::class)->setManager($subordinate, $manager, $this->fullHrActor($school));

        $this->assertSame($manager->id, $updated->manager_assignment_id);
    }

    #[Test]
    public function setting_a_cross_school_manager_is_rejected_by_the_service(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $employeeA = $this->createEmployee($schoolA);
        $employmentA = $this->createEmploymentRecord($employeeA);
        $positionA = $this->createPosition($schoolA);
        $subordinateA = $this->createEmployeeAssignment($employmentA, $positionA);

        $employeeB = $this->createEmployee($schoolB);
        $employmentB = $this->createEmploymentRecord($employeeB);
        $positionB = $this->createPosition($schoolB);
        $managerB = $this->createEmployeeAssignment($employmentB, $positionB);

        $this->expectException(AssignmentManagerMismatchException::class);

        app(ReportingHierarchyService::class)->setManager($subordinateA, $managerB, $this->fullHrActor($schoolA));
    }

    // --- Self-report / same-Employee / cycle prevention --------------------

    #[Test]
    public function self_reporting_is_rejected_by_the_database_check_constraint(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $employment = $this->createEmploymentRecord($employee);
        $position = $this->createPosition($school);
        $assignment = $this->createEmployeeAssignment($employment, $position);

        app(TenantContext::class)->set($school);

        $this->expectException(QueryException::class);

        DB::transaction(function () use ($assignment): void {
            EmployeeAssignment::query()->where('id', $assignment->id)->update(['manager_assignment_id' => $assignment->id]);
        });
    }

    #[Test]
    public function self_reporting_is_rejected_by_the_service(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $employment = $this->createEmploymentRecord($employee);
        $position = $this->createPosition($school);
        $assignment = $this->createEmployeeAssignment($employment, $position);

        $this->expectException(SelfReportingException::class);

        app(ReportingHierarchyService::class)->setManager($assignment, $assignment, $this->fullHrActor($school));
    }

    #[Test]
    public function reporting_to_another_assignment_of_the_same_employee_is_rejected(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $employment = $this->createEmploymentRecord($employee);
        $teacherPosition = $this->createPosition($school, ['code' => 'TCH']);
        $coordinatorPosition = $this->createPosition($school, ['code' => 'COORD']);
        $teacherAssignment = $this->createEmployeeAssignment($employment, $teacherPosition);
        $coordinatorAssignment = $this->createEmployeeAssignment($employment, $coordinatorPosition);

        $this->expectException(SameEmployeeReportingException::class);

        app(ReportingHierarchyService::class)->setManager($teacherAssignment, $coordinatorAssignment, $this->fullHrActor($school));
    }

    #[Test]
    public function a_direct_chain_of_reporting_lines_is_valid(): void
    {
        $school = $this->createSchool();
        $position = $this->createPosition($school);
        [$a, $b, $c] = $this->createIndependentAssignments($school, $position, 3);
        $service = app(ReportingHierarchyService::class);
        $actor = $this->fullHrActor($school);

        $service->setManager($a, $b, $actor);
        $service->setManager($b, $c, $actor);

        app(TenantContext::class)->set($school);
        $this->assertSame($b->id, $a->fresh()->manager_assignment_id);
        $this->assertSame($c->id, $b->fresh()->manager_assignment_id);
    }

    #[Test]
    public function an_indirect_three_node_cycle_is_rejected(): void
    {
        $school = $this->createSchool();
        $position = $this->createPosition($school);
        [$a, $b, $c] = $this->createIndependentAssignments($school, $position, 3);
        $service = app(ReportingHierarchyService::class);
        $actor = $this->fullHrActor($school);

        $service->setManager($a, $b, $actor);
        $service->setManager($b, $c, $actor);

        $this->expectException(ReportingHierarchyCycleException::class);

        // A -> B -> C already; C -> A would close the loop.
        $service->setManager($c, $a, $actor);
    }

    #[Test]
    public function a_deeper_indirect_cycle_is_rejected(): void
    {
        $school = $this->createSchool();
        $position = $this->createPosition($school);
        [$a, $b, $c, $d] = $this->createIndependentAssignments($school, $position, 4);
        $service = app(ReportingHierarchyService::class);
        $actor = $this->fullHrActor($school);

        $service->setManager($a, $b, $actor);
        $service->setManager($b, $c, $actor);
        $service->setManager($c, $d, $actor);

        $this->expectException(ReportingHierarchyCycleException::class);

        // A -> B -> C -> D already; D -> A would close the loop.
        $service->setManager($d, $a, $actor);
    }

    // --- Manager change ------------------------------------------------------

    #[Test]
    public function changing_manager_updates_the_pointer_and_is_audited(): void
    {
        $school = $this->createSchool();
        $position = $this->createPosition($school);
        [$subordinate, $originalManager, $newManager] = $this->createIndependentAssignments($school, $position, 3);
        $service = app(ReportingHierarchyService::class);
        $actor = $this->fullHrActor($school);

        $service->setManager($subordinate, $originalManager, $actor);
        $service->setManager($subordinate, $newManager, $actor);

        app(TenantContext::class)->set($school);
        $this->assertSame($newManager->id, $subordinate->fresh()->manager_assignment_id);

        // orderByDesc('id'), not 'occurred_at' -- two audit events created
        // in rapid succession within one test can land on the identical
        // timestamp at this column's precision; UUIDv7 ids remain
        // reliably creation-ordered even then.
        $event = SchoolAuditEvent::query()
            ->where('school_id', $school->id)
            ->where('event_type', 'hr.assignment.manager_changed')
            ->orderByDesc('id')
            ->first();

        $this->assertNotNull($event, 'A manager change must be audited so the prior relationship remains discoverable via the audit trail.');
        $this->assertSame($originalManager->id, $event->metadata['previousManagerAssignmentId']);
        $this->assertSame($newManager->id, $event->metadata['newManagerAssignmentId']);
    }

    #[Test]
    public function clearing_a_manager_sets_it_to_null(): void
    {
        $school = $this->createSchool();
        $position = $this->createPosition($school);
        [$subordinate, $manager] = $this->createIndependentAssignments($school, $position, 2);
        $service = app(ReportingHierarchyService::class);
        $actor = $this->fullHrActor($school);

        $service->setManager($subordinate, $manager, $actor);
        $cleared = $service->setManager($subordinate, null, $actor);

        $this->assertNull($cleared->manager_assignment_id);
    }

    // --- Assignment / Employment ending interaction ---------------------------

    #[Test]
    public function ending_a_manager_assignment_clears_the_dangling_pointer_on_its_open_subordinates(): void
    {
        $school = $this->createSchool();
        $position = $this->createPosition($school);
        [$subordinate, $manager] = $this->createIndependentAssignments($school, $position, 2);
        $actor = $this->fullHrActor($school);
        app(ReportingHierarchyService::class)->setManager($subordinate, $manager, $actor);

        app(EmployeeAssignmentService::class)->end($manager, '2026-12-31', $actor);

        app(TenantContext::class)->set($school);
        $this->assertNull($subordinate->fresh()->manager_assignment_id);
    }

    #[Test]
    public function ending_an_assignment_preserves_its_own_historical_manager_reference(): void
    {
        $school = $this->createSchool();
        $position = $this->createPosition($school);
        [$subordinate, $manager] = $this->createIndependentAssignments($school, $position, 2);
        $actor = $this->fullHrActor($school);
        app(ReportingHierarchyService::class)->setManager($subordinate, $manager, $actor);

        app(EmployeeAssignmentService::class)->end($subordinate, '2026-12-31', $actor);

        app(TenantContext::class)->set($school);
        $this->assertSame($manager->id, $subordinate->fresh()->manager_assignment_id, 'The ended Assignment\'s own record of who it reported to while open must remain intact.');
    }

    #[Test]
    public function ending_employment_cascades_through_closed_assignments_to_clear_dangling_manager_pointers(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $subordinateEmployee = $this->createEmployee($school);
        $position = $this->createPosition($school);
        $actor = $this->fullHrActor($school);
        $employment = app(EmploymentService::class)->create($employee, ['employment_type' => 'permanent', 'starts_on' => '2022-01-01'], $actor);
        $managerAssignment = app(EmployeeAssignmentService::class)->create($employment, ['starts_on' => '2022-01-01'], $position, $actor);

        $subordinateEmployment = app(EmploymentService::class)->create($subordinateEmployee, ['employment_type' => 'permanent', 'starts_on' => '2022-01-01'], $actor);
        $subordinateAssignment = app(EmployeeAssignmentService::class)->create($subordinateEmployment, ['starts_on' => '2022-01-01'], $position, $actor);
        app(ReportingHierarchyService::class)->setManager($subordinateAssignment, $managerAssignment, $actor);

        app(EmploymentService::class)->end($employment, '2026-03-31', $actor, 'separated');

        app(TenantContext::class)->set($school);
        $this->assertSame('2026-03-31', $managerAssignment->fresh()->ends_on->toDateString());
        $this->assertNull($subordinateAssignment->fresh()->manager_assignment_id, 'Ending the manager\'s Employment must cascade through its closed Assignment to clear the now-dangling manager pointer on the still-open subordinate.');
    }

    // --- Authorization separation ---------------------------------------------

    #[Test]
    public function setting_a_manager_never_touches_an_authorization_table(): void
    {
        $school = $this->createSchool();
        $position = $this->createPosition($school);
        [$subordinate, $manager] = $this->createIndependentAssignments($school, $position, 2);
        // Created BEFORE the baseline counts -- see PositionTest's
        // identical pattern/rationale.
        $actor = $this->fullHrActor($school);

        app(TenantContext::class)->set($school);
        $rolesBefore = Role::query()->count();
        $membershipRoleAssignmentsBefore = MembershipRoleAssignment::query()->count();
        $platformRoleAssignmentsBefore = PlatformRoleAssignment::query()->count();

        app(ReportingHierarchyService::class)->setManager($subordinate, $manager, $actor);

        app(TenantContext::class)->set($school);
        $this->assertSame($rolesBefore, Role::query()->count());
        $this->assertSame($membershipRoleAssignmentsBefore, MembershipRoleAssignment::query()->count());
        $this->assertSame($platformRoleAssignmentsBefore, PlatformRoleAssignment::query()->count());
    }

    /**
     * @return array<int, EmployeeAssignment>
     */
    private function createIndependentAssignments(School $school, Position $position, int $count): array
    {
        $assignments = [];

        for ($i = 0; $i < $count; $i++) {
            $employee = $this->createEmployee($school);
            $employment = $this->createEmploymentRecord($employee);
            $assignments[] = $this->createEmployeeAssignment($employment, $position);
        }

        return $assignments;
    }
}
