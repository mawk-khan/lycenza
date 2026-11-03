<?php

namespace App\Domain\HR\Application\Profile;

use App\Domain\HR\Application\Exceptions\ProfileRecordArchivedException;
use App\Domain\HR\Application\HrSelfAdministrationGuard;
use App\Domain\HR\Infrastructure\Employee;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * HR.2A (S-16, OD-HR-13): the one way an Employee's ancillary profile record
 * (address, emergency contact, note, qualification, previous experience,
 * certification) stops being current -- it is ARCHIVED with a closed reason,
 * never deleted. The row stays as frozen evidence
 * (`hr_profile_record_archive_guard`); only retention purges it. Used by the
 * owning services, which have already authorized the actor and verified that
 * the record belongs to the Employee.
 *
 * @property-read AuditRecorder $audit
 * @property-read TenantContext $context
 * @property-read HrSelfAdministrationGuard $selfAdministration
 */
trait ArchivesProfileRecords
{
    /** @param  array<string, mixed>  $metadata  ids and closed codes only */
    private function archiveProfileRecord(Employee $employee, Model $record, ProfileRecordArchiveReason $reason, User $actor, string $eventType, array $metadata): void
    {
        $this->context->withSchool($employee->school, function () use ($employee, $record, $reason, $actor, $eventType, $metadata): void {
            DB::transaction(function () use ($employee, $record, $reason, $actor, $eventType, $metadata): void {
                // SR.4 / HR.2A (S-3): nobody administers their own HR record.
                $this->selfAdministration->refuseOwnEmployee($actor, $employee->id, 'profile.archive');
                $locked = $record->newQuery()->whereKey($record->getKey())->lockForUpdate()->firstOrFail();
                if ($locked->getAttribute('archived_at') !== null) {
                    throw new ProfileRecordArchivedException;
                }
                $changes = ['archived_at' => now(), 'archived_by_user_id' => $actor->id, 'archive_reason' => $reason->value];
                if (array_key_exists('is_primary', $locked->getAttributes())) {
                    $changes['is_primary'] = false;
                }
                $locked->forceFill($changes)->save();

                $this->audit->school($employee->school, $eventType, actor: $actor, subject: $locked, metadata: ['employeeId' => $employee->id, 'reason' => $reason->value] + $metadata);
            });
        });
    }

    /** An archived record is frozen (the database refuses it too). */
    private function assertLiveRecord(Model $record): void
    {
        if ($record->getAttribute('archived_at') !== null) {
            throw new ProfileRecordArchivedException;
        }
    }
}
