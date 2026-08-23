<?php

namespace App\Domain\HR\Application;

use App\Domain\HR\Application\Exceptions\EmployeeOwnershipMismatchException;
use App\Domain\HR\Infrastructure\Employee;
use App\Domain\HR\Infrastructure\EmployeeExperience;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * The only sanctioned write path for an Employee's Restricted-tier
 * external professional experience (docs/modules/HR.md entity model:
 * `EmployeeExperience` is 1:N). Same ownership-verification shape as
 * App\Domain\HR\Application\EmployeeAddressService -- deliberately no
 * verification workflow (see EmployeeExperience's docblock for why),
 * so this service is kept intentionally minimal: add/update/remove
 * only.
 */
class EmployeeExperienceService
{
    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly TenantContext $context,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function add(Employee $employee, array $attributes, ?User $actor = null): EmployeeExperience
    {
        unset($attributes['school_id'], $attributes['employee_id']);

        return $this->context->withSchool($employee->school, function () use ($employee, $attributes, $actor) {
            return DB::transaction(function () use ($employee, $attributes, $actor) {
                $experience = EmployeeExperience::query()->create([
                    ...$attributes,
                    'employee_id' => $employee->id,
                    'school_id' => $employee->school_id,
                ]);

                $this->audit->school($employee->school, 'hr.experience.created', actor: $actor, subject: $experience, metadata: [
                    'employeeId' => $employee->id,
                    'experienceId' => $experience->id,
                ]);

                return $experience;
            });
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(Employee $employee, EmployeeExperience $experience, array $attributes, ?User $actor = null): EmployeeExperience
    {
        $this->assertOwnership($employee, $experience);
        unset($attributes['school_id'], $attributes['employee_id']);

        return $this->context->withSchool($employee->school, function () use ($employee, $experience, $attributes, $actor) {
            return DB::transaction(function () use ($employee, $experience, $attributes, $actor) {
                $experience->update($attributes);

                $this->audit->school($employee->school, 'hr.experience.updated', actor: $actor, subject: $experience, metadata: [
                    'employeeId' => $employee->id,
                    'experienceId' => $experience->id,
                    'fields' => array_keys($attributes),
                ]);

                return $experience->fresh();
            });
        });
    }

    public function remove(Employee $employee, EmployeeExperience $experience, ?User $actor = null): void
    {
        $this->assertOwnership($employee, $experience);

        $this->context->withSchool($employee->school, function () use ($employee, $experience, $actor) {
            DB::transaction(function () use ($employee, $experience, $actor) {
                $experienceId = $experience->id;
                $experience->delete();

                $this->audit->school($employee->school, 'hr.experience.removed', actor: $actor, metadata: [
                    'employeeId' => $employee->id,
                    'experienceId' => $experienceId,
                ]);
            });
        });
    }

    private function assertOwnership(Employee $employee, EmployeeExperience $experience): void
    {
        if ($experience->employee_id !== $employee->id) {
            throw new EmployeeOwnershipMismatchException($experience->id, $employee->id, $experience->employee_id);
        }
    }
}
