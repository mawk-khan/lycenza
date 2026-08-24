<?php

namespace Tests\Feature\HR;

use App\Domain\HR\Application\EmployeeAssignmentService;
use App\Domain\HR\Application\EmployeeLifecycleService;
use App\Domain\HR\Application\EmploymentService;
use App\Domain\HR\Application\Exceptions\AssignmentPositionMismatchException;
use App\Domain\HR\Application\Exceptions\EmploymentOverlapException;
use App\Domain\HR\Application\Exceptions\RehireRequiresEmploymentHistoryException;
use App\Domain\HR\Infrastructure\Employee;
use App\Domain\HR\Infrastructure\EmploymentRecord;
use App\Support\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 8A.13 -- REQUIRED rehire proof for
 * App\Domain\HR\Application\EmployeeLifecycleService::rehire()
 * (checkpoint brief sections 23-33/49-50/63/64): same Employee identity,
 * same employee_number, a genuinely new EmploymentRecord, mandatory
 * prior-history precondition, reused overlap protection, no automatic
 * reactivation of a prior Assignment/manager, and complete independence
 * from Employee.record_status.
 */
class HrEmployeeRehireTest extends TestCase
{
    use CreatesTenancyFixtures;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function separatedEmployeeWithHistory(): array
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $actor = $this->fullHrActor($school);
        $firstEmployment = app(EmploymentService::class)->create($employee, ['employment_type' => 'permanent', 'starts_on' => '2020-01-01'], $actor);
        app(EmploymentService::class)->end($firstEmployment, '2022-12-31', $actor, 'separated');

