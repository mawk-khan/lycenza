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
 *
 * Two different concurrency guarantees exist here, deliberately NOT
 * the same strength:
 *
 * - The ordinary read methods (`isAuthorizedForProcessing()`,
 *   `assertAuthorizedForProcessing()`, `qualifyingAuthorizationIdForStudent()`,
 *   `qualifyingGrants()`) evaluate age against whatever `Student`
 *   instance the CALLER supplies, with no row lock -- correct for an
 *   ordinary point-in-time display/decision read, but they give NO
 *   transactional serialization against a concurrent write landing
 *   between the caller's own Student fetch and the call into this
 *   service. A caller that needs a decision to remain valid through a
 *   subsequent write in the SAME transaction must NOT use these.
 * - `lockQualifyingAuthorizationIdForProcessing()` is the ONLY method
 *   with a real transactional guarantee: it re-fetches the Student row
 *   itself, under `FOR UPDATE`, and uses that freshly locked instance
 *   (never the caller's) for every qualification decision it makes.
 *   This is the seam a future check-and-write consumer (StudentMark
 *   chief among them) must use -- never the ordinary read methods --
 *   for anything that needs to survive a race with a concurrent
 *   withdrawal/revocation or a concurrent DOB/relationship correction.
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
     * `$student` is accepted for API ergonomics (a caller almost
     * always already has one in hand) but is used for its `id` ONLY --
     * every mutable field this method's qualification decision depends
     * on (`date_of_birth`) is read from the row THIS method itself
     * locks, never from the caller-supplied instance. A caller passing
     * a `$student` fetched before this method runs (routine: the
     * ordinary case for every real caller) must never have that
     * snapshot silently used for the actual decision once a fresher
     * row is available under lock -- see the Phase 0H.4D-P2 closure
     * audit finding this correction fixes: the pre-correction version
     * locked the Student row and then discarded the freshly locked
     * instance, continuing to evaluate age against the caller's
     * possibly-stale snapshot.
     *
     * @throws StudentNotAuthorizedForProcessingException
     */
    public function lockQualifyingAuthorizationIdForProcessing(School $school, Student $student, ProcessingAuthorizationPurpose $purpose, ?CarbonImmutable $asOf = null): string
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('lockQualifyingAuthorizationIdForProcessing() must be called inside an open DB::transaction().');
        }

        return $this->context->withSchool($school, function () use ($school, $student, $purpose, $asOf) {
            // Authoritative from this point on: the caller's $student
            // is never read again for a qualification decision, only
            // for its ->id (identity input) via $lockedStudent below.
            $lockedStudent = Student::query()->lockForUpdate()->findOrFail($student->id);

            $candidateIds = $this->activeGrantsQuery($school, $lockedStudent, $purpose)->pluck('id');

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

                if ($this->qualifies($school, $lockedStudent, $grant, $asOf)) {
                    return $grant->id;
                }
            }

            throw new StudentNotAuthorizedForProcessingException($purpose->value);
        });
    }

    /**
     * As lockQualifyingAuthorizationIdForProcessing(), for a caller that
     * holds only the Student's id (a consumer module that never loads this
     * module's models). A Student who is not the School's is simply not
     * authorized. The same locks, order and transaction requirement apply.
     *
     * @throws StudentNotAuthorizedForProcessingException
     */
    public function lockQualifyingAuthorizationIdForStudentId(School $school, string $studentId, ProcessingAuthorizationPurpose $purpose, ?CarbonImmutable $asOf = null): string
    {
        $student = $this->context->withSchool($school, fn () => Student::query()->where('school_id', $school->id)->find($studentId));
        if ($student === null) {
            throw new StudentNotAuthorizedForProcessingException($purpose->value);
        }

        return $this->lockQualifyingAuthorizationIdForProcessing($school, $student, $purpose, $asOf);
    }

    /**
     * The subset of $studentIds currently authorized for $purpose -- a plain
     * point-in-time read (no locks), for a list that must withhold every
     * other Student's data. Ids outside the School are never returned.
     *
     * @param  list<string>  $studentIds
     * @return list<string>
     */
    public function authorizedStudentIds(School $school, array $studentIds, ProcessingAuthorizationPurpose $purpose, ?CarbonImmutable $asOf = null): array
    {
        if ($studentIds === []) {
            return [];
        }
        $students = $this->context->withSchool($school, fn () => Student::query()->where('school_id', $school->id)->whereIn('id', $studentIds)->get());

        return $students->filter(fn (Student $student) => $this->isAuthorizedForProcessing($school, $student, $purpose, $asOf))
            ->map(fn (Student $student) => (string) $student->id)->values()->all();
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
