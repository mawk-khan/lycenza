<?php

namespace App\Domain\HR\Application;

use App\Domain\HR\Application\Exceptions\EmployeeOwnershipMismatchException;
use App\Domain\HR\Infrastructure\Employee;
use App\Domain\HR\Infrastructure\EmployeeQualification;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * The only sanctioned write path for an Employee's Restricted-tier
 * qualification history (docs/modules/HR.md entity model:
 * `EmployeeQualification` is 1:N). Same ownership-verification shape
 * as App\Domain\HR\Application\EmployeeAddressService: every method
 * takes the authoritative `Employee $employee` the caller already
 * resolved from trusted context, `school_id`/`employee_id` are never
 * accepted from caller-supplied attributes, and `update()`/`remove()`/
 * `verify()`/`reject()` re-verify the target Qualification actually
 * belongs to that Employee before touching it
 * (`EmployeeOwnershipMismatchException` otherwise).
 *
 * Verification: `verification_status`/`verified_at` are never
 * accepted through `add()`/`update()`'s attributes array -- `verify()`
 * and `reject()` are the only writers of those two columns.
 * Deliberately conservative material-edit rule: `update()` treats ANY
 * field change as material and, if the record was previously
 * `verified` or `rejected`, resets it to `unverified` (clearing
 * `verified_at`) as part of the same transaction -- a verified
 * qualification must never silently keep looking verified after its
 * institution/name/dates/grade are edited. No narrower "harmless
 * field" carve-out is defined; determining one is a judgment call
 * this checkpoint declines to make without an approved contract.
 */
class EmployeeQualificationService
{
    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly TenantContext $context,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function add(Employee $employee, array $attributes, ?User $actor = null): EmployeeQualification
    {
        unset($attributes['school_id'], $attributes['employee_id'], $attributes['verification_status'], $attributes['verified_at']);

        return $this->context->withSchool($employee->school, function () use ($employee, $attributes, $actor) {
            return DB::transaction(function () use ($employee, $attributes, $actor) {
                $qualification = EmployeeQualification::query()->create([
                    ...$attributes,
                    'employee_id' => $employee->id,
                    'school_id' => $employee->school_id,
                    'verification_status' => 'unverified',
                    'verified_at' => null,
                ]);

                $this->audit->school($employee->school, 'hr.qualification.created', actor: $actor, subject: $qualification, metadata: [
                    'employeeId' => $employee->id,
                    'qualificationId' => $qualification->id,
                ]);

                return $qualification;
            });
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(Employee $employee, EmployeeQualification $qualification, array $attributes, ?User $actor = null): EmployeeQualification
    {
        $this->assertOwnership($employee, $qualification);
        unset($attributes['school_id'], $attributes['employee_id'], $attributes['verification_status'], $attributes['verified_at']);

        return $this->context->withSchool($employee->school, function () use ($employee, $qualification, $attributes, $actor) {
            return DB::transaction(function () use ($employee, $qualification, $attributes, $actor) {
                $wasVerified = $qualification->verification_status !== 'unverified';

                if ($attributes !== [] && $wasVerified) {
                    $attributes['verification_status'] = 'unverified';
                    $attributes['verified_at'] = null;
                }

                $qualification->update($attributes);

                $this->audit->school($employee->school, 'hr.qualification.updated', actor: $actor, subject: $qualification, metadata: [
                    'employeeId' => $employee->id,
                    'qualificationId' => $qualification->id,
                    'fields' => array_keys($attributes),
                    'verificationReset' => $wasVerified && $attributes !== [],
                ]);

                return $qualification->fresh();
            });
        });
    }

    public function remove(Employee $employee, EmployeeQualification $qualification, ?User $actor = null): void
    {
        $this->assertOwnership($employee, $qualification);

        $this->context->withSchool($employee->school, function () use ($employee, $qualification, $actor) {
            DB::transaction(function () use ($employee, $qualification, $actor) {
                $qualificationId = $qualification->id;
                $qualification->delete();

                $this->audit->school($employee->school, 'hr.qualification.removed', actor: $actor, metadata: [
                    'employeeId' => $employee->id,
                    'qualificationId' => $qualificationId,
                ]);
            });
        });
    }

    public function verify(Employee $employee, EmployeeQualification $qualification, ?User $actor = null): EmployeeQualification
    {
        $this->assertOwnership($employee, $qualification);

        return $this->context->withSchool($employee->school, function () use ($employee, $qualification, $actor) {
            return DB::transaction(function () use ($employee, $qualification, $actor) {
                $qualification->update(['verification_status' => 'verified', 'verified_at' => now()]);

                $this->audit->school($employee->school, 'hr.qualification.verified', actor: $actor, subject: $qualification, metadata: [
                    'employeeId' => $employee->id,
                    'qualificationId' => $qualification->id,
                ]);

                return $qualification->fresh();
            });
        });
    }

    public function reject(Employee $employee, EmployeeQualification $qualification, ?User $actor = null): EmployeeQualification
    {
        $this->assertOwnership($employee, $qualification);

        return $this->context->withSchool($employee->school, function () use ($employee, $qualification, $actor) {
            return DB::transaction(function () use ($employee, $qualification, $actor) {
                $qualification->update(['verification_status' => 'rejected', 'verified_at' => null]);

                $this->audit->school($employee->school, 'hr.qualification.rejected', actor: $actor, subject: $qualification, metadata: [
                    'employeeId' => $employee->id,
                    'qualificationId' => $qualification->id,
                ]);

                return $qualification->fresh();
            });
        });
    }

    private function assertOwnership(Employee $employee, EmployeeQualification $qualification): void
    {
        if ($qualification->employee_id !== $employee->id) {
            throw new EmployeeOwnershipMismatchException($qualification->id, $employee->id, $qualification->employee_id);
        }
    }
}
