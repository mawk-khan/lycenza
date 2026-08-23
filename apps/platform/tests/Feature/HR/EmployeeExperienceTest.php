<?php

namespace Tests\Feature\HR;

use App\Domain\HR\Application\EmployeeExperienceService;
use App\Domain\HR\Application\Exceptions\EmployeeOwnershipMismatchException;
use App\Domain\HR\Infrastructure\EmployeeExperience;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 8A.6: proves the Employee's 1:N Restricted-tier record of
 * professional experience OUTSIDE this School's own employment --
 * UUIDv7 identity, School/Employee ownership, date-range validity,
 * overlapping-experience tolerance, and the structural distinction
 * from EmploymentRecord.
 */
class EmployeeExperienceTest extends TestCase
{
    use CreatesTenancyFixtures;

    #[Test]
    public function experience_id_is_a_real_uuidv7(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $experience = $this->createEmployeeExperience($employee);

        $this->assertInstanceOf(UuidV7::class, Uuid::fromString($experience->id));
    }

    #[Test]
    public function an_employee_may_have_multiple_experience_records(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $this->createEmployeeExperience($employee);
        $this->createEmployeeExperience($employee);

        app(TenantContext::class)->set($school);

        $this->assertCount(2, $employee->experienceRecords()->get());
    }

    #[Test]
    public function an_open_ended_ongoing_experience_entry_is_valid(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $experience = $this->createEmployeeExperience($employee, ['ends_on' => null]);

        $this->assertNull($experience->ends_on);
    }

    #[Test]
    public function overlapping_experience_entries_are_allowed(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $this->createEmployeeExperience($employee, ['starts_on' => '2020-01-01', 'ends_on' => '2021-06-01']);
        $overlapping = $this->createEmployeeExperience($employee, ['starts_on' => '2020-06-01', 'ends_on' => '2021-12-01']);

        $this->assertNotNull($overlapping->id, 'Concurrent external experience (e.g. consulting alongside another role) must be representable.');
    }

    #[Test]
    public function an_end_date_before_the_start_date_is_rejected(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);

        app(TenantContext::class)->set($school);

        $this->expectException(QueryException::class);

        DB::transaction(function () use ($employee): void {
            EmployeeExperience::query()->create([
                'school_id' => $employee->school_id,
                'employee_id' => $employee->id,
                'organization' => 'Some Organization',
                'job_title' => 'Some Role',
                'starts_on' => '2020-06-01',
                'ends_on' => '2019-06-01',
            ]);
        });
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
            EmployeeExperience::query()->create([
                'school_id' => $schoolB->id,
                'employee_id' => $employeeA->id,
                'organization' => 'Rogue Organization',
                'job_title' => 'Rogue Role',
                'starts_on' => '2020-01-01',
            ]);
        });
    }

    #[Test]
    public function service_add_ignores_caller_supplied_school_and_employee_id(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $otherEmployee = $this->createEmployee($school);

        $experience = app(EmployeeExperienceService::class)->add($employee, [
            'organization' => 'Some Organization',
            'job_title' => 'Some Role',
            'starts_on' => '2020-01-01',
            'employee_id' => $otherEmployee->id,
        ]);

        $this->assertSame($employee->id, $experience->employee_id, 'A caller-supplied employee_id in the attributes array must never override the authoritative Employee argument.');
    }

    #[Test]
    public function updating_an_experience_record_belonging_to_a_different_employee_is_rejected(): void
    {
        $school = $this->createSchool();
        $employeeA = $this->createEmployee($school);
        $employeeB = $this->createEmployee($school);
        $experienceB = $this->createEmployeeExperience($employeeB);

        $this->expectException(EmployeeOwnershipMismatchException::class);

        app(EmployeeExperienceService::class)->update($employeeA, $experienceB, ['organization' => 'Hacked Organization']);
    }

    #[Test]
    public function removing_an_experience_record_belonging_to_a_different_employee_is_rejected(): void
    {
        $school = $this->createSchool();
        $employeeA = $this->createEmployee($school);
        $employeeB = $this->createEmployee($school);
        $experienceB = $this->createEmployeeExperience($employeeB);

        $this->expectException(EmployeeOwnershipMismatchException::class);

        app(EmployeeExperienceService::class)->remove($employeeA, $experienceB);
    }

    #[Test]
    public function update_via_service_persists_and_survives_a_mass_assignment_attempt_on_school_id(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $employeeA = $this->createEmployee($schoolA);
        $experience = $this->createEmployeeExperience($employeeA, ['organization' => 'Original Organization']);

        $updated = app(EmployeeExperienceService::class)->update($employeeA, $experience, [
            'organization' => 'Updated Organization',
            'school_id' => $schoolB->id,
        ]);

        $this->assertSame('Updated Organization', $updated->organization);
        $this->assertSame($schoolA->id, $updated->school_id, 'A caller-supplied school_id in the attributes array must never move a record to a different School.');
    }

    #[Test]
    public function external_experience_is_structurally_distinct_from_employment_record(): void
    {
        $this->assertFalse(
            Schema::hasColumn('employee_experience_records', 'employment_record_id'),
            'employee_experience_records must never reference employment_records -- external experience is not this School\'s own employment.',
        );
        $this->assertFalse(Schema::hasColumn('employee_experience_records', 'campus_id'));
        $this->assertFalse(Schema::hasColumn('employee_experience_records', 'department_id'));
        $this->assertFalse(Schema::hasColumn('employee_experience_records', 'position_id'));
    }

    #[Test]
    public function experience_records_carry_no_verification_columns(): void
    {
        $this->assertFalse(
            Schema::hasColumn('employee_experience_records', 'verification_status'),
            'External experience deliberately has no verification workflow in Phase 8A.6.',
        );
    }
}
