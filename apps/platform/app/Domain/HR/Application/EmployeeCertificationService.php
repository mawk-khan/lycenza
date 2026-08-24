<?php

namespace App\Domain\HR\Application;

use App\Domain\HR\Application\Exceptions\EmployeeOwnershipMismatchException;
use App\Domain\HR\Infrastructure\Employee;
use App\Domain\HR\Infrastructure\EmployeeCertification;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * The only sanctioned write path for an Employee's Restricted-tier
 * certifications/licences (docs/modules/HR.md entity model:
 * `EmployeeCertification` is 1:N). Identical shape and verification
 * reasoning to App\Domain\HR\Application\EmployeeQualificationService
 * -- see that class's docblock. In particular: a caller cannot verify
 * one certificate and then silently transform it (issuer,
 * credential_number, dates, name) into another while keeping
 * `verified` status -- `update()` resets to `unverified` whenever it
 * changes any field on a previously verified/rejected record.
 */
class EmployeeCertificationService
{
    use AuthorizesCapability;

    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly TenantContext $context,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function add(Employee $employee, array $attributes, User $actor): EmployeeCertification
    {
        $this->authorizeCapabilityFor($actor, 'hr.employees.qualifications.manage', $employee->school);
        unset($attributes['school_id'], $attributes['employee_id'], $attributes['verification_status'], $attributes['verified_at']);

        return $this->context->withSchool($employee->school, function () use ($employee, $attributes, $actor) {
            return DB::transaction(function () use ($employee, $attributes, $actor) {
                $certification = EmployeeCertification::query()->create([
                    ...$attributes,
                    'employee_id' => $employee->id,
                    'school_id' => $employee->school_id,
                    'verification_status' => 'unverified',
                    'verified_at' => null,
                ]);

                $this->audit->school($employee->school, 'hr.certification.created', actor: $actor, subject: $certification, metadata: [
                    'employeeId' => $employee->id,
                    'certificationId' => $certification->id,
                ]);

                return $certification;
            });
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(Employee $employee, EmployeeCertification $certification, array $attributes, User $actor): EmployeeCertification
    {
        $this->authorizeCapabilityFor($actor, 'hr.employees.qualifications.manage', $employee->school);
        $this->assertOwnership($employee, $certification);
        unset($attributes['school_id'], $attributes['employee_id'], $attributes['verification_status'], $attributes['verified_at']);

        return $this->context->withSchool($employee->school, function () use ($employee, $certification, $attributes, $actor) {
            return DB::transaction(function () use ($employee, $certification, $attributes, $actor) {
                $wasVerified = $certification->verification_status !== 'unverified';

                if ($attributes !== [] && $wasVerified) {
                    $attributes['verification_status'] = 'unverified';
                    $attributes['verified_at'] = null;
                }

                $certification->update($attributes);

                $this->audit->school($employee->school, 'hr.certification.updated', actor: $actor, subject: $certification, metadata: [
                    'employeeId' => $employee->id,
                    'certificationId' => $certification->id,
                    'fields' => array_keys($attributes),
                    'verificationReset' => $wasVerified && $attributes !== [],
                ]);

                return $certification->fresh();
            });
        });
    }

    public function remove(Employee $employee, EmployeeCertification $certification, User $actor): void
    {
        $this->authorizeCapabilityFor($actor, 'hr.employees.qualifications.manage', $employee->school);
        $this->assertOwnership($employee, $certification);

        $this->context->withSchool($employee->school, function () use ($employee, $certification, $actor) {
            DB::transaction(function () use ($employee, $certification, $actor) {
                $certificationId = $certification->id;
                $certification->delete();

                $this->audit->school($employee->school, 'hr.certification.removed', actor: $actor, metadata: [
                    'employeeId' => $employee->id,
                    'certificationId' => $certificationId,
                ]);
            });
        });
    }

    public function verify(Employee $employee, EmployeeCertification $certification, User $actor): EmployeeCertification
    {
        $this->authorizeCapabilityFor($actor, 'hr.employees.qualifications.manage', $employee->school);
        $this->assertOwnership($employee, $certification);

        return $this->context->withSchool($employee->school, function () use ($employee, $certification, $actor) {
            return DB::transaction(function () use ($employee, $certification, $actor) {
                $certification->update(['verification_status' => 'verified', 'verified_at' => now()]);

                $this->audit->school($employee->school, 'hr.certification.verified', actor: $actor, subject: $certification, metadata: [
                    'employeeId' => $employee->id,
                    'certificationId' => $certification->id,
                ]);

                return $certification->fresh();
            });
        });
    }

    public function reject(Employee $employee, EmployeeCertification $certification, User $actor): EmployeeCertification
    {
        $this->authorizeCapabilityFor($actor, 'hr.employees.qualifications.manage', $employee->school);
        $this->assertOwnership($employee, $certification);

        return $this->context->withSchool($employee->school, function () use ($employee, $certification, $actor) {
            return DB::transaction(function () use ($employee, $certification, $actor) {
                $certification->update(['verification_status' => 'rejected', 'verified_at' => null]);

                $this->audit->school($employee->school, 'hr.certification.rejected', actor: $actor, subject: $certification, metadata: [
                    'employeeId' => $employee->id,
                    'certificationId' => $certification->id,
                ]);

                return $certification->fresh();
            });
        });
    }

    private function assertOwnership(Employee $employee, EmployeeCertification $certification): void
    {
        if ($certification->employee_id !== $employee->id) {
            throw new EmployeeOwnershipMismatchException($certification->id, $employee->id, $certification->employee_id);
        }
    }
}
