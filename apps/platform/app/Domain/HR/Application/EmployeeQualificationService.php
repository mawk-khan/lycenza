<?php

namespace App\Domain\HR\Application;

use App\Domain\HR\Application\Credentials\CredentialVerificationDecisions;
use App\Domain\HR\Application\Exceptions\EmployeeOwnershipMismatchException;
use App\Domain\HR\Application\Profile\ArchivesProfileRecords;
use App\Domain\HR\Application\Profile\ProfileRecordArchiveReason;
use App\Domain\HR\Infrastructure\Employee;
use App\Domain\HR\Infrastructure\EmployeeCredentialVerification;
use App\Domain\HR\Infrastructure\EmployeeQualification;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Tenancy\SchoolOperationalGuard;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
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
 * this checkpoint declines to make without an approved contract. *
 * HR.R1-C29 (C14-N5, owner decisions C29-A/B/C, 2026-11-12): a decision
 * names the revision its decision-maker saw (`revision`, moved only by the
 * database on any change of a verifiable field). The decision is an
 * append-only `employee_credential_verifications` row
 * (CredentialVerificationDecisions), whose insert sets
 * `verification_status`/`verified_at`/`verification_revision`; an edit keeps
 * that history and resets the projection. A status with no
 * `verification_revision` predates version recording and is never rewritten.
 */
