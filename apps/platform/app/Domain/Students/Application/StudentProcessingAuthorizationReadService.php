<?php

namespace App\Domain\Students\Application;

use App\Domain\Guardians\Infrastructure\StudentGuardianRelationship;
use App\Domain\Students\Application\Exceptions\StudentNotAuthorizedForProcessingException;
use App\Domain\Students\Domain\ProcessingAuthorizationBasisType;
use App\Domain\Students\Domain\ProcessingAuthorizationPurpose;
use App\Domain\Students\Domain\ProcessingAuthorizationStatus;
use App\Domain\Students\Domain\StudentAge;
use App\Domain\Students\Infrastructure\Student;
use App\Domain\Students\Infrastructure\StudentProcessingAuthorization;
use App\Models\School;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Phase 0H.4D-P2 -- the ONE place "is this Student currently
 * authorized for this processing purpose" is decided. Students/SIS
 * owns the interpretation of age, basis, lifecycle state, and
 * validity; a future StudentMark checkpoint (or any other consumer)
 * calls this service exclusively and never queries
 * StudentProcessingAuthorization directly (see ADR 0038).
 *
 * "Current" state is always DERIVED from the append-only ledger --
 * never a stored flag. A grant is "active" when its own status is
 * `recorded` AND no later row's `terminates_authorization_id` points
 * at it (the partial unique index on that column is what makes "at
 * most one terminator" a database guarantee, not merely an
 * application read convention). An active grant additionally
 * "qualifies" only while its basis type's own current-time rule is
 * satisfied -- evaluated fresh on every read, never cached/frozen at
 * grant time (Student.date_of_birth and
 * StudentGuardianRelationship.is_legal_guardian are both read live).
 */
class StudentProcessingAuthorizationReadService
{
    public function __construct(private readonly TenantContext $context) {}

    public function isAuthorizedForProcessing(School $school, Student $student, ProcessingAuthorizationPurpose $purpose, ?CarbonImmutable $asOf = null): bool
    {
        return $this->qualifyingAuthorizationIdForStudent($school, $student, $purpose, $asOf) !== null;
    }

    /**
     * @throws StudentNotAuthorizedForProcessingException
     */
    public function assertAuthorizedForProcessing(School $school, Student $student, ProcessingAuthorizationPurpose $purpose, ?CarbonImmutable $asOf = null): void
    {
        if (! $this->isAuthorizedForProcessing($school, $student, $purpose, $asOf)) {
            throw new StudentNotAuthorizedForProcessingException($purpose->value);
        }
    }

    /**
     * Deterministic provenance selection (never PostgreSQL's
     * unspecified row order): among every currently-qualifying active
     * grant, the most recently `recorded_at`, ties broken by `id`
     * descending (a UUIDv7 is itself chronologically sortable). This
     * ordering exists ONLY to pick a single stable id for a future
     * StudentMark provenance snapshot -- it is NOT a legal precedence
     * ranking between basis types; ANY qualifying active basis is
     * independently sufficient, and withdrawing one never affects
     * another.
     */
    public function qualifyingAuthorizationIdForStudent(School $school, Student $student, ProcessingAuthorizationPurpose $purpose, ?CarbonImmutable $asOf = null): ?string
    {
        return $this->qualifyingGrants($school, $student, $purpose, $asOf)->first()?->id;
    }

    /**
     * @return Collection<int, StudentProcessingAuthorization> ordered most-recently-recorded first
     */
    public function qualifyingGrants(School $school, Student $student, ProcessingAuthorizationPurpose $purpose, ?CarbonImmutable $asOf = null): Collection
    {
        return $this->context->withSchool($school, function () use ($school, $student, $purpose, $asOf) {
            return $this->activeGrantsQuery($school, $student, $purpose)
                ->get()
                ->filter(fn (StudentProcessingAuthorization $grant) => $this->qualifies($school, $student, $grant, $asOf))
                ->values();
        });
    }

