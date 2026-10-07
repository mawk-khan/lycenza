<?php

namespace App\Domain\Guardians\Application;

use App\Domain\Guardians\Application\Exceptions\ConcurrentPrimaryGuardianConflictException;
use App\Domain\Guardians\Application\Exceptions\CrossSchoolRelationshipException;
use App\Domain\Guardians\Application\Exceptions\DuplicateRelationshipException;
use App\Domain\Guardians\Application\Exceptions\GuardianRelationshipInUseException;
use App\Domain\Guardians\Infrastructure\Guardian;
use App\Domain\Guardians\Infrastructure\RelationshipType;
use App\Domain\Guardians\Infrastructure\StudentGuardianRelationship;
use App\Domain\Students\Application\StudentLockOrder;
use App\Domain\Students\Application\StudentProcessingAuthorizationReadService;
use App\Domain\Students\Infrastructure\Student;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * The ONLY sanctioned mutation path for the Student<->Guardian
 * relationship (Phase 1A.4) -- resolves Phase 1A.2's P3 finding for
 * good: `Student::guardians()->attach()`/`sync()` and
 * `Guardian::students()->attach()`/`sync()` are NOT used anywhere in
 * this service (or anywhere else in the supported mutation layer,
 * proven by StudentGuardianRelationshipTest::attach_is_not_the_supported_mutation_api_and_fails_closed,
 * Phase 1A.3). Every write goes through
 * App\Domain\Guardians\Infrastructure\StudentGuardianRelationship
 * directly, following AcademicYearService/GuardianContactService's
 * established validate -> write state -> audit, inside one transaction
 * pattern.
 *
 * Deliberately authorization-neutral -- see
 * docs/modules/STUDENT-GUARDIAN-IDENTITY.md ("Authorization boundary").
 * A future controller linking/unlinking/changing primary Guardian must
 * check BOTH `students.manage` AND `guardians.manage` before calling
 * this service, since the operation mutates both domain identities'
 * relationship at once (docs/modules/STUDENT-GUARDIAN-IDENTITY.md
 * "Authorization").
 *
 * Audit metadata carries only structural/authority facts (ids,
 * relationship type, boolean flags) -- never a Student's or Guardian's
 * name, matching StudentService/GuardianService's audit design.
 */