        return [$school, $employee, $actor, $firstEmployment];
    }

    #[Test]
    public function rehire_reuses_the_same_employee_and_employee_number(): void
    {
        Carbon::setTestNow('2026-06-15');
        [$school, $employee, $actor] = $this->separatedEmployeeWithHistory();
        $originalNumber = $employee->employee_number;

        $second = app(EmployeeLifecycleService::class)->rehire($employee, ['employment_type' => 'permanent', 'starts_on' => '2026-06-01'], $actor);

        app(TenantContext::class)->set($school);
        $this->assertSame($employee->id, $second->employee_id);
        $this->assertSame($originalNumber, $employee->fresh()->employee_number);
        $this->assertSame(1, Employee::query()->where('school_id', $school->id)->count(), 'Rehire must never create a second Employee.');
    }

    #[Test]
    public function rehire_creates_a_new_employment_record_leaving_the_first_untouched(): void
    {
        Carbon::setTestNow('2026-06-15');
        [$school, $employee, $actor, $firstEmployment] = $this->separatedEmployeeWithHistory();

        $second = app(EmployeeLifecycleService::class)->rehire($employee, ['employment_type' => 'permanent', 'starts_on' => '2026-06-01'], $actor);

        app(TenantContext::class)->set($school);
        $this->assertNotSame($firstEmployment->id, $second->id);
        $this->assertSame('2022-12-31', $firstEmployment->fresh()->ends_on->toDateString());
        $this->assertSame('separated', $firstEmployment->fresh()->status);
        $this->assertSame('active', $second->fresh()->status);
        $this->assertTrue($second->fresh()->isOpen());
        $this->assertSame(2, EmploymentRecord::query()->where('employee_id', $employee->id)->count());
    }

    #[Test]
    public function rehire_without_any_prior_employment_history_is_rejected(): void
    {
        Carbon::setTestNow('2026-06-15');
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $actor = $this->fullHrActor($school);

        $this->expectException(RehireRequiresEmploymentHistoryException::class);

        app(EmployeeLifecycleService::class)->rehire($employee, ['employment_type' => 'permanent', 'starts_on' => '2026-06-01'], $actor);
    }

    #[Test]
    public function rehire_while_a_current_employment_is_still_open_is_rejected_as_overlap(): void
    {
        Carbon::setTestNow('2026-06-15');
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $actor = $this->fullHrActor($school);
        app(EmploymentService::class)->create($employee, ['employment_type' => 'permanent', 'starts_on' => '2020-01-01'], $actor);

        $this->expectException(EmploymentOverlapException::class);

        app(EmployeeLifecycleService::class)->rehire($employee, ['employment_type' => 'permanent', 'starts_on' => '2026-06-01'], $actor);
    }

    #[Test]
    public function rehire_with_dates_overlapping_the_prior_ended_employment_is_rejected(): void
    {
        Carbon::setTestNow('2026-06-15');
        [$school, $employee, $actor] = $this->separatedEmployeeWithHistory();

        $this->expectException(EmploymentOverlapException::class);

        app(EmployeeLifecycleService::class)->rehire($employee, ['employment_type' => 'permanent', 'starts_on' => '2021-01-01'], $actor);
    }

    #[Test]
    public function rehire_with_a_future_start_is_allowed_but_not_yet_current(): void
    {
        Carbon::setTestNow('2026-06-15');
        [$school, $employee, $actor] = $this->separatedEmployeeWithHistory();

        $second = app(EmployeeLifecycleService::class)->rehire($employee, ['employment_type' => 'permanent', 'starts_on' => '2026-09-01'], $actor);

        app(TenantContext::class)->set($school);
        $fresh = $second->fresh();
        $this->assertSame('2026-09-01', $fresh->starts_on->toDateString());
        $this->assertTrue($fresh->starts_on->toDateString() > Carbon::today()->toDateString(), 'Sanity: the new Employment genuinely starts in the future relative to the fixed clock.');
    }

    #[Test]
    public function rehire_employment_only_without_an_assignment_is_a_valid_choice(): void
    {
        Carbon::setTestNow('2026-06-15');
        [$school, $employee, $actor] = $this->separatedEmployeeWithHistory();

        $second = app(EmployeeLifecycleService::class)->rehire($employee, ['employment_type' => 'permanent', 'starts_on' => '2026-06-01'], $actor);

        app(TenantContext::class)->set($school);
        $this->assertSame(0, $second->assignments()->count());
    }

    #[Test]
    public function rehire_can_create_a_new_primary_assignment_atomically(): void
    {
        Carbon::setTestNow('2026-06-15');
        [$school, $employee, $actor] = $this->separatedEmployeeWithHistory();
        $position = $this->createPosition($school);

        $second = app(EmployeeLifecycleService::class)->rehire(
            $employee,
            ['employment_type' => 'permanent', 'starts_on' => '2026-06-01'],
            $actor,
            assignmentAttributes: ['starts_on' => '2026-06-01'],
            position: $position,
        );

        app(TenantContext::class)->set($school);
        $assignments = $second->assignments()->get();
        $this->assertCount(1, $assignments);
        $this->assertTrue($assignments->first()->is_primary);
        $this->assertNull($assignments->first()->manager_assignment_id, 'A brand new rehire Assignment never inherits a prior manager.');
    }

    #[Test]
    public function rehires_new_assignment_is_a_new_row_never_the_reactivated_old_one(): void
    {
        Carbon::setTestNow('2026-06-15');
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $position = $this->createPosition($school);
        $actor = $this->fullHrActor($school);
        $firstEmployment = app(EmploymentService::class)->create($employee, ['employment_type' => 'permanent', 'starts_on' => '2020-01-01'], $actor);
        $oldAssignment = app(EmployeeAssignmentService::class)->create($firstEmployment, ['starts_on' => '2020-01-01'], $position, $actor);
        app(EmploymentService::class)->end($firstEmployment, '2022-12-31', $actor, 'separated');

        $second = app(EmployeeLifecycleService::class)->rehire(
            $employee,
            ['employment_type' => 'permanent', 'starts_on' => '2026-06-01'],
            $actor,
            assignmentAttributes: ['starts_on' => '2026-06-01'],
            position: $position,
        );

        app(TenantContext::class)->set($school);
        $newAssignment = $second->assignments()->first();
        $this->assertNotSame($oldAssignment->id, $newAssignment->id);
        $this->assertSame('2022-12-31', $oldAssignment->fresh()->ends_on->toDateString(), 'The old historical Assignment is never reopened.');
        $this->assertSame($firstEmployment->id, $oldAssignment->fresh()->employment_record_id, 'The old Assignment still belongs to the OLD Employment, never reparented.');
    }

    #[Test]
    public function rehires_new_assignment_still_enforces_ordinary_position_ownership_rules(): void
    {
        Carbon::setTestNow('2026-06-15');
        [$school, $employee, $actor] = $this->separatedEmployeeWithHistory();
        $otherSchool = $this->createSchool();
        $crossSchoolPosition = $this->createPosition($otherSchool);

        $this->expectException(AssignmentPositionMismatchException::class);

        app(EmployeeLifecycleService::class)->rehire(
            $employee,
            ['employment_type' => 'permanent', 'starts_on' => '2026-06-01'],
            $actor,
            assignmentAttributes: ['starts_on' => '2026-06-01'],
            position: $crossSchoolPosition,
        );
    }

    #[Test]
    public function requesting_an_assignment_without_a_position_is_a_caller_contract_violation(): void
    {
        Carbon::setTestNow('2026-06-15');
        [$school, $employee, $actor] = $this->separatedEmployeeWithHistory();

        $this->expectException(InvalidArgumentException::class);

        app(EmployeeLifecycleService::class)->rehire(
            $employee,
            ['employment_type' => 'permanent', 'starts_on' => '2026-06-01'],
            $actor,
            assignmentAttributes: ['starts_on' => '2026-06-01'],
        );
    }

    #[Test]
    public function an_ordinary_member_cannot_rehire(): void
    {
        Carbon::setTestNow('2026-06-15');
        [$school, $employee, $actor] = $this->separatedEmployeeWithHistory();
        $ordinaryMember = $this->createUserWithCapabilities($school, []);

        $this->expectException(AuthorizationException::class);

        app(EmployeeLifecycleService::class)->rehire($employee, ['employment_type' => 'permanent', 'starts_on' => '2026-06-01'], $ordinaryMember);
    }

    #[Test]
    public function an_actor_authorized_in_school_a_cannot_rehire_a_school_b_employee(): void
    {
        Carbon::setTestNow('2026-06-15');
        [$schoolB, $employeeB] = $this->separatedEmployeeWithHistory();
        $actorA = $this->fullHrActor($this->createSchool());

        $this->expectException(AuthorizationException::class);

        app(EmployeeLifecycleService::class)->rehire($employeeB, ['employment_type' => 'permanent', 'starts_on' => '2026-06-01'], $actorA);
    }

    #[Test]
    public function rehire_never_touches_the_linked_users_account_or_membership(): void
    {
        Carbon::setTestNow('2026-06-15');
        $school = $this->createSchool();
        $linkedUser = $this->createUser();
        $membership = $this->createMembership($linkedUser, $school, 'active');
        $employee = $this->createEmployee($school, ['user_id' => $linkedUser->id]);
        $actor = $this->fullHrActor($school);
        $firstEmployment = app(EmploymentService::class)->create($employee, ['employment_type' => 'permanent', 'starts_on' => '2020-01-01'], $actor);
        app(EmploymentService::class)->end($firstEmployment, '2022-12-31', $actor, 'separated');

        app(EmployeeLifecycleService::class)->rehire($employee, ['employment_type' => 'permanent', 'starts_on' => '2026-06-01'], $actor);

        $this->assertSame('active', $membership->fresh()->status);
        app(TenantContext::class)->set($school);
        $this->assertSame($linkedUser->id, $employee->fresh()->user_id);
    }

    #[Test]
    public function rehire_is_completely_independent_of_employee_record_status(): void
    {
        Carbon::setTestNow('2026-06-15');
        [$school, $employee, $actor, $firstEmployment] = $this->separatedEmployeeWithHistory();

        // No archive()/reactivate() write path exists for
        // Employee.record_status anywhere in this codebase (grepped:
        // only ever set to 'active' at creation) -- this direct model
        // write is TEST SETUP ONLY, simulating the field's only other
        // possible value to prove rehire() never reads or reacts to it
        // (docs/modules/HR.md principle 2.6 / "Employee lifecycle --
        // state responsibility matrix": Employee-record lifecycle and
        // Employment lifecycle are fully independent dimensions).
        app(TenantContext::class)->set($school);
        $employee->forceFill(['record_status' => 'archived'])->save();

        $second = app(EmployeeLifecycleService::class)->rehire($employee, ['employment_type' => 'permanent', 'starts_on' => '2026-06-01'], $actor);

        app(TenantContext::class)->set($school);
        $this->assertSame('active', $second->fresh()->status, 'Rehire succeeds regardless of Employee.record_status.');
        $this->assertSame('archived', $employee->fresh()->record_status, 'rehire() never reactivates Employee.record_status as a side effect.');
    }
}
