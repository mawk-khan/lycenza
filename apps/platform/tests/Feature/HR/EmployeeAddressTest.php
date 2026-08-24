<?php

namespace Tests\Feature\HR;

use App\Domain\HR\Application\EmployeeAddressService;
use App\Domain\HR\Application\Exceptions\EmployeeOwnershipMismatchException;
use App\Domain\HR\Infrastructure\EmployeeAddress;
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
 * Phase 8A.2: proves the Employee's 1:N Restricted-tier address book --
 * UUIDv7 identity, School/Employee ownership, the "at most one
 * current/permanent/mailing but many other" invariant, and
 * EmployeeAddressService's ownership-checked write path.
 */
class EmployeeAddressTest extends TestCase
{
    use CreatesTenancyFixtures;

    #[Test]
    public function address_id_is_a_real_uuidv7(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $address = $this->createEmployeeAddress($employee);

        $this->assertInstanceOf(UuidV7::class, Uuid::fromString($address->id));
    }

    #[Test]
    public function an_employee_may_have_multiple_addresses(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $this->createEmployeeAddress($employee, ['address_type' => 'current']);
        $this->createEmployeeAddress($employee, ['address_type' => 'permanent']);
        $this->createEmployeeAddress($employee, ['address_type' => 'other']);

        app(TenantContext::class)->set($school);

        $this->assertCount(3, $employee->addresses()->get());
    }

    #[Test]
    public function each_valid_address_type_can_be_created(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);

        foreach (EmployeeAddress::TYPES as $type) {
            $address = $this->createEmployeeAddress($employee, ['address_type' => $type]);
            $this->assertSame($type, $address->address_type);
        }
    }

    #[Test]
    public function ownership_is_preserved_when_fetched_through_the_employee(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $address = $this->createEmployeeAddress($employee);

        app(TenantContext::class)->set($school);

        $this->assertSame($employee->id, $address->fresh()->employee_id);
        $this->assertTrue($employee->addresses()->get()->contains('id', $address->id));
    }

    #[Test]
    public function a_second_current_address_for_the_same_employee_is_rejected(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $this->createEmployeeAddress($employee, ['address_type' => 'current']);

        app(TenantContext::class)->set($school);

        $this->expectException(UniqueConstraintViolationException::class);

        DB::transaction(function () use ($employee): void {
            EmployeeAddress::query()->create([
                'school_id' => $employee->school_id,
                'employee_id' => $employee->id,
                'address_type' => 'current',
                'address_line1' => 'Second current address',
            ]);
        });
    }

    #[Test]
    public function a_second_permanent_address_for_the_same_employee_is_rejected(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $this->createEmployeeAddress($employee, ['address_type' => 'permanent']);

        app(TenantContext::class)->set($school);

        $this->expectException(UniqueConstraintViolationException::class);

        DB::transaction(function () use ($employee): void {
            EmployeeAddress::query()->create([
                'school_id' => $employee->school_id,
                'employee_id' => $employee->id,
                'address_type' => 'permanent',
                'address_line1' => 'Second permanent address',
            ]);
        });
    }

    #[Test]
    public function multiple_other_addresses_are_allowed(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $this->createEmployeeAddress($employee, ['address_type' => 'other']);
        $this->createEmployeeAddress($employee, ['address_type' => 'other']);
        $this->createEmployeeAddress($employee, ['address_type' => 'other']);

        app(TenantContext::class)->set($school);

        $this->assertSame(3, EmployeeAddress::query()->where('employee_id', $employee->id)->where('address_type', 'other')->count());
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
            EmployeeAddress::query()->create([
                'school_id' => $schoolB->id,
                'employee_id' => $employeeA->id,
                'address_type' => 'current',
                'address_line1' => 'Rogue address',
            ]);
        });
    }

    #[Test]
    public function service_add_ignores_caller_supplied_school_and_employee_id(): void
    {
        $school = $this->createSchool();
        $employee = $this->createEmployee($school);
        $otherEmployee = $this->createEmployee($school);

        $address = app(EmployeeAddressService::class)->add($employee, [
            'address_type' => 'current',
            'address_line1' => '221B Baker Street',
            'employee_id' => $otherEmployee->id,
        ], $this->fullHrActor($school));

        $this->assertSame($employee->id, $address->employee_id, 'A caller-supplied employee_id in the attributes array must never override the authoritative Employee argument.');
    }

    #[Test]
    public function updating_an_address_belonging_to_a_different_employee_is_rejected(): void
    {
        $school = $this->createSchool();
        $employeeA = $this->createEmployee($school);
        $employeeB = $this->createEmployee($school);
        $addressB = $this->createEmployeeAddress($employeeB, ['address_type' => 'other']);

        $this->expectException(EmployeeOwnershipMismatchException::class);

        app(EmployeeAddressService::class)->update($employeeA, $addressB, ['city' => 'Hacked City'], $this->fullHrActor($school));
    }

    #[Test]
    public function removing_an_address_belonging_to_a_different_employee_is_rejected(): void
    {
        $school = $this->createSchool();
        $employeeA = $this->createEmployee($school);
        $employeeB = $this->createEmployee($school);
        $addressB = $this->createEmployeeAddress($employeeB, ['address_type' => 'other']);

        $this->expectException(EmployeeOwnershipMismatchException::class);

        app(EmployeeAddressService::class)->remove($employeeA, $addressB, $this->fullHrActor($school));
    }

    #[Test]
    public function update_via_service_persists_and_survives_a_mass_assignment_attempt_on_school_id(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $employeeA = $this->createEmployee($schoolA);
        $address = $this->createEmployeeAddress($employeeA, ['address_type' => 'other', 'city' => 'Original City']);

        $updated = app(EmployeeAddressService::class)->update($employeeA, $address, [
            'city' => 'Updated City',
            'school_id' => $schoolB->id,
        ], $this->fullHrActor($schoolA));

        $this->assertSame('Updated City', $updated->city);
        $this->assertSame($schoolA->id, $updated->school_id, 'A caller-supplied school_id in the attributes array must never move a record to a different School.');
    }
}
