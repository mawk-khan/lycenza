<?php

namespace Tests\Feature\HR;

use App\Domain\HR\Application\EmployeeEmergencyContactService;
use App\Domain\HR\Application\Exceptions\EmployeeOwnershipMismatchException;
use App\Domain\HR\Infrastructure\EmployeeEmergencyContact;
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
 * Phase 8A.2: proves the Employee's 1:N Restricted-tier emergency
 * contacts -- UUIDv7 identity, School/Employee ownership, the
 * at-most-one-primary invariant, and
 * EmployeeEmergencyContactService's ownership-checked write path
 * including the transactional demote-then-promote primary switch.
 */
class EmployeeEmergencyContactTest extends TestCase
{
    use CreatesTenancyFixtures;

    #[Test]
    public function contact_id_is_a_real_uuidv7(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $contact = $this->createEmployeeEmergencyContact($employee);

        $this->assertInstanceOf(UuidV7::class, Uuid::fromString($contact->id));
    }

    #[Test]
    public function an_employee_may_have_multiple_emergency_contacts(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $this->createEmployeeEmergencyContact($employee, ['name' => 'Contact One']);
        $this->createEmployeeEmergencyContact($employee, ['name' => 'Contact Two']);

        app(TenantContext::class)->set($school);

        $this->assertCount(2, $employee->emergencyContacts()->get());
    }

    #[Test]
    public function a_contact_is_not_primary_by_default(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $contact = $this->createEmployeeEmergencyContact($employee);

        $this->assertFalse($contact->is_primary);
    }

    #[Test]
    public function a_second_primary_contact_for_the_same_employee_is_rejected_by_the_database(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $this->createEmployeeEmergencyContact($employee, ['is_primary' => true]);

        app(TenantContext::class)->set($school);

        $this->expectException(UniqueConstraintViolationException::class);

        DB::transaction(function () use ($employee): void {
            EmployeeEmergencyContact::query()->create([
                'school_id' => $employee->school_id,
                'employee_id' => $employee->id,
                'name' => 'Second Primary Attempt',
                'phone' => '1234567890',
                'is_primary' => true,
            ]);
        });
    }

    #[Test]
    public function set_primary_demotes_the_previous_primary_and_promotes_the_new_one(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $original = $this->createEmployeeEmergencyContact($employee, ['is_primary' => true]);
        $replacement = $this->createEmployeeEmergencyContact($employee, ['is_primary' => false]);

        app(EmployeeEmergencyContactService::class)->setPrimary($employee, $replacement, $this->fullHrActor($school));

        app(TenantContext::class)->set($school);
        $this->assertFalse($original->fresh()->is_primary);
        $this->assertTrue($replacement->fresh()->is_primary);
        $this->assertSame(1, EmployeeEmergencyContact::query()->where('employee_id', $employee->id)->where('is_primary', true)->count());
    }

    #[Test]
    public function service_add_ignores_a_caller_supplied_is_primary_true(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);

        $contact = app(EmployeeEmergencyContactService::class)->add($employee, [
            'name' => 'Attempted Primary',
            'phone' => '5551234567',
            'is_primary' => true,
        ], $this->fullHrActor($school));

        $this->assertFalse($contact->is_primary, 'add() must never let a caller create an already-primary contact -- setPrimary() is the sole promotion path.');
    }

    #[Test]
    public function service_add_ignores_caller_supplied_school_and_employee_id(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $otherEmployee = $this->createEmployee($school);

        $contact = app(EmployeeEmergencyContactService::class)->add($employee, [
            'name' => 'Real Contact',
            'phone' => '5551234567',
            'employee_id' => $otherEmployee->id,
        ], $this->fullHrActor($school));

        $this->assertSame($employee->id, $contact->employee_id);
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
            EmployeeEmergencyContact::query()->create([
                'school_id' => $schoolB->id,
                'employee_id' => $employeeA->id,
                'name' => 'Rogue Contact',
                'phone' => '0000000000',
            ]);
        });
    }

    #[Test]
    public function updating_a_contact_belonging_to_a_different_employee_is_rejected(): void
    {
        $school = $this->createSchool();
        $employeeA = $this->createEmployee($school);
        $employeeB = $this->createEmployee($school);
        $contactB = $this->createEmployeeEmergencyContact($employeeB);

        $this->expectException(EmployeeOwnershipMismatchException::class);

        app(EmployeeEmergencyContactService::class)->update($employeeA, $contactB, ['name' => 'Hacked Name'], $this->fullHrActor($school));
    }

    #[Test]
    public function removing_a_contact_belonging_to_a_different_employee_is_rejected(): void
    {
        $school = $this->createSchool();
        $employeeA = $this->createEmployee($school);
        $employeeB = $this->createEmployee($school);
        $contactB = $this->createEmployeeEmergencyContact($employeeB);

        $this->expectException(EmployeeOwnershipMismatchException::class);

        app(EmployeeEmergencyContactService::class)->remove($employeeA, $contactB, $this->fullHrActor($school));
    }

    #[Test]
    public function promoting_a_contact_belonging_to_a_different_employee_is_rejected(): void
    {
        $school = $this->createSchool();
        $employeeA = $this->createEmployee($school);
        $employeeB = $this->createEmployee($school);
        $contactB = $this->createEmployeeEmergencyContact($employeeB);

        $this->expectException(EmployeeOwnershipMismatchException::class);

        app(EmployeeEmergencyContactService::class)->setPrimary($employeeA, $contactB, $this->fullHrActor($school));
    }
}