class EmployeeQualificationService
{
    use ArchivesProfileRecords, AuthorizesCapability;

    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly TenantContext $context,
        private readonly HrSelfAdministrationGuard $selfAdministration,
        private readonly SchoolOperationalGuard $operational,
        private readonly CredentialVerificationDecisions $decisions,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function add(Employee $employee, array $attributes, User $actor): EmployeeQualification
    {
        $this->authorizeCapabilityFor($actor, 'hr.employees.qualifications.manage', $employee->school);
        unset($attributes['school_id'], $attributes['employee_id'], $attributes['verification_status'], $attributes['verified_at']);

        return $this->context->withSchool($employee->school, function () use ($employee, $attributes, $actor) {
            return DB::transaction(function () use ($employee, $attributes, $actor) {
                $this->operational->requireOperational($employee->school_id);
                // HR.2A (S-3): nobody administers their own HR record.
                $this->selfAdministration->refuseOwnEmployee($actor, $employee->id, 'profile.qualification');
                // HR.R1-C13 (GOV-6): the caller's fields pass through fill(); the owner's columns are stated here.
                $qualification = new EmployeeQualification($attributes);
                $qualification->forceFill([
                    'employee_id' => $employee->id,
                    'school_id' => $employee->school_id,
                    'verification_status' => 'unverified',
                    'verified_at' => null,
                ])->save();

                $this->audit->school($employee->school, 'hr.qualification.created', actor: $actor, subject: $qualification, metadata: [
                    'employeeId' => $employee->id,
                    'qualificationId' => $qualification->id,
                ]);

                // The database's revision (1) and defaults, never assumed here.
                return $qualification->refresh();
            });
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(Employee $employee, EmployeeQualification $qualification, array $attributes, User $actor): EmployeeQualification
    {
        $this->authorizeCapabilityFor($actor, 'hr.employees.qualifications.manage', $employee->school);
        $this->assertOwnership($employee, $qualification);
        $this->assertLiveRecord($qualification);
        unset($attributes['school_id'], $attributes['employee_id'], $attributes['verification_status'], $attributes['verified_at']);

        return $this->context->withSchool($employee->school, function () use ($employee, $qualification, $attributes, $actor) {
            return DB::transaction(function () use ($employee, $qualification, $attributes, $actor) {
                $this->operational->requireOperational($employee->school_id);
                // HR.2A (S-3): nobody administers their own HR record.
                $this->selfAdministration->refuseOwnEmployee($actor, $employee->id, 'profile.qualification');
                // HR.R1-C14: decided on the locked row. A model read earlier cannot see a verification that committed
                // since, and writing only the edited columns would leave the new content verified.
                $locked = EmployeeQualification::query()->whereKey($qualification->id)->lockForUpdate()->firstOrFail();
                $this->assertLiveRecord($locked);
                $wasVerified = $locked->verification_status !== 'unverified';

                // HR.R1-C29 (delta review LOW-A): a date column stores a day, so a value with a time of day is compared
                // as the day PostgreSQL will store; otherwise an unchanged day would look like an edit.
                foreach (['starts_on', 'completed_on'] as $date) {
                    if (isset($attributes[$date]) && is_string($attributes[$date])) {
                        $attributes[$date] = Carbon::parse($attributes[$date])->toDateString();
                    }
                }
                $locked->fill($attributes);
                // HR.R1-C29 (C29-A, review LOW-2): an actual change of a verifiable field is what resets a decision,
                // exactly what moves the revision in the database; re-saving the same values decides nothing.
                $changed = $locked->isDirty();
                // HR.R1-C13 (GOV-6): verification is not fillable, so the reset is stated, and the audit still names it.
                if ($changed && $wasVerified) {
                    // HR.R1-C29: the database moves the revision; the reset names no verification any more.
                    $locked->forceFill(['verification_status' => 'unverified', 'verified_at' => null, 'verification_revision' => null]);
                    $attributes = [...$attributes, 'verification_status' => 'unverified', 'verified_at' => null];
                }
                $locked->save();

                $this->audit->school($employee->school, 'hr.qualification.updated', actor: $actor, subject: $locked, metadata: [
                    'employeeId' => $employee->id,
                    'qualificationId' => $qualification->id,
                    'fields' => array_keys($attributes),
                    'verificationReset' => $wasVerified && $changed,
                ]);

                return $locked->fresh();
            });
        });
    }

    /**
     * HR.2A (S-16, OD-HR-13): "remove" ARCHIVES the record with a closed
     * reason -- frozen evidence, never deleted (ArchivesProfileRecords).
     */
    public function remove(Employee $employee, EmployeeQualification $qualification, User $actor, ProfileRecordArchiveReason $reason = ProfileRecordArchiveReason::NO_LONGER_CURRENT): void
    {
        $this->authorizeCapabilityFor($actor, 'hr.employees.qualifications.manage', $employee->school);
        $this->assertOwnership($employee, $qualification);

        $this->archiveProfileRecord($employee, $qualification, $reason, $actor, 'hr.qualification.archived', ['qualificationId' => $qualification->id]);
    }

    /**
     * HR.R1-C29 (C14-N5, C29-A): `$revision` is the revision the decision-maker saw. Decided on the locked row:
     * any other revision is refused (CredentialRevisionChangedException) and nothing is written. The decision is
     * recorded once per revision and sets the qualification's verification in the database.
     */
    public function verify(Employee $employee, EmployeeQualification $qualification, User $actor, int $revision): EmployeeQualification
    {
        $this->authorizeCapabilityFor($actor, 'hr.employees.qualifications.manage', $employee->school);
        $this->assertOwnership($employee, $qualification);
        $this->assertLiveRecord($qualification);

        return $this->context->withSchool($employee->school, function () use ($employee, $qualification, $actor, $revision) {
            return DB::transaction(function () use ($employee, $qualification, $actor, $revision) {
                $this->operational->requireOperational($employee->school_id);
                // HR.2A (S-3): nobody administers their own HR record.
                $this->selfAdministration->refuseOwnEmployee($actor, $employee->id, 'profile.qualification');
                $locked = EmployeeQualification::query()->whereKey($qualification->id)->lockForUpdate()->firstOrFail();
                $this->assertLiveRecord($locked);
                $recorded = $this->decisions->decide($locked, $revision, EmployeeCredentialVerification::VERIFIED, $actor);
                if ($recorded === null) {
                    // This revision already carries this decision: no new evidence, nothing written or audited.
                    return $locked;
                }

                $this->audit->school($employee->school, 'hr.qualification.verified', actor: $actor, subject: $qualification, metadata: [
                    'employeeId' => $employee->id,
                    'qualificationId' => $qualification->id,
                    'revision' => $revision,
                    'decisionId' => $recorded->id,
                ]);

                return $qualification->fresh();
            });
        });
    }

    /**
     * HR.R1-C29 (C14-N5, C29-A): `$revision` is the revision the decision-maker saw. Decided on the locked row:
     * any other revision is refused (CredentialRevisionChangedException) and nothing is written. The decision is
     * recorded once per revision and sets the qualification's verification in the database.
     */
    public function reject(Employee $employee, EmployeeQualification $qualification, User $actor, int $revision): EmployeeQualification
    {
        $this->authorizeCapabilityFor($actor, 'hr.employees.qualifications.manage', $employee->school);
        $this->assertOwnership($employee, $qualification);
        $this->assertLiveRecord($qualification);

        return $this->context->withSchool($employee->school, function () use ($employee, $qualification, $actor, $revision) {
            return DB::transaction(function () use ($employee, $qualification, $actor, $revision) {
                $this->operational->requireOperational($employee->school_id);
                // HR.2A (S-3): nobody administers their own HR record.
                $this->selfAdministration->refuseOwnEmployee($actor, $employee->id, 'profile.qualification');
                $locked = EmployeeQualification::query()->whereKey($qualification->id)->lockForUpdate()->firstOrFail();
                $this->assertLiveRecord($locked);
                $recorded = $this->decisions->decide($locked, $revision, EmployeeCredentialVerification::REJECTED, $actor);
                if ($recorded === null) {
                    // This revision already carries this decision: no new evidence, nothing written or audited.
                    return $locked;
                }

                $this->audit->school($employee->school, 'hr.qualification.rejected', actor: $actor, subject: $qualification, metadata: [
                    'employeeId' => $employee->id,
                    'qualificationId' => $qualification->id,
                    'revision' => $revision,
                    'decisionId' => $recorded->id,
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
