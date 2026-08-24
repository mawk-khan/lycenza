<?php

namespace Tests\Feature\HR;

use App\Domain\HR\Application\EmployeeQualificationService;
use App\Domain\HR\Application\Exceptions\EmployeeOwnershipMismatchException;
use App\Domain\HR\Infrastructure\EmployeeQualification;
use App\Models\SchoolAuditEvent;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 8A.6: proves the Employee's 1:N Restricted-tier qualification
 * history -- UUIDv7 identity, School/Employee ownership, date-range
 * validity, verification transitions, and the material-edit
 * verification-reset rule.
 */
class EmployeeQualificationTest extends TestCase
{
    use CreatesTenancyFixtures;

    #[Test]
    public function qualification_id_is_a_real_uuidv7(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $qualification = $this->createEmployeeQualification($employee);

        $this->assertInstanceOf(UuidV7::class, Uuid::fromString($qualification->id));
    }

    #[Test]
    public function an_employee_may_have_multiple_qualifications(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $this->createEmployeeQualification($employee, ['qualification_type' => 'bachelors']);
        $this->createEmployeeQualification($employee, ['qualification_type' => 'masters']);

        app(TenantContext::class)->set($school);

        $this->assertCount(2, $employee->qualifications()->get());
    }

    #[Test]
    public function ownership_is_preserved_when_fetched_through_the_employee(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $qualification = $this->createEmployeeQualification($employee);

        app(TenantContext::class)->set($school);

        $this->assertSame($employee->id, $qualification->fresh()->employee_id);
        $this->assertTrue($employee->qualifications()->get()->contains('id', $qualification->id));
    }

    #[Test]
    public function new_qualifications_default_to_unverified(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $qualification = $this->createEmployeeQualification($employee);

        $this->assertSame('unverified', $qualification->verification_status);
        $this->assertNull($qualification->verified_at);
    }

    #[Test]
    public function an_ongoing_qualification_with_no_completion_date_is_valid(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $qualification = $this->createEmployeeQualification($employee, ['completed_on' => null]);

        $this->assertNull($qualification->completed_on);
    }

    #[Test]
    public function a_completion_date_before_the_start_date_is_rejected(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);

        app(TenantContext::class)->set($school);

        $this->expectException(QueryException::class);

        DB::transaction(function () use ($employee): void {
            EmployeeQualification::query()->create([
                'school_id' => $employee->school_id,
                'employee_id' => $employee->id,
                'qualification_type' => 'bachelors',
                'qualification_name' => 'Invalid Range Degree',
                'institution' => 'Some University',
                'starts_on' => '2020-06-01',
                'completed_on' => '2019-06-01',
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
            EmployeeQualification::query()->create([
                'school_id' => $schoolB->id,
                'employee_id' => $employeeA->id,
                'qualification_type' => 'bachelors',
                'qualification_name' => 'Rogue Degree',
                'institution' => 'Some University',
            ]);
        });
    }

    #[Test]
    public function service_add_ignores_caller_supplied_school_and_employee_id(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $otherEmployee = $this->createEmployee($school);

        $qualification = app(EmployeeQualificationService::class)->add($employee, [
            'qualification_type' => 'bachelors',
            'qualification_name' => 'Bachelor of Science',
            'institution' => 'Some University',
            'employee_id' => $otherEmployee->id,
        ], $this->fullHrActor($school));

        $this->assertSame($employee->id, $qualification->employee_id, 'A caller-supplied employee_id in the attributes array must never override the authoritative Employee argument.');
    }

    #[Test]
    public function service_add_ignores_caller_supplied_verification_status(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);

        $qualification = app(EmployeeQualificationService::class)->add($employee, [
            'qualification_type' => 'bachelors',
            'qualification_name' => 'Bachelor of Science',
            'institution' => 'Some University',
            'verification_status' => 'verified',
            'verified_at' => now(),
        ], $this->fullHrActor($school));

        $this->assertSame('unverified', $qualification->verification_status, 'verification_status must never be settable through add() -- only verify()/reject() may set it.');
        $this->assertNull($qualification->verified_at);
    }

    #[Test]
    public function updating_a_qualification_belonging_to_a_different_employee_is_rejected(): void
    {
        $school = $this->createSchool();
        $employeeA = $this->createEmployee($school);
        $employeeB = $this->createEmployee($school);
        $qualificationB = $this->createEmployeeQualification($employeeB);

        $this->expectException(EmployeeOwnershipMismatchException::class);

        app(EmployeeQualificationService::class)->update($employeeA, $qualificationB, ['grade_or_result' => 'Hacked'], $this->fullHrActor($school));
    }

    #[Test]
    public function removing_a_qualification_belonging_to_a_different_employee_is_rejected(): void
    {
        $school = $this->createSchool();
        $employeeA = $this->createEmployee($school);
        $employeeB = $this->createEmployee($school);
        $qualificationB = $this->createEmployeeQualification($employeeB);

        $this->expectException(EmployeeOwnershipMismatchException::class);

        app(EmployeeQualificationService::class)->remove($employeeA, $qualificationB, $this->fullHrActor($school));
    }

