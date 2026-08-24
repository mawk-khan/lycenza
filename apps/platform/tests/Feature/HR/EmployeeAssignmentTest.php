<?php

namespace Tests\Feature\HR;

use App\Domain\HR\Application\DepartmentService;
use App\Domain\HR\Application\EmployeeAssignmentService;
use App\Domain\HR\Application\Exceptions\AssignmentCampusMismatchException;
use App\Domain\HR\Application\Exceptions\AssignmentDepartmentCampusScopeMismatchException;
use App\Domain\HR\Application\Exceptions\AssignmentDepartmentMismatchException;
use App\Domain\HR\Application\Exceptions\AssignmentInactiveDepartmentException;
use App\Domain\HR\Application\Exceptions\AssignmentInactivePositionException;
use App\Domain\HR\Application\Exceptions\AssignmentOutsideEmploymentRangeException;
use App\Domain\HR\Application\Exceptions\AssignmentPositionMismatchException;
use App\Domain\HR\Infrastructure\EmployeeAssignment;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 8A.4: proves EmployeeAssignment -- UUIDv7 identity, School
 * ownership, tenant-safe composite FKs to EmploymentRecord/Campus/
 * Department/Position, Campus/Department compatibility, active-
 * reference-only-at-creation rules, EmploymentRecord date-range
 * containment, multiple simultaneous assignments, and the primary-
 * assignment invariant. See tests/Feature/Postgres/HrRawIsolationTest
 * for the independent raw-SQL/RLS proof.
 */
class EmployeeAssignmentTest extends TestCase
{
    use CreatesTenancyFixtures;

    // --- Schema / model -------------------------------------------------

    #[Test]
    public function assignment_id_is_a_real_uuidv7(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $employment = $this->createEmploymentRecord($employee);
        $position = $this->createPosition($school);
        $assignment = $this->createEmployeeAssignment($employment, $position);

        $this->assertInstanceOf(UuidV7::class, Uuid::fromString($assignment->id));
    }

    #[Test]
    public function assignment_belongs_to_its_school_and_employment_record(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $employment = $this->createEmploymentRecord($employee);
        $position = $this->createPosition($school);
        $assignment = $this->createEmployeeAssignment($employment, $position);

        app(TenantContext::class)->set($school);

        $this->assertSame($school->id, $assignment->fresh()->school_id);
        $this->assertSame($employment->id, $assignment->fresh()->employment_record_id);
        $this->assertSame($employment->id, $assignment->fresh()->employmentRecord->id);
    }

    #[Test]
    public function campus_and_department_relationships_resolve_correctly(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $employment = $this->createEmploymentRecord($employee);
        $campus = $this->createCampus($school);
        $department = $this->createDepartment($school);
        $position = $this->createPosition($school);
        $assignment = $this->createEmployeeAssignment($employment, $position, ['campus_id' => $campus->id, 'department_id' => $department->id]);

        app(TenantContext::class)->set($school);
        $fresh = $assignment->fresh();

        $this->assertSame($campus->id, $fresh->campus->id);
        $this->assertSame($department->id, $fresh->department->id);
        $this->assertSame($position->id, $fresh->position->id);
    }

    #[Test]
    public function is_current_is_true_for_an_open_assignment_that_has_already_started(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $employment = $this->createEmploymentRecord($employee);
        $position = $this->createPosition($school);
        $assignment = $this->createEmployeeAssignment($employment, $position, ['starts_on' => now()->subYear()->toDateString(), 'ends_on' => null]);

        $this->assertTrue($assignment->isCurrent());
    }

    #[Test]
    public function is_current_is_false_for_a_future_dated_assignment(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $employment = $this->createEmploymentRecord($employee, ['starts_on' => now()->toDateString()]);
        $position = $this->createPosition($school);
        $assignment = $this->createEmployeeAssignment($employment, $position, ['starts_on' => now()->addMonth()->toDateString(), 'ends_on' => null]);

        $this->assertFalse($assignment->isCurrent());
    }

