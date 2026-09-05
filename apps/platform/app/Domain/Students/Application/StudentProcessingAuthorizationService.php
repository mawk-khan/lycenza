<?php

namespace App\Domain\Students\Application;

use App\Domain\Guardians\Infrastructure\StudentGuardianRelationship;
use App\Domain\Students\Application\Exceptions\GuardianRelationshipNotEligibleException;
use App\Domain\Students\Application\Exceptions\ProcessingAuthorizationAlreadyTerminatedException;
use App\Domain\Students\Application\Exceptions\ProcessingAuthorizationNotFoundException;
use App\Domain\Students\Domain\ProcessingAuthorizationAuditActions;
use App\Domain\Students\Domain\ProcessingAuthorizationBasisType;
use App\Domain\Students\Domain\ProcessingAuthorizationPurpose;
use App\Domain\Students\Domain\ProcessingAuthorizationStatus;
use App\Domain\Students\Infrastructure\Student;
use App\Domain\Students\Infrastructure\StudentProcessingAuthorization;
use App\Models\School;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Phase 0H.4D-P2 -- the sole write path for the append-only
 * StudentProcessingAuthorization ledger. Never mutates or deletes a
 * row: every operation either inserts a new `recorded` grant or
 * inserts a new terminal event (`withdrawn`/`revoked`/`superseded`)
 * pointing at the grant it ends. Capability (`students.
 * processing_authorizations.manage`) and `mfa` assurance are enforced
 * by the caller (route middleware / AuthorizesCapability), exactly
 * like every other write path in this codebase -- this service never
 * checks capabilities itself.
 *
 * `note` is short, optional, operator-facing context -- never treated
 * as the legal basis itself, and never copied into audit metadata
 * (see AuditActions and the audit() calls below).
 */