    /**
     * Every recorded grant with no terminal event pointing at it,
     * regardless of whether it currently qualifies -- callers needing
     * a qualification decision must use qualifyingGrants()/
     * isAuthorizedForProcessing(), not this method directly.
     *
     * @return Builder<StudentProcessingAuthorization>
     */
    public function activeGrantsQuery(School $school, Student $student, ProcessingAuthorizationPurpose $purpose): Builder
    {
        return StudentProcessingAuthorization::query()
            ->where('school_id', $school->id)
            ->where('student_id', $student->id)
            ->where('purpose', $purpose->value)
            ->where('status', ProcessingAuthorizationStatus::Recorded->value)
            ->whereNotExists(function ($query) {
                $query->selectRaw('1')
                    ->from('student_processing_authorizations as spa_terminal')
                    ->whereColumn('spa_terminal.terminates_authorization_id', 'student_processing_authorizations.id');
            })
            ->orderByDesc('recorded_at')
            ->orderByDesc('id');
    }

    private function qualifies(School $school, Student $student, StudentProcessingAuthorization $grant, ?CarbonImmutable $asOf): bool
    {
        return match ($grant->basis_type) {
            ProcessingAuthorizationBasisType::GuardianConsent => ! StudentAge::isAdult($student, $school, $asOf)
                && $this->relationshipStillLegalGuardian($grant),
            ProcessingAuthorizationBasisType::AdultStudentConsent => StudentAge::isAdult($student, $school, $asOf),
            ProcessingAuthorizationBasisType::StatutorySchoolPurpose => true,
        };
    }

    /**
     * Phase 0H.4D-P2 §31 -- the lock-capable consumption seam a future
     * StudentMark creation path must use instead of
     * qualifyingAuthorizationIdForStudent(), so its own mark-creation
     * transaction cannot race a concurrent withdrawal/revocation
     * committing in between the check and the write (the exact class
     * of bug the Phase 0H.4D-P1 replay-security correction fixed for
     * TOTP verification, applied here to the same "check-then-write
     * under a real lock" discipline).
     *
     * The CALLER must already be inside a `DB::transaction()` -- this
     * method locks rows but never opens or commits a transaction
     * itself, so its locks are released only when the caller's own
     * transaction ends. Locks the Student row, then the deterministic
     * qualifying grant row (and, for `guardian_consent`, the relied-
     * upon relationship row), and re-evaluates qualification under
     * those locks before returning -- eliminating the read-then-write
     * gap entirely.
     *
     * @throws StudentNotAuthorizedForProcessingException
     */
    public function lockQualifyingAuthorizationIdForProcessing(School $school, Student $student, ProcessingAuthorizationPurpose $purpose, ?CarbonImmutable $asOf = null): string
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('lockQualifyingAuthorizationIdForProcessing() must be called inside an open DB::transaction().');
        }

        return $this->context->withSchool($school, function () use ($school, $student, $purpose, $asOf) {
            Student::query()->lockForUpdate()->findOrFail($student->id);

            $candidateIds = $this->activeGrantsQuery($school, $student, $purpose)->pluck('id');

            if ($candidateIds->isEmpty()) {
                throw new StudentNotAuthorizedForProcessingException($purpose->value);
            }

            $lockedGrants = StudentProcessingAuthorization::query()
                ->whereIn('id', $candidateIds)
                ->lockForUpdate()
                ->orderByDesc('recorded_at')
                ->orderByDesc('id')
                ->get();

            foreach ($lockedGrants as $grant) {
                if ($grant->basis_type === ProcessingAuthorizationBasisType::GuardianConsent && $grant->student_guardian_relationship_id !== null) {
                    StudentGuardianRelationship::query()->lockForUpdate()->find($grant->student_guardian_relationship_id);
                }

                if ($this->qualifies($school, $student, $grant, $asOf)) {
                    return $grant->id;
                }
            }

            throw new StudentNotAuthorizedForProcessingException($purpose->value);
        });
    }

    private function relationshipStillLegalGuardian(StudentProcessingAuthorization $grant): bool
    {
        if ($grant->student_guardian_relationship_id === null) {
            return false;
        }

        // Read fresh, never the grant-time snapshot -- a relationship
        // later corrected to `is_legal_guardian = false` must stop
        // qualifying this (historical, untouched) grant immediately.
        $relationship = StudentGuardianRelationship::query()->find($grant->student_guardian_relationship_id);

        return $relationship !== null && $relationship->is_legal_guardian;
    }
}