    #[Test]
    public function is_current_is_false_for_a_historically_ended_assignment(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $employment = $this->createEmploymentRecord($employee);
        $position = $this->createPosition($school);
        $assignment = $this->createEmployeeAssignment($employment, $position, [
            'starts_on' => now()->subYears(2)->toDateString(),
            'ends_on' => now()->subYear()->toDateString(),
        ]);

        $this->assertFalse($assignment->isCurrent());
    }

    // --- Cross-School composite FK rejection (database layer) ---------------

    #[Test]
    public function a_cross_school_employment_record_is_rejected_by_the_composite_foreign_key(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $employeeB = $this->createEmployee($schoolB);
        $employmentB = $this->createEmploymentRecord($employeeB);
        $positionA = $this->createPosition($schoolA);

        app(TenantContext::class)->set($schoolA);

        $this->expectException(QueryException::class);

        DB::transaction(function () use ($schoolA, $employmentB, $positionA): void {
            EmployeeAssignment::query()->create([
                'school_id' => $schoolA->id,
                'employment_record_id' => $employmentB->id,
                'position_id' => $positionA->id,
                'starts_on' => '2026-01-01',
            ]);
        });
    }

    #[Test]
    public function a_cross_school_campus_is_rejected_by_the_composite_foreign_key(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $employeeA = $this->createEmployee($schoolA);
        $employmentA = $this->createEmploymentRecord($employeeA);
        $positionA = $this->createPosition($schoolA);
        $campusB = $this->createCampus($schoolB);

        app(TenantContext::class)->set($schoolA);

        $this->expectException(QueryException::class);

        DB::transaction(function () use ($schoolA, $employmentA, $positionA, $campusB): void {
            EmployeeAssignment::query()->create([
                'school_id' => $schoolA->id,
                'employment_record_id' => $employmentA->id,
                'position_id' => $positionA->id,
                'campus_id' => $campusB->id,
                'starts_on' => '2026-01-01',
            ]);
        });
    }

    #[Test]
    public function a_cross_school_department_is_rejected_by_the_composite_foreign_key(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $employeeA = $this->createEmployee($schoolA);
        $employmentA = $this->createEmploymentRecord($employeeA);
        $positionA = $this->createPosition($schoolA);
        $departmentB = $this->createDepartment($schoolB);

        app(TenantContext::class)->set($schoolA);

        $this->expectException(QueryException::class);

        DB::transaction(function () use ($schoolA, $employmentA, $positionA, $departmentB): void {
            EmployeeAssignment::query()->create([
                'school_id' => $schoolA->id,
                'employment_record_id' => $employmentA->id,
                'position_id' => $positionA->id,
                'department_id' => $departmentB->id,
                'starts_on' => '2026-01-01',
            ]);
        });
    }

    #[Test]
    public function a_cross_school_position_is_rejected_by_the_composite_foreign_key(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $employeeA = $this->createEmployee($schoolA);
        $employmentA = $this->createEmploymentRecord($employeeA);
        $positionB = $this->createPosition($schoolB);

        app(TenantContext::class)->set($schoolA);

        $this->expectException(QueryException::class);

        DB::transaction(function () use ($schoolA, $employmentA, $positionB): void {
            EmployeeAssignment::query()->create([
                'school_id' => $schoolA->id,
                'employment_record_id' => $employmentA->id,
                'position_id' => $positionB->id,
                'starts_on' => '2026-01-01',
            ]);
        });
    }

    // --- Service: same-School semantic mismatch rejection --------------------

    #[Test]
    public function service_create_rejects_a_campus_from_a_different_school(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $employeeA = $this->createEmployee($schoolA);
        $employmentA = $this->createEmploymentRecord($employeeA);
        $positionA = $this->createPosition($schoolA);
        $campusB = $this->createCampus($schoolB);

        $this->expectException(AssignmentCampusMismatchException::class);

        app(EmployeeAssignmentService::class)->create($employmentA, ['starts_on' => '2026-01-01'], $positionA, $this->fullHrActor($schoolA), campus: $campusB);
    }

