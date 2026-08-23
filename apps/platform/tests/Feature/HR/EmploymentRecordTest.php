<?php

namespace Tests\Feature\HR;

use App\Domain\HR\Application\EmployeeAssignmentService;
use App\Domain\HR\Application\EmployeeService;
use App\Domain\HR\Application\EmploymentService;
use App\Domain\HR\Application\Exceptions\EmploymentOverlapException;
use App\Domain\HR\Infrastructure\EmploymentRecord;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 8A.4: proves EmploymentRecord -- UUIDv7 identity, School/
 * Employee ownership, temporal interval semantics, the overlap policy,
 * rehire support, employment-ending behavior (including its cascade to
 * open Assignments), and tenant isolation at the Eloquent layer. See
 * tests/Feature/Postgres/HrRawIsolationTest for the independent
 * raw-SQL/RLS proof.
 */
class EmploymentRecordTest extends TestCase
{
    use CreatesTenancyFixtures;

    // --- Schema / model -------------------------------------------------

    #[Test]
    public function employment_record_id_is_a_real_uuidv7(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $employment = $this->createEmploymentRecord($employee);

        $this->assertInstanceOf(UuidV7::class, Uuid::fromString($employment->id));
    }

    #[Test]
    public function employment_record_belongs_to_its_school(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $employment = $this->createEmploymentRecord($employee);

        app(TenantContext::class)->set($school);

        $this->assertSame($school->id, $employment->fresh()->school_id);
    }

    #[Test]
    public function employment_record_belongs_to_its_employee(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $employment = $this->createEmploymentRecord($employee);

        app(TenantContext::class)->set($school);

        $this->assertSame($employee->id, $employment->fresh()->employee_id);
        $this->assertSame($employee->id, $employment->fresh()->employee->id);
    }

    #[Test]
    public function a_school_a_employee_id_combined_with_school_b_is_rejected_by_the_composite_foreign_key(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $employeeA = $this->createEmployee($schoolA);

        app(TenantContext::class)->set($schoolB);

        $this->expectException(QueryException::class);

        DB::transaction(function () use ($employeeA, $schoolB): void {
            EmploymentRecord::query()->create([
                'school_id' => $schoolB->id,
                'employee_id' => $employeeA->id,
                'employment_type' => 'permanent',
                'starts_on' => '2026-01-01',
                'status' => 'active',
            ]);
        });
    }

    #[Test]
    public function valid_start_and_end_dates_work(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $employment = $this->createEmploymentRecord($employee, ['starts_on' => '2022-06-01', 'ends_on' => '2025-03-31']);

        app(TenantContext::class)->set($school);
        $fresh = $employment->fresh();

        $this->assertSame('2022-06-01', $fresh->starts_on->toDateString());
        $this->assertSame('2025-03-31', $fresh->ends_on->toDateString());
    }

    #[Test]
    public function an_end_date_before_the_start_date_is_rejected_by_the_database(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);

        app(TenantContext::class)->set($school);

        $this->expectException(QueryException::class);