class StudentGuardianRelationshipService
{
    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly TenantContext $context,
        private readonly StudentLockOrder $lockOrder,
        private readonly StudentProcessingAuthorizationReadService $authorizations,
    ) {}

    /**
     * Deliberately does NOT accept `is_primary` -- mirrors
     * GuardianContactService::create()'s exact split (never auto-
     * promotes/demotes on create; setPrimary() below is the only way to
     * change the primary Guardian, so the database's partial unique
     * index is what rejects an invalid state, not "helpful" service
     * logic layered on top of create()).
     *
     * @param  array{is_legal_guardian?: bool, is_emergency_contact?: bool, is_authorized_pickup?: bool}  $attributes
     */
    public function link(Student $student, Guardian $guardian, RelationshipType $type, array $attributes = [], ?User $actor = null): StudentGuardianRelationship
    {
        if ($student->school_id !== $guardian->school_id) {
            throw new CrossSchoolRelationshipException;
        }

        return $this->context->withSchool($student->school, function () use ($student, $guardian, $type, $attributes, $actor) {
            try {
                return DB::transaction(function () use ($student, $guardian, $type, $attributes, $actor) {
                    $relationship = StudentGuardianRelationship::query()->create(array_merge([
                        'school_id' => $student->school_id,
                        'student_id' => $student->id,
                        'guardian_id' => $guardian->id,
                        'relationship_type' => $type,
                        'is_primary' => false,
                        // Explicit, not left to the column's DB
                        // default(false) -- Eloquent's create() does not
                        // re-fetch DB-assigned defaults for non-
                        // auto-increment columns, so an omitted flag
                        // would read back as `null` in memory (not
                        // `false`) until a refresh(), a real trap the
                        // GuardianContactService::create() precedent
                        // (Phase 1A.3) already avoids the same way.
                        'is_legal_guardian' => false,
                        'is_emergency_contact' => false,
                        'is_authorized_pickup' => false,
                    ], $attributes));

                    $this->audit->school($student->school, 'student_guardian.linked', actor: $actor, subject: $relationship, metadata: [
                        'studentId' => $student->id,
                        'guardianId' => $guardian->id,
                        'relationshipType' => $type->value,
                        'isLegalGuardian' => $relationship->is_legal_guardian,
                        'isEmergencyContact' => $relationship->is_emergency_contact,
                        'isAuthorizedPickup' => $relationship->is_authorized_pickup,
                    ]);

                    return $relationship;
                });
            } catch (UniqueConstraintViolationException) {
                throw new DuplicateRelationshipException;
            }
        });
    }

    /**
     * Relationship-type/authority-flag changes only -- never
     * `is_primary` (setPrimary() below is the only supported path for
     * that, matching link()'s own split).
     *
     * @param  array{relationship_type?: RelationshipType, is_legal_guardian?: bool, is_emergency_contact?: bool, is_authorized_pickup?: bool}  $attributes
     */
    public function update(StudentGuardianRelationship $relationship, array $attributes, ?User $actor = null): StudentGuardianRelationship
    {
        return $this->context->withSchool($relationship->school, fn () => DB::transaction(function () use ($relationship, $attributes, $actor) {
            // S5: Student first (ADR 0038 order) -- `is_legal_guardian` decides whether a consent grant qualifies.
            $this->lockOrder->holdStudent($relationship->school, $relationship->student_id);
            $relationship->update($attributes);

            $this->audit->school($relationship->school, 'student_guardian.updated', actor: $actor, subject: $relationship, metadata: [
                'studentId' => $relationship->student_id,
                'guardianId' => $relationship->guardian_id,
                'changedFields' => array_keys($attributes),
            ]);

            return $relationship->refresh();
        }));
    }

    /**
     * Demotes any other active primary relationship for the SAME
     * Student in the SAME transaction as the promotion -- the
     * database's partial unique index
     * (student_guardian_relationships_one_primary_per_student) is the
     * actual concurrency guarantee, matching
     * AcademicYearService::activate() and
     * GuardianContactService::setPrimary()'s identical "demote then
     * conditionally promote, translate the race into a domain
     * exception" pattern (section 14: never demote/COMMIT/promote as
     * separate transactions).
     */
    public function setPrimary(StudentGuardianRelationship $relationship, ?User $actor = null): StudentGuardianRelationship
    {
        return $this->context->withSchool($relationship->school, function () use ($relationship, $actor) {
            try {
                return DB::transaction(function () use ($relationship, $actor) {
                    // S5: Student first (ADR 0038 order): the two relationship rows below are then never locked
                    // against the processing-authorization seam's own relationship order.
                    $this->lockOrder->holdStudent($relationship->school, $relationship->student_id);
                    $previousPrimary = StudentGuardianRelationship::query()
                        ->where('school_id', $relationship->school_id)
                        ->where('student_id', $relationship->student_id)
                        ->where('is_primary', true)
                        ->where('id', '!=', $relationship->id)
                        ->first();

                    if ($previousPrimary !== null) {
                        $previousPrimary->update(['is_primary' => false]);
                    }

                    $relationship->update(['is_primary' => true]);

                    $this->audit->school($relationship->school, 'student_guardian.primary_changed', actor: $actor, subject: $relationship, metadata: [
                        'studentId' => $relationship->student_id,
                        'guardianId' => $relationship->guardian_id,
                        'previousPrimaryGuardianId' => $previousPrimary?->guardian_id,
                    ]);

                    return $relationship->refresh();
                });
            } catch (UniqueConstraintViolationException) {
                throw new ConcurrentPrimaryGuardianConflictException;
            }
        });
    }

    /**
     * Hard-deletes the relationship row -- there is no soft-deactivation
     * column on `student_guardian_relationships` (unlike GuardianContact's
     * `is_active`); "unlinking" a Guardian from a Student while keeping
     * both identities is the only meaning this operation has today.
     * Deleting a relationship row can never delete the Student or
     * Guardian, and never touches another Student's relationship with a
     * shared Guardian -- foreign keys only cascade parent-to-child (see
     * the migration's "Delete behavior" docblock, Phase 1A.2).
     */
    public function unlink(StudentGuardianRelationship $relationship, ?User $actor = null): void
    {
        $this->context->withSchool($relationship->school, function () use ($relationship, $actor) {
            DB::transaction(function () use ($relationship, $actor) {
                // S5: Student first (ADR 0038 order): the DELETE's RESTRICT check reaches the grants after the
                // relationship row, the reverse of the seam's grants -> relationship; the Student serializes them.
                $this->lockOrder->holdStudent($relationship->school, $relationship->student_id);

                // Retained processing-authorization evidence keeps the relationship (ADR 0038 note, 2026-10-07): a
                // deliberate 409, not the foreign key's raw error. Holding the Student, no new reference can appear
                // before this commits (every ledger insert needs the Student's key too).
                if ($this->authorizations->isGuardianRelationshipReferenced($relationship->school, $relationship->id)) {
                    throw new GuardianRelationshipInUseException;
                }

                $this->audit->school($relationship->school, 'student_guardian.unlinked', actor: $actor, subject: $relationship, metadata: [
                    'studentId' => $relationship->student_id,
                    'guardianId' => $relationship->guardian_id,
                    'relationshipType' => $relationship->relationship_type->value,
                ]);

                try {
                    $this->deleteRelationship($relationship);
                } catch (QueryException $e) {
                    // The RESTRICT key stays the authority; only ITS violation is this refusal.
                    if (GuardianRelationshipInUseException::isViolation($e)) {
                        throw new GuardianRelationshipInUseException;
                    }
                    throw $e;
                }
            });
        });
    }

    /**
     * The hard delete (the model has no delete events). Its RESTRICT foreign key
     * (`spa_guardian_relationship_context_foreign`) refuses a relationship retained evidence still names -- the
     * caller's preflight normally answers first.
     */
    private function deleteRelationship(StudentGuardianRelationship $relationship): void
    {
        StudentGuardianRelationship::query()->where('school_id', $relationship->school_id)->whereKey($relationship->id)->delete();
    }
}
