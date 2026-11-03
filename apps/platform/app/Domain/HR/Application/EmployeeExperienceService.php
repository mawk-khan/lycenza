<?php

namespace App\Domain\HR\Application;

use App\Domain\HR\Application\Exceptions\EmployeeOwnershipMismatchException;
use App\Domain\HR\Application\Profile\ArchivesProfileRecords;
use App\Domain\HR\Application\Profile\ProfileRecordArchiveReason;
use App\Domain\HR\Infrastructure\Employee;
use App\Domain\HR\Infrastructure\EmployeeExperience;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Authorization\AuthorizesCapability;
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
    use ArchivesProfileRecords, AuthorizesCapability;

    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly TenantContext $context,
        private readonly HrSelfAdministrationGuard $selfAdministration,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function add(Employee $employee, array $attributes, User $actor): EmployeeExperience
    {
        $this->authorizeCapabilityFor($actor, 'hr.employees.qualifications.manage', $employee->school);
        unset($attributes['school_id'], $attributes['employee_id']);

        return $this->context->withSchool($employee->school, function () use ($employee, $attributes, $actor) {
            return DB::transaction(function () use ($employee, $attributes, $actor) {
                // HR.2A (S-3): nobody administers their own HR record.
                $this->selfAdministration->refuseOwnEmployee($actor, $employee->id, 'profile.experience');
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
    public function update(Employee $employee, EmployeeExperience $experience, array $attributes, User $actor): EmployeeExperience
    {
        $this->authorizeCapabilityFor($actor, 'hr.employees.qualifications.manage', $employee->school);
        $this->assertOwnership($employee, $experience);
        $this->assertLiveRecord($experience);
        unset($attributes['school_id'], $attributes['employee_id']);

        return $this->context->withSchool($employee->school, function () use ($employee, $experience, $attributes, $actor) {
            return DB::transaction(function () use ($employee, $experience, $attributes, $actor) {
                // HR.2A (S-3): nobody administers their own HR record.
                $this->selfAdministration->refuseOwnEmployee($actor, $employee->id, 'profile.experience');
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

    /**
     * HR.2A (S-16, OD-HR-13): "remove" ARCHIVES the record with a closed
     * reason -- frozen evidence, never deleted (ArchivesProfileRecords).
     */
    public function remove(Employee $employee, EmployeeExperience $experience, User $actor, ProfileRecordArchiveReason $reason = ProfileRecordArchiveReason::NO_LONGER_CURRENT): void
    {
        $this->authorizeCapabilityFor($actor, 'hr.employees.qualifications.manage', $employee->school);
        $this->assertOwnership($employee, $experience);

        $this->archiveProfileRecord($employee, $experience, $reason, $actor, 'hr.experience.archived', ['experienceId' => $experience->id]);
    }

    private function assertOwnership(Employee $employee, EmployeeExperience $experience): void
    {
        if ($experience->employee_id !== $employee->id) {
            throw new EmployeeOwnershipMismatchException($experience->id, $employee->id, $experience->employee_id);
        }
    }
}
