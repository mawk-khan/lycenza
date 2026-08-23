<?php

namespace App\Domain\HR\Application;

use App\Domain\HR\Application\Exceptions\EmployeeOwnershipMismatchException;
use App\Domain\HR\Infrastructure\Employee;
use App\Domain\HR\Infrastructure\EmployeeEmergencyContact;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * The only sanctioned write path for an Employee's Restricted-tier
 * emergency contacts (docs/modules/HR.md entity model:
 * `EmployeeEmergencyContact` is 1:N). Same ownership-verification shape
 * as App\Domain\HR\Application\EmployeeAddressService.
 *
 * `is_primary` is never settable through `add()`/`update()` -- a new or
 * updated contact is always non-primary; `setPrimary()` is the sole
 * promotion path, deliberately kept separate so the demote-old/
 * promote-new invariant only has one call site to get right (mirrors
 * App\Domain\AcademicStructure\Application\AcademicYearService::activate()'s
 * exact demote-then-promote-in-one-transaction shape, with the
 * migration's partial unique index as the concurrency backstop).
 */
class EmployeeEmergencyContactService
{
    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly TenantContext $context,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function add(Employee $employee, array $attributes, ?User $actor = null): EmployeeEmergencyContact
    {
        unset($attributes['school_id'], $attributes['employee_id'], $attributes['is_primary']);

        return $this->context->withSchool($employee->school, function () use ($employee, $attributes, $actor) {
            return DB::transaction(function () use ($employee, $attributes, $actor) {
                $contact = EmployeeEmergencyContact::query()->create([
                    ...$attributes,
                    'employee_id' => $employee->id,
                    'school_id' => $employee->school_id,
                    'is_primary' => false,
                ]);

                $this->audit->school($employee->school, 'employee.emergency_contact.created', actor: $actor, subject: $contact, metadata: [
                    'employeeId' => $employee->id,
                    'contactId' => $contact->id,
                ]);

                return $contact;
            });
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(Employee $employee, EmployeeEmergencyContact $contact, array $attributes, ?User $actor = null): EmployeeEmergencyContact
    {
        $this->assertOwnership($employee, $contact);
        unset($attributes['school_id'], $attributes['employee_id'], $attributes['is_primary']);

        return $this->context->withSchool($employee->school, function () use ($employee, $contact, $attributes, $actor) {
            return DB::transaction(function () use ($employee, $contact, $attributes, $actor) {
                $contact->update($attributes);

                $this->audit->school($employee->school, 'employee.emergency_contact.updated', actor: $actor, subject: $contact, metadata: [
                    'employeeId' => $employee->id,
                    'contactId' => $contact->id,
                    'fields' => array_keys($attributes),
                ]);

                return $contact->fresh();
            });
        });
    }

    public function remove(Employee $employee, EmployeeEmergencyContact $contact, ?User $actor = null): void
    {
        $this->assertOwnership($employee, $contact);

        $this->context->withSchool($employee->school, function () use ($employee, $contact, $actor) {
            DB::transaction(function () use ($employee, $contact, $actor) {
                $contactId = $contact->id;
                $wasPrimary = $contact->is_primary;
                $contact->delete();

                $this->audit->school($employee->school, 'employee.emergency_contact.removed', actor: $actor, metadata: [
                    'employeeId' => $employee->id,
                    'contactId' => $contactId,
                    'wasPrimary' => $wasPrimary,
                ]);
            });
        });
    }

    /**
     * Demotes whatever contact is currently primary for this Employee
     * (if any) and promotes $contact, in one transaction -- never a
     * bare `update(['is_primary' => true])` on its own, which could
     * race a concurrent promotion of a different contact and briefly
     * (or, without the partial unique index, permanently) leave two
     * primaries.
     */
    public function setPrimary(Employee $employee, EmployeeEmergencyContact $contact, ?User $actor = null): EmployeeEmergencyContact
    {
        $this->assertOwnership($employee, $contact);

        return $this->context->withSchool($employee->school, function () use ($employee, $contact, $actor) {
            return DB::transaction(function () use ($employee, $contact, $actor) {
                EmployeeEmergencyContact::query()
                    ->where('employee_id', $employee->id)
                    ->where('is_primary', true)
                    ->where('id', '!=', $contact->id)
                    ->update(['is_primary' => false]);

                $contact->update(['is_primary' => true]);

                $this->audit->school($employee->school, 'employee.emergency_contact.primary_changed', actor: $actor, subject: $contact, metadata: [
                    'employeeId' => $employee->id,
                    'contactId' => $contact->id,
                ]);

                return $contact->fresh();
            });
        });
    }

    private function assertOwnership(Employee $employee, EmployeeEmergencyContact $contact): void
    {
        if ($contact->employee_id !== $employee->id) {
            throw new EmployeeOwnershipMismatchException($contact->id, $employee->id, $contact->employee_id);
        }
    }
}
