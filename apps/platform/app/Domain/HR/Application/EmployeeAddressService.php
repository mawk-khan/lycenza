<?php

namespace App\Domain\HR\Application;

use App\Domain\HR\Application\Exceptions\EmployeeOwnershipMismatchException;
use App\Domain\HR\Infrastructure\Employee;
use App\Domain\HR\Infrastructure\EmployeeAddress;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * The only sanctioned write path for an Employee's Restricted-tier
 * addresses (docs/modules/HR.md entity model: `EmployeeAddress` is
 * 1:N). Every method takes the authoritative `Employee $employee` the
 * caller already resolved from trusted context -- `school_id`/
 * `employee_id` are never accepted from caller-supplied attributes
 * (rule 21), and `update()`/`remove()` re-verify the target address
 * actually belongs to that Employee before touching it
 * (`EmployeeOwnershipMismatchException` otherwise -- rules 19/24's IDOR
 * protection applied to a child record instead of a route parameter).
 */
class EmployeeAddressService
{
    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly TenantContext $context,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function add(Employee $employee, array $attributes, ?User $actor = null): EmployeeAddress
    {
        unset($attributes['school_id'], $attributes['employee_id']);

        return $this->context->withSchool($employee->school, function () use ($employee, $attributes, $actor) {
            return DB::transaction(function () use ($employee, $attributes, $actor) {
                $address = EmployeeAddress::query()->create([
                    ...$attributes,
                    'employee_id' => $employee->id,
                    'school_id' => $employee->school_id,
                ]);

                $this->audit->school($employee->school, 'employee.address.created', actor: $actor, subject: $address, metadata: [
                    'employeeId' => $employee->id,
                    'addressType' => $address->address_type,
                ]);

                return $address;
            });
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(Employee $employee, EmployeeAddress $address, array $attributes, ?User $actor = null): EmployeeAddress
    {
        $this->assertOwnership($employee, $address);
        unset($attributes['school_id'], $attributes['employee_id']);

        return $this->context->withSchool($employee->school, function () use ($employee, $address, $attributes, $actor) {
            return DB::transaction(function () use ($employee, $address, $attributes, $actor) {
                $address->update($attributes);

                $this->audit->school($employee->school, 'employee.address.updated', actor: $actor, subject: $address, metadata: [
                    'employeeId' => $employee->id,
                    'fields' => array_keys($attributes),
                ]);

                return $address->fresh();
            });
        });
    }

    public function remove(Employee $employee, EmployeeAddress $address, ?User $actor = null): void
    {
        $this->assertOwnership($employee, $address);

        $this->context->withSchool($employee->school, function () use ($employee, $address, $actor) {
            DB::transaction(function () use ($employee, $address, $actor) {
                $addressId = $address->id;
                $addressType = $address->address_type;
                $address->delete();

                $this->audit->school($employee->school, 'employee.address.removed', actor: $actor, metadata: [
                    'employeeId' => $employee->id,
                    'addressId' => $addressId,
                    'addressType' => $addressType,
                ]);
            });
        });
    }

    private function assertOwnership(Employee $employee, EmployeeAddress $address): void
    {
        if ($address->employee_id !== $employee->id) {
            throw new EmployeeOwnershipMismatchException($address->id, $employee->id, $address->employee_id);
        }
    }
}