    #[Test]
    public function service_create_rejects_a_department_from_a_different_school(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $employeeA = $this->createEmployee($schoolA);
        $employmentA = $this->createEmploymentRecord($employeeA);
        $positionA = $this->createPosition($schoolA);
        $departmentB = $this->createDepartment($schoolB);

        $this->expectException(AssignmentDepartmentMismatchException::class);

        app(EmployeeAssignmentService::class)->create($employmentA, ['starts_on' => '2026-01-01'], $positionA, $this->fullHrActor($schoolA), department: $departmentB);
    }

    #[Test]
    public function service_create_rejects_a_position_from_a_different_school(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $employeeA = $this->createEmployee($schoolA);
        $employmentA = $this->createEmploymentRecord($employeeA);
        $positionB = $this->createPosition($schoolB);

        $this->expectException(AssignmentPositionMismatchException::class);

        app(EmployeeAssignmentService::class)->create($employmentA, ['starts_on' => '2026-01-01'], $positionB, $this->fullHrActor($schoolA));
    }

    // --- Service: Campus / Department compatibility --------------------------

    #[Test]
    public function a_school_wide_department_works_with_any_campus(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $employment = $this->createEmploymentRecord($employee);
        $position = $this->createPosition($school);
        $campus = $this->createCampus($school);
        $schoolWideDepartment = $this->createDepartment($school, ['campus_id' => null]);

        $assignment = app(EmployeeAssignmentService::class)->create(
            $employment,
            ['starts_on' => '2026-01-01'],
            $position,
            $this->fullHrActor($school),
            campus: $campus,
            department: $schoolWideDepartment,
        );

        $this->assertSame($campus->id, $assignment->campus_id);
        $this->assertSame($schoolWideDepartment->id, $assignment->department_id);
    }

    #[Test]
    public function a_campus_scoped_department_works_when_the_assignment_campus_matches(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $employment = $this->createEmploymentRecord($employee);
        $position = $this->createPosition($school);
        $campus = $this->createCampus($school);
        $scopedDepartment = $this->createDepartment($school, ['campus_id' => $campus->id]);

        $assignment = app(EmployeeAssignmentService::class)->create(
            $employment,
            ['starts_on' => '2026-01-01'],
            $position,
            $this->fullHrActor($school),
            campus: $campus,
            department: $scopedDepartment,
        );

        $this->assertSame($campus->id, $assignment->campus_id);
    }

    #[Test]
    public function a_campus_scoped_department_is_rejected_when_the_assignment_campus_does_not_match(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $employment = $this->createEmploymentRecord($employee);
        $position = $this->createPosition($school);
        $northCampus = $this->createCampus($school, ['code' => 'NORTH']);
        $southCampus = $this->createCampus($school, ['code' => 'SOUTH']);
        $departmentScopedToNorth = $this->createDepartment($school, ['campus_id' => $northCampus->id]);

        $this->expectException(AssignmentDepartmentCampusScopeMismatchException::class);

        app(EmployeeAssignmentService::class)->create(
            $employment,
            ['starts_on' => '2026-01-01'],
            $position,
            $this->fullHrActor($school),
            campus: $southCampus,
            department: $departmentScopedToNorth,
        );
    }

    #[Test]
    public function a_campus_scoped_department_is_rejected_when_the_assignment_has_no_campus(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $employment = $this->createEmploymentRecord($employee);
        $position = $this->createPosition($school);
        $campus = $this->createCampus($school);
        $scopedDepartment = $this->createDepartment($school, ['campus_id' => $campus->id]);

        $this->expectException(AssignmentDepartmentCampusScopeMismatchException::class);

        app(EmployeeAssignmentService::class)->create($employment, ['starts_on' => '2026-01-01'], $position, $this->fullHrActor($school), department: $scopedDepartment);
    }