        DB::transaction(function () use ($school, $employee): void {
            EmploymentRecord::query()->create([
                'school_id' => $school->id,
                'employee_id' => $employee->id,
                'employment_type' => 'permanent',
                'starts_on' => '2026-06-01',
                'ends_on' => '2026-01-01',
                'status' => 'active',
            ]);
        });
    }

    #[Test]
    public function an_open_ended_employment_is_valid(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $employment = $this->createEmploymentRecord($employee, ['ends_on' => null]);

        $this->assertTrue($employment->isOpen());
        $this->assertNull($employment->ends_on);
    }

    #[Test]
    public function a_historical_ended_employment_is_valid(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $employment = $this->createEmploymentRecord($employee, [
            'starts_on' => '2020-01-01',
            'ends_on' => '2021-12-31',
            'status' => 'separated',
        ]);

        $this->assertFalse($employment->isOpen());
        $this->assertSame('separated', $employment->status);
    }

    // --- Service ------------------------------------------------------------

    #[Test]
    public function service_create_defaults_status_to_active(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);

        $employment = app(EmploymentService::class)->create($employee, [
            'employment_type' => 'permanent',
            'starts_on' => '2026-01-01',
        ]);

        $this->assertSame('active', $employment->status);
        $this->assertSame($employee->id, $employment->employee_id);
    }

    #[Test]
    public function service_create_respects_an_explicit_status(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);

        $employment = app(EmploymentService::class)->create($employee, [
            'employment_type' => 'permanent',
            'starts_on' => '2027-01-01',
            'status' => 'pre_joining',
        ]);

        $this->assertSame('pre_joining', $employment->status);
    }

    #[Test]
    public function service_update_ignores_ownership_and_temporal_fields(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $employeeA = $this->createEmployee($schoolA);
        $employeeB = $this->createEmployee($schoolA);
        $employment = $this->createEmploymentRecord($employeeA, ['employment_type' => 'permanent', 'starts_on' => '2026-01-01']);

        $updated = app(EmploymentService::class)->update($employment, [
            'employment_type' => 'fixed_term',
            'school_id' => $schoolB->id,
            'employee_id' => $employeeB->id,
            'starts_on' => '2020-01-01',
            'ends_on' => '2021-01-01',
            'status' => 'terminated',
        ]);

        $this->assertSame('fixed_term', $updated->employment_type);
        $this->assertSame($schoolA->id, $updated->school_id);
        $this->assertSame($employeeA->id, $updated->employee_id);
        $this->assertSame('2026-01-01', $updated->starts_on->toDateString());
        $this->assertNull($updated->ends_on);
        $this->assertSame('active', $updated->status);
    }

    #[Test]
    public function overlapping_employment_for_the_same_employee_is_rejected(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $service = app(EmploymentService::class);

        $service->create($employee, ['employment_type' => 'permanent', 'starts_on' => '2022-06-01', 'ends_on' => '2025-03-31']);

        $this->expectException(EmploymentOverlapException::class);

        $service->create($employee, ['employment_type' => 'permanent', 'starts_on' => '2024-01-01', 'ends_on' => '2026-01-01']);
    }

    #[Test]
    public function an_open_ended_employment_blocks_any_later_overlapping_start(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $service = app(EmploymentService::class);

        $service->create($employee, ['employment_type' => 'permanent', 'starts_on' => '2020-01-01', 'ends_on' => null]);

        $this->expectException(EmploymentOverlapException::class);

        $service->create($employee, ['employment_type' => 'contract', 'starts_on' => '2026-01-01']);
    }

    #[Test]
    public function non_overlapping_sequential_employment_is_allowed(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $service = app(EmploymentService::class);

        $first = $service->create($employee, ['employment_type' => 'permanent', 'starts_on' => '2022-06-01', 'ends_on' => '2025-03-31']);
        $second = $service->create($employee, ['employment_type' => 'permanent', 'starts_on' => '2025-04-01']);

        $this->assertNotSame($first->id, $second->id);
    }

    #[Test]
    public function ending_an_employment_sets_ends_on_and_status(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $employment = app(EmploymentService::class)->create($employee, ['employment_type' => 'permanent', 'starts_on' => '2022-01-01']);

        $ended = app(EmploymentService::class)->end($employment, '2026-03-31', 'retired');

        $this->assertSame('2026-03-31', $ended->ends_on->toDateString());
        $this->assertSame('retired', $ended->status);
    }

    #[Test]
    public function ending_an_employment_closes_open_assignments_at_the_same_date(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $department = $this->createDepartment($school);
        $position = $this->createPosition($school);
        $employment = app(EmploymentService::class)->create($employee, ['employment_type' => 'permanent', 'starts_on' => '2022-01-01']);
        $assignment = app(EmployeeAssignmentService::class)->create(
            $employment,
            ['starts_on' => '2022-01-01'],
            $position,
            department: $department,
        );

        app(EmploymentService::class)->end($employment, '2026-03-31', 'separated');

        app(TenantContext::class)->set($school);
        $this->assertSame('2026-03-31', $assignment->fresh()->ends_on->toDateString());
    }

    #[Test]
    public function ending_an_employment_does_not_touch_already_closed_assignments(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $position = $this->createPosition($school);
        $employment = app(EmploymentService::class)->create($employee, ['employment_type' => 'permanent', 'starts_on' => '2022-01-01']);
        $closedAssignment = app(EmployeeAssignmentService::class)->create(
            $employment,
            ['starts_on' => '2022-01-01', 'ends_on' => '2023-01-01'],
            $position,
        );

        app(EmploymentService::class)->end($employment, '2026-03-31', 'separated');

        app(TenantContext::class)->set($school);
        $this->assertSame('2023-01-01', $closedAssignment->fresh()->ends_on->toDateString());
    }

    // --- Rehire (core Phase 8A architecture proof) ---------------------------

    #[Test]
    public function rehire_preserves_employee_identity_and_creates_an_independent_second_employment(): void
    {
        $school = $this->createSchool();
        $employee = app(EmployeeService::class)->create($school, ['full_name' => 'Asha Verma']);
        $employmentService = app(EmploymentService::class);

        $firstEmployment = $employmentService->create($employee, [
            'employment_type' => 'permanent',
            'starts_on' => '2022-06-01',
        ]);
        $endedFirst = $employmentService->end($firstEmployment, '2025-03-31', 'separated');

        $secondEmployment = $employmentService->create($employee, [
            'employment_type' => 'permanent',
            'starts_on' => '2026-06-01',
        ]);

        app(TenantContext::class)->set($school);

        // Same Employee UUID and employee_number.
        $this->assertSame($employee->id, $endedFirst->employee_id);
        $this->assertSame($employee->id, $secondEmployment->employee_id);
        $this->assertSame($employee->employee_number, $employee->fresh()->employee_number);

        // Two distinct EmploymentRecord UUIDs.
        $this->assertNotSame($firstEmployment->id, $secondEmployment->id);

        // Employment #1 unchanged, independently queryable.
        $this->assertSame('2025-03-31', $endedFirst->fresh()->ends_on->toDateString());
        $this->assertSame('separated', $endedFirst->fresh()->status);

        // Employment #2 independently active and open-ended.
        $this->assertSame('2026-06-01', $secondEmployment->fresh()->starts_on->toDateString());
        $this->assertTrue($secondEmployment->fresh()->isOpen());
        $this->assertSame('active', $secondEmployment->fresh()->status);

        $this->assertSame(2, EmploymentRecord::query()->where('employee_id', $employee->id)->count());
    }

    #[Test]
    public function rehire_assignments_attach_to_the_correct_employment_record(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $positionA = $this->createPosition($school, ['code' => 'TCH-A']);
        $positionB = $this->createPosition($school, ['code' => 'TCH-B']);
        $employmentService = app(EmploymentService::class);
        $assignmentService = app(EmployeeAssignmentService::class);

        $firstEmployment = $employmentService->create($employee, ['employment_type' => 'permanent', 'starts_on' => '2022-06-01']);
        $firstAssignment = $assignmentService->create($firstEmployment, ['starts_on' => '2022-06-01'], $positionA);
        $employmentService->end($firstEmployment, '2025-03-31', 'separated');

        $secondEmployment = $employmentService->create($employee, ['employment_type' => 'permanent', 'starts_on' => '2026-06-01']);
        $secondAssignment = $assignmentService->create($secondEmployment, ['starts_on' => '2026-06-01'], $positionB);

        app(TenantContext::class)->set($school);

        $this->assertSame($firstEmployment->id, $firstAssignment->fresh()->employment_record_id);
        $this->assertSame($secondEmployment->id, $secondAssignment->fresh()->employment_record_id);
        $this->assertCount(1, $firstEmployment->assignments()->get());
        $this->assertCount(1, $secondEmployment->assignments()->get());
    }

    // --- Tenant isolation (Eloquent layer) -----------------------------------

    #[Test]
    public function school_a_cannot_see_school_bs_employment_record(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $employeeB = $this->createEmployee($schoolB);
        $this->createEmploymentRecord($employeeB);

        app(TenantContext::class)->set($schoolA);

        $this->assertSame(0, EmploymentRecord::query()->count());
    }

    #[Test]
    public function school_b_cannot_update_school_as_employment_record(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $employeeA = $this->createEmployee($schoolA);
        $employment = $this->createEmploymentRecord($employeeA, ['employment_type' => 'permanent']);

        app(TenantContext::class)->set($schoolB);

        $affected = EmploymentRecord::query()->where('id', $employment->id)->update(['employment_type' => 'contract']);

        $this->assertSame(0, $affected);
    }
}
