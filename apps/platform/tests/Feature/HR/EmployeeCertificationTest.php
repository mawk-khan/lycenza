<?php

namespace Tests\Feature\HR;

use App\Domain\HR\Application\EmployeeCertificationService;
use App\Domain\HR\Application\Exceptions\EmployeeOwnershipMismatchException;
use App\Domain\HR\Infrastructure\EmployeeCertification;
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
 * Phase 8A.6: proves the Employee's 1:N Restricted-tier professional
 * certifications/licences -- UUIDv7 identity, School/Employee
 * ownership, expiry-date validity, non-expiring representability,
 * credential-number non-uniqueness, and verification transitions
 * including the material-edit reset rule.
 */
class EmployeeCertificationTest extends TestCase
{
    use CreatesTenancyFixtures;

    #[Test]
    public function certification_id_is_a_real_uuidv7(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $certification = $this->createEmployeeCertification($employee);

        $this->assertInstanceOf(UuidV7::class, Uuid::fromString($certification->id));
    }

    #[Test]
    public function an_employee_may_have_multiple_certifications(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $this->createEmployeeCertification($employee);
        $this->createEmployeeCertification($employee);

        app(TenantContext::class)->set($school);

        $this->assertCount(2, $employee->certifications()->get());
    }

    #[Test]
    public function a_non_expiring_certification_is_valid(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $certification = $this->createEmployeeCertification($employee, ['expires_on' => null]);

        $this->assertNull($certification->expires_on);
    }

    #[Test]
    public function expired_status_is_derived_from_expires_on_not_stored(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $certification = $this->createEmployeeCertification($employee, [
            'issued_on' => now()->subYears(2),
            'expires_on' => now()->subDays(5),
        ]);

        $this->assertTrue($certification->expires_on->isPast(), 'Expiry must be derivable from expires_on alone, with no persistent is_expired column.');
    }

    #[Test]
    public function an_expiry_date_before_the_issue_date_is_rejected(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);

        app(TenantContext::class)->set($school);

        $this->expectException(QueryException::class);