    // --- Service: active-reference-at-creation rules --------------------------

    #[Test]
    public function an_inactive_department_is_rejected_for_a_new_assignment(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $employment = $this->createEmploymentRecord($employee);
        $position = $this->createPosition($school);
        $inactiveDepartment = $this->createDepartment($school, ['status' => 'inactive']);

        $this->expectException(AssignmentInactiveDepartmentException::class);

        app(EmployeeAssignmentService::class)->create($employment, ['starts_on' => '2026-01-01'], $position, $this->fullHrActor($school), department: $inactiveDepartment);
    }

    #[Test]
    public function an_inactive_position_is_rejected_for_a_new_assignment(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $employment = $this->createEmploymentRecord($employee);
        $inactivePosition = $this->createPosition($school, ['status' => 'inactive']);

        $this->expectException(AssignmentInactivePositionException::class);

        app(EmployeeAssignmentService::class)->create($employment, ['starts_on' => '2026-01-01'], $inactivePosition, $this->fullHrActor($school));
    }

    #[Test]
    public function archiving_a_department_after_assignment_creation_preserves_the_historical_reference(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $employment = $this->createEmploymentRecord($employee);
        $position = $this->createPosition($school);
        $department = $this->createDepartment($school);
        $actor = $this->fullHrActor($school);
        $assignment = app(EmployeeAssignmentService::class)->create($employment, ['starts_on' => '2026-01-01'], $position, $actor, department: $department);

        app(DepartmentService::class)->archive($department, $actor);

        app(TenantContext::class)->set($school);
        $this->assertSame($department->id, $assignment->fresh()->department_id);
    }

    // --- Service: EmploymentRecord date-range containment ---------------------

    #[Test]
    public function an_assignment_starting_before_the_employment_is_rejected(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $employment = $this->createEmploymentRecord($employee, ['starts_on' => '2026-06-01', 'ends_on' => '2027-05-31']);
        $position = $this->createPosition($school);

        $this->expectException(AssignmentOutsideEmploymentRangeException::class);

        app(EmployeeAssignmentService::class)->create($employment, ['starts_on' => '2026-05-01', 'ends_on' => '2027-05-31'], $position, $this->fullHrActor($school));
    }

    #[Test]
    public function an_assignment_ending_after_the_employment_is_rejected(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $employment = $this->createEmploymentRecord($employee, ['starts_on' => '2026-06-01', 'ends_on' => '2027-05-31']);
        $position = $this->createPosition($school);

        $this->expectException(AssignmentOutsideEmploymentRangeException::class);

        app(EmployeeAssignmentService::class)->create($employment, ['starts_on' => '2026-06-01', 'ends_on' => '2027-06-30'], $position, $this->fullHrActor($school));
    }

    #[Test]
    public function an_open_ended_assignment_is_rejected_when_the_employment_is_bounded(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $employment = $this->createEmploymentRecord($employee, ['starts_on' => '2026-06-01', 'ends_on' => '2027-05-31']);
        $position = $this->createPosition($school);

        $this->expectException(AssignmentOutsideEmploymentRangeException::class);

        app(EmployeeAssignmentService::class)->create($employment, ['starts_on' => '2026-06-01', 'ends_on' => null], $position, $this->fullHrActor($school));
    }

    #[Test]
    public function an_open_ended_assignment_is_allowed_when_the_employment_is_open_ended(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $employment = $this->createEmploymentRecord($employee, ['starts_on' => '2026-06-01', 'ends_on' => null]);
        $position = $this->createPosition($school);

        $assignment = app(EmployeeAssignmentService::class)->create($employment, ['starts_on' => '2026-06-01', 'ends_on' => null], $position, $this->fullHrActor($school));

        $this->assertNull($assignment->ends_on);
    }

