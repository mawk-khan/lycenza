<?php

namespace App\Domain\HR\Application;

use App\Domain\HR\Application\Exceptions\EmployeeOwnershipMismatchException;
use App\Domain\HR\Application\Profile\ArchivesProfileRecords;
use App\Domain\HR\Application\Profile\ProfileRecordArchiveReason;
use App\Domain\HR\Infrastructure\Employee;
use App\Domain\HR\Infrastructure\EmployeeNote;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Tenancy\SchoolOperationalGuard;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Phase 8A closure correction ("EmployeeNote"). The only sanctioned
 * write path for an Employee's Restricted/Confidential HR-authored
 * notes -- mirrors EmployeeAddressService's shape exactly (`school_id`/
 * `employee_id` never accepted from caller-supplied attributes,
 * `update()`/`remove()` re-verify ownership via
 * `EmployeeOwnershipMismatchException`), gated by the
 * `hr.employees.notes.view`/`.manage` pair that was pre-registered at
 * 8A.10 and, until this correction, attached to no real feature.
 *
 * `author_user_id` is always the acting `$actor->id` -- a Note's
 * authorship is never caller-suppliable, unlike an address's fields.
 */
class EmployeeNoteService
{
    use ArchivesProfileRecords, AuthorizesCapability;

    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly TenantContext $context,
        private readonly HrSelfAdministrationGuard $selfAdministration,
        private readonly SchoolOperationalGuard $operational,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function add(Employee $employee, array $attributes, User $actor): EmployeeNote
    {
        $this->authorizeCapabilityFor($actor, 'hr.employees.notes.manage', $employee->school);

        unset($attributes['school_id'], $attributes['employee_id'], $attributes['author_user_id']);

        return $this->context->withSchool($employee->school, function () use ($employee, $attributes, $actor) {
            return DB::transaction(function () use ($employee, $attributes, $actor) {
                $this->operational->requireOperational($employee->school_id);
                // HR.2A (S-3): nobody administers their own HR record.
                $this->selfAdministration->refuseOwnEmployee($actor, $employee->id, 'profile.note');
                // HR.R1-C13 (GOV-6): the caller's fields pass through fill(); the owner's columns are stated here.
                $note = new EmployeeNote($attributes);
                $note->forceFill([
                    'employee_id' => $employee->id,
                    'school_id' => $employee->school_id,
                    'author_user_id' => $actor->id,
                ])->save();

                $this->audit->school($employee->school, 'employee.note.created', actor: $actor, subject: $note, metadata: [
                    'employeeId' => $employee->id,
                    'classificationTier' => $note->classification_tier,
                ]);

                return $note;
            });
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(Employee $employee, EmployeeNote $note, array $attributes, User $actor): EmployeeNote
    {
        $this->authorizeCapabilityFor($actor, 'hr.employees.notes.manage', $employee->school);
        $this->assertOwnership($employee, $note);
        $this->assertLiveRecord($note);
        unset($attributes['school_id'], $attributes['employee_id'], $attributes['author_user_id']);

        return $this->context->withSchool($employee->school, function () use ($employee, $note, $attributes, $actor) {
            return DB::transaction(function () use ($employee, $note, $attributes, $actor) {
                $this->operational->requireOperational($employee->school_id);
                // HR.2A (S-3): nobody administers their own HR record.
                $this->selfAdministration->refuseOwnEmployee($actor, $employee->id, 'profile.note');
                $note->update($attributes);

                $this->audit->school($employee->school, 'employee.note.updated', actor: $actor, subject: $note, metadata: [
                    'employeeId' => $employee->id,
                    'fields' => array_keys($attributes),
                ]);

                return $note->fresh();
            });
        });
    }

    /**
     * HR.2A (S-16, OD-HR-13): "remove" ARCHIVES the record with a closed
     * reason -- frozen evidence, never deleted (ArchivesProfileRecords).
     */
    public function remove(Employee $employee, EmployeeNote $note, User $actor, ProfileRecordArchiveReason $reason = ProfileRecordArchiveReason::NO_LONGER_CURRENT): void
    {
        $this->authorizeCapabilityFor($actor, 'hr.employees.notes.manage', $employee->school);
        $this->assertOwnership($employee, $note);

        $this->archiveProfileRecord($employee, $note, $reason, $actor, 'employee.note.archived', ['noteId' => $note->id, 'classificationTier' => $note->classification_tier]);
    }

    private function assertOwnership(Employee $employee, EmployeeNote $note): void
    {
        if ($note->employee_id !== $employee->id) {
            throw new EmployeeOwnershipMismatchException($note->id, $employee->id, $note->employee_id);
        }
    }
}