    #[Test]
    public function update_via_service_persists_and_survives_a_mass_assignment_attempt_on_school_id(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $employeeA = $this->createEmployee($schoolA);
        $qualification = $this->createEmployeeQualification($employeeA, ['grade_or_result' => 'Original']);

        $updated = app(EmployeeQualificationService::class)->update($employeeA, $qualification, [
            'grade_or_result' => 'Updated',
            'school_id' => $schoolB->id,
        ], $this->fullHrActor($schoolA));

        $this->assertSame('Updated', $updated->grade_or_result);
        $this->assertSame($schoolA->id, $updated->school_id, 'A caller-supplied school_id in the attributes array must never move a record to a different School.');
    }

    #[Test]
    public function verify_sets_verified_status_and_a_verified_at_timestamp(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $qualification = $this->createEmployeeQualification($employee);

        $verified = app(EmployeeQualificationService::class)->verify($employee, $qualification, $this->fullHrActor($school));

        $this->assertSame('verified', $verified->verification_status);
        $this->assertNotNull($verified->verified_at);
    }

    #[Test]
    public function reject_sets_rejected_status_with_no_verified_at(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $qualification = $this->createEmployeeQualification($employee);

        $rejected = app(EmployeeQualificationService::class)->reject($employee, $qualification, $this->fullHrActor($school));

        $this->assertSame('rejected', $rejected->verification_status);
        $this->assertNull($rejected->verified_at);
    }

    #[Test]
    public function verify_on_a_qualification_belonging_to_a_different_employee_is_rejected(): void
    {
        $school = $this->createSchool();
        $employeeA = $this->createEmployee($school);
        $employeeB = $this->createEmployee($school);
        $qualificationB = $this->createEmployeeQualification($employeeB);

        $this->expectException(EmployeeOwnershipMismatchException::class);

        app(EmployeeQualificationService::class)->verify($employeeA, $qualificationB, $this->fullHrActor($school));
    }

    #[Test]
    public function a_material_edit_to_a_verified_qualification_resets_it_to_unverified(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $qualification = $this->createEmployeeQualification($employee);
        $service = app(EmployeeQualificationService::class);
        $actor = $this->fullHrActor($school);
        $service->verify($employee, $qualification, $actor);

        $edited = $service->update($employee, $qualification, ['grade_or_result' => 'Corrected Grade'], $actor);

        $this->assertSame('unverified', $edited->verification_status, 'A material edit to a verified record must reset verification, not silently keep it verified.');
        $this->assertNull($edited->verified_at);
    }

    #[Test]
    public function a_material_edit_to_a_rejected_qualification_resets_it_to_unverified(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $qualification = $this->createEmployeeQualification($employee);
        $service = app(EmployeeQualificationService::class);
        $actor = $this->fullHrActor($school);
        $service->reject($employee, $qualification, $actor);

        $edited = $service->update($employee, $qualification, ['grade_or_result' => 'Corrected Grade'], $actor);

        $this->assertSame('unverified', $edited->verification_status);
    }

    #[Test]
    public function an_update_that_supplies_no_field_changes_does_not_disturb_verified_status(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $qualification = $this->createEmployeeQualification($employee);
        $service = app(EmployeeQualificationService::class);
        $actor = $this->fullHrActor($school);
        $service->verify($employee, $qualification, $actor);

        $unchanged = $service->update($employee, $qualification, [], $actor);

        $this->assertSame('verified', $unchanged->verification_status);
    }

    #[Test]
    public function update_cannot_directly_set_verification_status(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $qualification = $this->createEmployeeQualification($employee);

        $updated = app(EmployeeQualificationService::class)->update($employee, $qualification, [
            'verification_status' => 'verified',
            'verified_at' => now(),
        ], $this->fullHrActor($school));

        $this->assertSame('unverified', $updated->verification_status, 'verification_status must never be settable through update() -- only verify()/reject() may set it.');
    }

    #[Test]
    public function audit_metadata_contains_no_personal_or_academic_field_values(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);

        $qualification = app(EmployeeQualificationService::class)->add($employee, [
            'qualification_type' => 'bachelors',
            'qualification_name' => 'Bachelor of Science',
            'institution' => 'Confidential University',
            'grade_or_result' => 'Distinction',
        ], $this->fullHrActor($school));

        app(TenantContext::class)->set($school);
        $event = SchoolAuditEvent::query()
            ->where('event_type', 'hr.qualification.created')
            ->orderByDesc('id')
            ->first();

        $this->assertNotNull($event);
        $this->assertSame($qualification->id, $event->metadata['qualificationId']);
        $this->assertArrayNotHasKey('institution', $event->metadata);
        $this->assertArrayNotHasKey('qualification_name', $event->metadata);
        $this->assertArrayNotHasKey('grade_or_result', $event->metadata);
    }
}