class StudentProcessingAuthorizationService
{
    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly TenantContext $context,
    ) {}

    /**
     * @throws GuardianRelationshipNotEligibleException
     */
    public function recordGuardianConsent(
        School $school,
        Student $student,
        ProcessingAuthorizationPurpose $purpose,
        StudentGuardianRelationship $relationship,
        User $actor,
        ?string $note = null,
    ): StudentProcessingAuthorization {
        return $this->context->withSchool($school, function () use ($school, $student, $purpose, $relationship, $actor, $note) {
            if ($relationship->school_id !== $school->id
                || $relationship->student_id !== $student->id
                || ! $relationship->is_legal_guardian
            ) {
                throw new GuardianRelationshipNotEligibleException;
            }

            return $this->record(
                $school, $student, $purpose, ProcessingAuthorizationBasisType::GuardianConsent, $actor, $note,
                studentGuardianRelationshipId: $relationship->id,
            );
        });
    }

    public function recordAdultStudentConsent(
        School $school,
        Student $student,
        ProcessingAuthorizationPurpose $purpose,
        User $actor,
        ?string $note = null,
    ): StudentProcessingAuthorization {
        return $this->context->withSchool(
            $school,
            fn () => $this->record($school, $student, $purpose, ProcessingAuthorizationBasisType::AdultStudentConsent, $actor, $note),
        );
    }

    public function recordStatutorySchoolPurpose(
        School $school,
        Student $student,
        ProcessingAuthorizationPurpose $purpose,
        User $actor,
        ?string $note = null,
    ): StudentProcessingAuthorization {
        return $this->context->withSchool(
            $school,
            fn () => $this->record($school, $student, $purpose, ProcessingAuthorizationBasisType::StatutorySchoolPurpose, $actor, $note),
        );
    }

    /**
     * @throws ProcessingAuthorizationNotFoundException
     * @throws ProcessingAuthorizationAlreadyTerminatedException
     */
    public function withdraw(School $school, StudentProcessingAuthorization $grant, User $actor, ?string $note = null): StudentProcessingAuthorization
    {
        return $this->terminate($school, $grant, ProcessingAuthorizationStatus::Withdrawn, $actor, $note);
    }

    /**
     * @throws ProcessingAuthorizationNotFoundException
     * @throws ProcessingAuthorizationAlreadyTerminatedException
     */
    public function revoke(School $school, StudentProcessingAuthorization $grant, User $actor, ?string $note = null): StudentProcessingAuthorization
    {
        return $this->terminate($school, $grant, ProcessingAuthorizationStatus::Revoked, $actor, $note);
    }

    /**
     * Atomically ends `$oldGrant` (status `superseded`) and records a
     * new grant of `$newBasisType` in ONE transaction -- the old grant
     * is locked first, so a concurrent termination of the same row
     * cannot leave the lineage in a state where the old grant is
     * un-terminated while a replacement already exists, or vice versa.
     *
     * @return array{terminal: StudentProcessingAuthorization, new: StudentProcessingAuthorization}
     *
     * @throws ProcessingAuthorizationNotFoundException
     * @throws ProcessingAuthorizationAlreadyTerminatedException
     * @throws GuardianRelationshipNotEligibleException
     */
    public function supersede(
        School $school,
        StudentProcessingAuthorization $oldGrant,
        ProcessingAuthorizationBasisType $newBasisType,
        User $actor,
        ?StudentGuardianRelationship $newRelationship = null,
        ?string $note = null,
    ): array {
        return $this->context->withSchool($school, function () use ($school, $oldGrant, $newBasisType, $actor, $newRelationship, $note) {
            return DB::transaction(function () use ($school, $oldGrant, $newBasisType, $actor, $newRelationship, $note) {
                $terminal = $this->terminate($school, $oldGrant, ProcessingAuthorizationStatus::Superseded, $actor, $note);

                $student = Student::query()->findOrFail($oldGrant->student_id);
                $purpose = $oldGrant->purpose;

                $new = $newBasisType === ProcessingAuthorizationBasisType::GuardianConsent
                    ? $this->recordGuardianConsent($school, $student, $purpose, $newRelationship, $actor, $note)
                    : $this->record($school, $student, $purpose, $newBasisType, $actor, $note);

                return ['terminal' => $terminal, 'new' => $new];
            });
        });
    }

    private function record(
        School $school,
        Student $student,
        ProcessingAuthorizationPurpose $purpose,
        ProcessingAuthorizationBasisType $basisType,
        User $actor,
        ?string $note,
        ?string $studentGuardianRelationshipId = null,
    ): StudentProcessingAuthorization {
        $grant = StudentProcessingAuthorization::query()->create([
            'school_id' => $school->id,
            'student_id' => $student->id,
            'purpose' => $purpose->value,
            'basis_type' => $basisType->value,
            'status' => ProcessingAuthorizationStatus::Recorded->value,
            'student_guardian_relationship_id' => $studentGuardianRelationshipId,
            'recorded_at' => now(),
            'recorded_by_user_id' => $actor->id,
            'note' => $note,
        ]);

        $this->audit->school($school, ProcessingAuthorizationAuditActions::RECORDED, actor: $actor, subject: $grant, metadata: [
            'studentId' => $student->id,
            'purpose' => $purpose->value,
            'basisType' => $basisType->value,
        ]);

        return $grant;
    }

    /**
     * @throws ProcessingAuthorizationNotFoundException
     * @throws ProcessingAuthorizationAlreadyTerminatedException
     */
    private function terminate(
        School $school,
        StudentProcessingAuthorization $grant,
        ProcessingAuthorizationStatus $terminalStatus,
        User $actor,
        ?string $note,
    ): StudentProcessingAuthorization {
        return $this->context->withSchool($school, function () use ($school, $grant, $terminalStatus, $actor, $note) {
            return DB::transaction(function () use ($school, $grant, $terminalStatus, $actor, $note) {
                // Deterministic lock order (Student always locked
                // before any of its StudentProcessingAuthorization
                // rows), matching
                // StudentProcessingAuthorizationReadService::
                // lockQualifyingAuthorizationIdForProcessing()'s exact
                // order -- discovered empirically as the fix for a
                // real PostgreSQL deadlock between this method and
                // that one racing the same grant row from opposite
                // lock orders. The same "establish one documented lock
                // order across every code path touching the same
                // rows" discipline this codebase already uses
                // elsewhere (e.g. Attendance's Section-before-
                // Enrollment order, Hostel's Student-then-Bed order).
                Student::query()->where('school_id', $school->id)->lockForUpdate()->find($grant->student_id);

                // Section 29: lock the exact grant being terminated
                // before deciding anything about it.
                $locked = StudentProcessingAuthorization::query()
                    ->where('school_id', $school->id)
                    ->lockForUpdate()
                    ->find($grant->id);

                if ($locked === null) {
                    throw new ProcessingAuthorizationNotFoundException;
                }

                if ($locked->status !== ProcessingAuthorizationStatus::Recorded) {
                    throw new ProcessingAuthorizationAlreadyTerminatedException;
                }

                try {
                    $terminal = StudentProcessingAuthorization::query()->create([
                        'school_id' => $school->id,
                        'student_id' => $locked->student_id,
                        'purpose' => $locked->purpose->value,
                        'basis_type' => $locked->basis_type->value,
                        'status' => $terminalStatus->value,
                        'terminates_authorization_id' => $locked->id,
                        'student_guardian_relationship_id' => $locked->student_guardian_relationship_id,
                        'recorded_at' => now(),
                        'recorded_by_user_id' => $actor->id,
                        'note' => $note,
                    ]);
                } catch (UniqueConstraintViolationException) {
                    // The database-authoritative guarantee (CLAUDE.md
                    // rule 30's discipline): the lock above closes the
                    // ordinary race window, but the partial unique
                    // index on terminates_authorization_id is what
                    // makes "at most one terminator" true even against
                    // a theoretically stale/bypassed lock.
                    throw new ProcessingAuthorizationAlreadyTerminatedException;
                }

                $eventType = match ($terminalStatus) {
                    ProcessingAuthorizationStatus::Withdrawn => ProcessingAuthorizationAuditActions::WITHDRAWN,
                    ProcessingAuthorizationStatus::Revoked => ProcessingAuthorizationAuditActions::REVOKED,
                    ProcessingAuthorizationStatus::Superseded => ProcessingAuthorizationAuditActions::SUPERSEDED,
                    ProcessingAuthorizationStatus::Recorded => throw new LogicException('terminate() cannot be called with the Recorded status.'),
                };

                $this->audit->school($school, $eventType, actor: $actor, subject: $terminal, metadata: [
                    'studentId' => $locked->student_id,
                    'purpose' => $locked->purpose->value,
                    'basisType' => $locked->basis_type->value,
                    'terminatesAuthorizationId' => $locked->id,
                ]);

                return $terminal;
            });
        });
    }
}