    #[Test]
    public function a_bounded_assignment_fully_contained_within_an_open_ended_employment_is_allowed(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $employment = $this->createEmploymentRecord($employee, ['starts_on' => '2026-01-01', 'ends_on' => null]);
        $position = $this->createPosition($school);

        $assignment = app(EmployeeAssignmentService::class)->create($employment, ['starts_on' => '2026-06-01', 'ends_on' => '2026-12-31'], $position, $this->fullHrActor($school));

        $this->assertSame('2026-12-31', $assignment->ends_on->toDateString());
    }

    // --- Multiple assignments / primary invariant -----------------------------

    #[Test]
    public function multiple_simultaneous_secondary_assignments_are_allowed(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $employment = $this->createEmploymentRecord($employee, ['starts_on' => '2026-01-01', 'ends_on' => null]);
        $teacherPosition = $this->createPosition($school, ['code' => 'TCH']);
        $coordinatorPosition = $this->createPosition($school, ['code' => 'COORD']);
        $service = app(EmployeeAssignmentService::class);
        $actor = $this->fullHrActor($school);

        $service->create($employment, ['starts_on' => '2026-01-01'], $teacherPosition, $actor);
        $service->create($employment, ['starts_on' => '2026-01-01'], $coordinatorPosition, $actor);

        app(TenantContext::class)->set($school);
        $this->assertCount(2, $employment->assignments()->get());
    }

    #[Test]
    public function service_create_ignores_a_caller_supplied_is_primary_true(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $employment = $this->createEmploymentRecord($employee, ['starts_on' => '2026-01-01', 'ends_on' => null]);
        $position = $this->createPosition($school);

        $assignment = app(EmployeeAssignmentService::class)->create($employment, ['starts_on' => '2026-01-01', 'is_primary' => true], $position, $this->fullHrActor($school));

        $this->assertFalse($assignment->is_primary, 'create() must never let a caller create an already-primary assignment -- setPrimary() is the sole promotion path.');
    }

    #[Test]
    public function a_second_open_primary_assignment_for_the_same_employment_is_rejected_by_the_database(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $employment = $this->createEmploymentRecord($employee, ['starts_on' => '2026-01-01', 'ends_on' => null]);
        $position = $this->createPosition($school);
        $this->createEmployeeAssignment($employment, $position, ['is_primary' => true, 'starts_on' => '2026-01-01', 'ends_on' => null]);

        app(TenantContext::class)->set($school);

        $this->expectException(UniqueConstraintViolationException::class);

        DB::transaction(function () use ($school, $employment, $position): void {
            EmployeeAssignment::query()->create([
                'school_id' => $school->id,
                'employment_record_id' => $employment->id,
                'position_id' => $position->id,
                'is_primary' => true,
                'starts_on' => '2026-06-01',
            ]);
        });
    }

    #[Test]
    public function set_primary_demotes_the_previous_primary_and_promotes_the_new_one(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $employment = $this->createEmploymentRecord($employee, ['starts_on' => '2026-01-01', 'ends_on' => null]);
        $position = $this->createPosition($school);
        $original = $this->createEmployeeAssignment($employment, $position, ['is_primary' => true, 'starts_on' => '2026-01-01', 'ends_on' => null]);
        $replacement = $this->createEmployeeAssignment($employment, $position, ['is_primary' => false, 'starts_on' => '2026-01-01', 'ends_on' => null]);

        app(EmployeeAssignmentService::class)->setPrimary($replacement, $this->fullHrActor($school));

        app(TenantContext::class)->set($school);
        $this->assertFalse($original->fresh()->is_primary);
        $this->assertTrue($replacement->fresh()->is_primary);
    }