        DB::transaction(function () use ($employee): void {
            EmployeeCertification::query()->create([
                'school_id' => $employee->school_id,
                'employee_id' => $employee->id,
                'name' => 'Invalid Range Certificate',
                'issuer' => 'Some Issuer',
                'issued_on' => '2022-06-01',
                'expires_on' => '2021-06-01',
            ]);
        });
    }

    #[Test]
    public function credential_number_is_not_unique_and_may_repeat_across_certifications(): void
    {
        $school = $this->createSchool();
        $employeeA = $this->createEmployee($school);
        $employeeB = $this->createEmployee($school);

        $certA = $this->createEmployeeCertification($employeeA, ['credential_number' => 'DUPLICATE-123']);
        $certB = $this->createEmployeeCertification($employeeB, ['credential_number' => 'DUPLICATE-123']);

        $this->assertSame('DUPLICATE-123', $certA->credential_number);
        $this->assertSame('DUPLICATE-123', $certB->credential_number);
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
            EmployeeCertification::query()->create([
                'school_id' => $schoolB->id,
                'employee_id' => $employeeA->id,
                'name' => 'Rogue Certificate',
                'issuer' => 'Rogue Issuer',
            ]);
        });
    }

    #[Test]
    public function service_add_ignores_caller_supplied_school_and_employee_id(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $otherEmployee = $this->createEmployee($school);

        $certification = app(EmployeeCertificationService::class)->add($employee, [
            'name' => 'First Aid Certificate',
            'issuer' => 'Red Cross',
            'employee_id' => $otherEmployee->id,
        ]);

        $this->assertSame($employee->id, $certification->employee_id, 'A caller-supplied employee_id in the attributes array must never override the authoritative Employee argument.');
    }

    #[Test]
    public function service_add_ignores_caller_supplied_verification_status(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);

        $certification = app(EmployeeCertificationService::class)->add($employee, [
            'name' => 'First Aid Certificate',
            'issuer' => 'Red Cross',
            'verification_status' => 'verified',
            'verified_at' => now(),
        ]);

        $this->assertSame('unverified', $certification->verification_status, 'verification_status must never be settable through add() -- only verify()/reject() may set it.');
        $this->assertNull($certification->verified_at);
    }

    #[Test]
    public function updating_a_certification_belonging_to_a_different_employee_is_rejected(): void
    {
        $school = $this->createSchool();
        $employeeA = $this->createEmployee($school);
        $employeeB = $this->createEmployee($school);
        $certificationB = $this->createEmployeeCertification($employeeB);

        $this->expectException(EmployeeOwnershipMismatchException::class);

        app(EmployeeCertificationService::class)->update($employeeA, $certificationB, ['issuer' => 'Hacked Issuer']);
    }

    #[Test]
    public function removing_a_certification_belonging_to_a_different_employee_is_rejected(): void
    {
        $school = $this->createSchool();
        $employeeA = $this->createEmployee($school);
        $employeeB = $this->createEmployee($school);
        $certificationB = $this->createEmployeeCertification($employeeB);

        $this->expectException(EmployeeOwnershipMismatchException::class);

        app(EmployeeCertificationService::class)->remove($employeeA, $certificationB);
    }

    #[Test]
    public function verify_sets_verified_status_and_a_verified_at_timestamp(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $certification = $this->createEmployeeCertification($employee);

        $verified = app(EmployeeCertificationService::class)->verify($employee, $certification);

        $this->assertSame('verified', $verified->verification_status);
        $this->assertNotNull($verified->verified_at);
    }

    #[Test]
    public function reject_sets_rejected_status_with_no_verified_at(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $certification = $this->createEmployeeCertification($employee);

        $rejected = app(EmployeeCertificationService::class)->reject($employee, $certification);

        $this->assertSame('rejected', $rejected->verification_status);
        $this->assertNull($rejected->verified_at);
    }

    #[Test]
    public function a_material_edit_to_a_verified_certification_resets_it_to_unverified(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $certification = $this->createEmployeeCertification($employee);
        $service = app(EmployeeCertificationService::class);
        $service->verify($employee, $certification);

        $edited = $service->update($employee, $certification, ['issuer' => 'A Different Issuer']);

        $this->assertSame('unverified', $edited->verification_status, 'A caller must not be able to verify one certificate and silently transform it into another while keeping verified status.');
        $this->assertNull($edited->verified_at);
    }

    #[Test]
    public function changing_the_credential_number_of_a_verified_certification_resets_verification(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $certification = $this->createEmployeeCertification($employee, ['credential_number' => 'ORIGINAL-1']);
        $service = app(EmployeeCertificationService::class);
        $service->verify($employee, $certification);

        $edited = $service->update($employee, $certification, ['credential_number' => 'CHANGED-2']);

        $this->assertSame('unverified', $edited->verification_status);
        $this->assertSame('CHANGED-2', $edited->credential_number);
    }

    #[Test]
    public function an_update_that_supplies_no_field_changes_does_not_disturb_verified_status(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $certification = $this->createEmployeeCertification($employee);
        $service = app(EmployeeCertificationService::class);
        $service->verify($employee, $certification);

        $unchanged = $service->update($employee, $certification, []);

        $this->assertSame('verified', $unchanged->verification_status);
    }

    #[Test]
    public function audit_metadata_contains_no_credential_number_or_issuer_values(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);

        $certification = app(EmployeeCertificationService::class)->add($employee, [
            'name' => 'Confidential Certificate',
            'issuer' => 'Confidential Issuer',
            'credential_number' => 'SECRET-999',
        ]);

        app(TenantContext::class)->set($school);
        $event = SchoolAuditEvent::query()
            ->where('event_type', 'hr.certification.created')
            ->orderByDesc('id')
            ->first();

        $this->assertNotNull($event);
        $this->assertSame($certification->id, $event->metadata['certificationId']);
        $this->assertArrayNotHasKey('credential_number', $event->metadata);
        $this->assertArrayNotHasKey('issuer', $event->metadata);
        $this->assertArrayNotHasKey('name', $event->metadata);
    }
}
