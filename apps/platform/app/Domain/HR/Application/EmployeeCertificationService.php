<?php

namespace App\Domain\HR\Application;

use App\Domain\HR\Application\Credentials\CredentialVerificationDecisions;
use App\Domain\HR\Application\Exceptions\EmployeeOwnershipMismatchException;
use App\Domain\HR\Application\Profile\ArchivesProfileRecords;
use App\Domain\HR\Application\Profile\ProfileRecordArchiveReason;
use App\Domain\HR\Infrastructure\Employee;
use App\Domain\HR\Infrastructure\EmployeeCertification;
use App\Domain\HR\Infrastructure\EmployeeCredentialVerification;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Tenancy\SchoolOperationalGuard;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
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
 *
 * HR.R1-C29 (C14-N5): verify()/reject() name the revision their
 * decision-maker saw and record an append-only decision, exactly as
 * EmployeeQualificationService does.
 */
class EmployeeCertificationService
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
    public function add(Employee $employee, array $attributes, User $actor): EmployeeCertification
    {
        $this->authorizeCapabilityFor($actor, 'hr.employees.qualifications.manage', $employee->school);
        unset($attributes['school_id'], $attributes['employee_id'], $attributes['verification_status'], $attributes['verified_at']);

        return $this->context->withSchool($employee->school, function () use ($employee, $attributes, $actor) {
            return DB::transaction(function () use ($employee, $attributes, $actor) {
                $this->operational->requireOperational($employee->school_id);
                // HR.2A (S-3): nobody administers their own HR record.
                $this->selfAdministration->refuseOwnEmployee($actor, $employee->id, 'profile.certification');
                // HR.R1-C13 (GOV-6): the caller's fields pass through fill(); the owner's columns are stated here.
                $certification = new EmployeeCertification($attributes);
                $certification->forceFill([
                    'employee_id' => $employee->id,
                    'school_id' => $employee->school_id,
                    'verification_status' => 'unverified',
                    'verified_at' => null,
                ])->save();

                $this->audit->school($employee->school, 'hr.certification.created', actor: $actor, subject: $certification, metadata: [
                    'employeeId' => $employee->id,
                    'certificationId' => $certification->id,
                ]);

                // The database's revision (1) and defaults, never assumed here.
                return $certification->refresh();
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
        $this->assertLiveRecord($certification);
        unset($attributes['school_id'], $attributes['employee_id'], $attributes['verification_status'], $attributes['verified_at']);

        return $this->context->withSchool($employee->school, function () use ($employee, $certification, $attributes, $actor) {
            return DB::transaction(function () use ($employee, $certification, $attributes, $actor) {
                $this->operational->requireOperational($employee->school_id);
                // HR.2A (S-3): nobody administers their own HR record.
                $this->selfAdministration->refuseOwnEmployee($actor, $employee->id, 'profile.certification');
                // HR.R1-C14: decided on the locked row. A model read earlier cannot see a verification that committed
                // since, and writing only the edited columns would leave the new content verified.
                $locked = EmployeeCertification::query()->whereKey($certification->id)->lockForUpdate()->firstOrFail();
                $this->assertLiveRecord($locked);
                $wasVerified = $locked->verification_status !== 'unverified';

                // HR.R1-C29 (delta review LOW-A): a date column stores a day, so a value with a time of day is compared
                // as the day PostgreSQL will store; otherwise an unchanged day would look like an edit.
                foreach (['issued_on', 'expires_on'] as $date) {
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

                $this->audit->school($employee->school, 'hr.certification.updated', actor: $actor, subject: $locked, metadata: [
                    'employeeId' => $employee->id,
                    'certificationId' => $certification->id,
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
    public function remove(Employee $employee, EmployeeCertification $certification, User $actor, ProfileRecordArchiveReason $reason = ProfileRecordArchiveReason::NO_LONGER_CURRENT): void
    {
        $this->authorizeCapabilityFor($actor, 'hr.employees.qualifications.manage', $employee->school);
        $this->assertOwnership($employee, $certification);

        $this->archiveProfileRecord($employee, $certification, $reason, $actor, 'hr.certification.archived', ['certificationId' => $certification->id]);
    }

    /**
     * HR.R1-C29 (C14-N5, C29-A): `$revision` is the revision the decision-maker saw. Decided on the locked row:
     * any other revision is refused (CredentialRevisionChangedException) and nothing is written. The decision is
     * recorded once per revision and sets the certification's verification in the database.
     */
    public function verify(Employee $employee, EmployeeCertification $certification, User $actor, int $revision): EmployeeCertification
    {
        $this->authorizeCapabilityFor($actor, 'hr.employees.qualifications.manage', $employee->school);
        $this->assertOwnership($employee, $certification);
        $this->assertLiveRecord($certification);

        return $this->context->withSchool($employee->school, function () use ($employee, $certification, $actor, $revision) {
            return DB::transaction(function () use ($employee, $certification, $actor, $revision) {
                $this->operational->requireOperational($employee->school_id);
                // HR.2A (S-3): nobody administers their own HR record.
                $this->selfAdministration->refuseOwnEmployee($actor, $employee->id, 'profile.certification');
                $locked = EmployeeCertification::query()->whereKey($certification->id)->lockForUpdate()->firstOrFail();
                $this->assertLiveRecord($locked);
                $recorded = $this->decisions->decide($locked, $revision, EmployeeCredentialVerification::VERIFIED, $actor);
                if ($recorded === null) {
                    // This revision already carries this decision: no new evidence, nothing written or audited.
                    return $locked;
                }

                $this->audit->school($employee->school, 'hr.certification.verified', actor: $actor, subject: $certification, metadata: [
                    'employeeId' => $employee->id,
                    'certificationId' => $certification->id,
                    'revision' => $revision,
                    'decisionId' => $recorded->id,
                ]);

                return $certification->fresh();
            });
        });
    }

    /**
     * HR.R1-C29 (C14-N5, C29-A): `$revision` is the revision the decision-maker saw. Decided on the locked row:
     * any other revision is refused (CredentialRevisionChangedException) and nothing is written. The decision is
     * recorded once per revision and sets the certification's verification in the database.
     */
    public function reject(Employee $employee, EmployeeCertification $certification, User $actor, int $revision): EmployeeCertification
    {
        $this->authorizeCapabilityFor($actor, 'hr.employees.qualifications.manage', $employee->school);
        $this->assertOwnership($employee, $certification);
        $this->assertLiveRecord($certification);

        return $this->context->withSchool($employee->school, function () use ($employee, $certification, $actor, $revision) {
            return DB::transaction(function () use ($employee, $certification, $actor, $revision) {
                $this->operational->requireOperational($employee->school_id);
                // HR.2A (S-3): nobody administers their own HR record.
                $this->selfAdministration->refuseOwnEmployee($actor, $employee->id, 'profile.certification');
                $locked = EmployeeCertification::query()->whereKey($certification->id)->lockForUpdate()->firstOrFail();
                $this->assertLiveRecord($locked);
                $recorded = $this->decisions->decide($locked, $revision, EmployeeCredentialVerification::REJECTED, $actor);
                if ($recorded === null) {
                    // This revision already carries this decision: no new evidence, nothing written or audited.
                    return $locked;
                }

                $this->audit->school($employee->school, 'hr.certification.rejected', actor: $actor, subject: $certification, metadata: [
                    'employeeId' => $employee->id,
                    'certificationId' => $certification->id,
                    'revision' => $revision,
                    'decisionId' => $recorded->id,
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