    #[Test]
    public function a_historical_ended_primary_does_not_block_a_later_primary(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $employment = $this->createEmploymentRecord($employee, ['starts_on' => '2020-01-01', 'ends_on' => null]);
        $position = $this->createPosition($school);
        $this->createEmployeeAssignment($employment, $position, [
            'is_primary' => true,
            'starts_on' => '2020-01-01',
            'ends_on' => '2024-01-01',
        ]);

        $actor = $this->fullHrActor($school);
        $newPrimary = app(EmployeeAssignmentService::class)->create($employment, ['starts_on' => '2024-01-02'], $position, $actor);
        $promoted = app(EmployeeAssignmentService::class)->setPrimary($newPrimary, $actor);

        $this->assertTrue($promoted->is_primary);
    }

    #[Test]
    public function a_secondary_assignment_coexists_with_a_primary_assignment(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $employment = $this->createEmploymentRecord($employee, ['starts_on' => '2026-01-01', 'ends_on' => null]);
        $teacherPosition = $this->createPosition($school, ['code' => 'TCH']);
        $coordinatorPosition = $this->createPosition($school, ['code' => 'COORD']);
        $service = app(EmployeeAssignmentService::class);
        $actor = $this->fullHrActor($school);

        $primary = $service->create($employment, ['starts_on' => '2026-01-01'], $teacherPosition, $actor);
        $service->setPrimary($primary, $actor);
        $secondary = $service->create($employment, ['starts_on' => '2026-01-01'], $coordinatorPosition, $actor);

        app(TenantContext::class)->set($school);
        $this->assertTrue($primary->fresh()->is_primary);
        $this->assertFalse($secondary->fresh()->is_primary);
    }

    #[Test]
    public function cross_school_primary_manipulation_is_impossible_via_raw_sql(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $employeeB = $this->createEmployee($schoolB);
        $employmentB = $this->createEmploymentRecord($employeeB, ['starts_on' => '2026-01-01', 'ends_on' => null]);
        $positionB = $this->createPosition($schoolB);
        $assignmentB = $this->createEmployeeAssignment($employmentB, $positionB, ['is_primary' => false, 'starts_on' => '2026-01-01', 'ends_on' => null]);

        app(TenantContext::class)->set($schoolA);

        $affected = EmployeeAssignment::query()->where('id', $assignmentB->id)->update(['is_primary' => true]);

        $this->assertSame(0, $affected);
    }

    // --- end() ----------------------------------------------------------------

    #[Test]
    public function ending_an_assignment_sets_ends_on(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $employment = $this->createEmploymentRecord($employee, ['starts_on' => '2026-01-01', 'ends_on' => null]);
        $position = $this->createPosition($school);
        $actor = $this->fullHrActor($school);
        $assignment = app(EmployeeAssignmentService::class)->create($employment, ['starts_on' => '2026-01-01'], $position, $actor);

        $ended = app(EmployeeAssignmentService::class)->end($assignment, '2026-12-31', $actor);

        $this->assertSame('2026-12-31', $ended->ends_on->toDateString());
    }

    // --- Tenant isolation (Eloquent layer) -----------------------------------

    #[Test]
    public function school_a_cannot_see_school_bs_assignment(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $employeeB = $this->createEmployee($schoolB);
        $employmentB = $this->createEmploymentRecord($employeeB);
        $positionB = $this->createPosition($schoolB);
        $this->createEmployeeAssignment($employmentB, $positionB);

        app(TenantContext::class)->set($schoolA);

        $this->assertSame(0, EmployeeAssignment::query()->count());
    }

    #[Test]
    public function school_b_cannot_update_school_as_assignment(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $employeeA = $this->createEmployee($schoolA);
        $employmentA = $this->createEmploymentRecord($employeeA);
        $positionA = $this->createPosition($schoolA);
        $assignmentA = $this->createEmployeeAssignment($employmentA, $positionA);

        app(TenantContext::class)->set($schoolB);

        $affected = EmployeeAssignment::query()->where('id', $assignmentA->id)->update(['is_primary' => true]);

        $this->assertSame(0, $affected);
    }
}
