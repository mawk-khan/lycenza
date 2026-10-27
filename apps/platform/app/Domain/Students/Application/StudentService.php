<?php

namespace App\Domain\Students\Application;

use App\Domain\Students\Application\Exceptions\DuplicateStudentNumberException;
use App\Domain\Students\Application\Exceptions\InvalidStudentStatusException;
use App\Domain\Students\Application\Exceptions\PossibleDuplicateStudentException;
use App\Domain\Students\Application\Exceptions\StudentStatusUnchangedException;
use App\Domain\Students\Infrastructure\Student;
use App\Domain\Students\Infrastructure\StudentLifecycleEvent;
use App\Models\School;
use App\Models\User;
use App\Support\Approval\ApprovalDecisionAuthority;
use App\Support\Audit\AuditRecorder;
use App\Support\Auth\Mfa\FreshMfaProof;
use App\Support\Authorization\Campus\CampusScope;
use App\Support\Tenancy\TenantContext;
use App\Support\Validation\DateOfBirth;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * The only sanctioned write path for Student identity (Phase 1A.4) --
 * never create/update a Student row directly from a future controller.
 * Follows the exact pattern
 * App\Domain\AcademicStructure\Application\AcademicYearService and
 * App\Domain\Guardians\Application\GuardianContactService established:
 * validate -> write state -> audit, inside one transaction, with School
 * context enforced via TenantContext::withSchool() rather than assumed
 * ambient (rule 5/19: school_id is never accepted from caller-supplied
 * data -- it always derives from the $school/$student the caller
 * already holds, itself only obtainable through a real, verified
 * tenant-resolution path upstream of this service).
 *
 * Deliberately authorization-neutral, matching AcademicYearService and
 * GuardianContactService -- see docs/modules/STUDENT-GUARDIAN-IDENTITY.md
 * ("Authorization boundary") for exactly where `Gate::authorize` must
 * be called by the controller that eventually wraps this service.
 *
 * Audit metadata never carries a Student's name/date_of_birth (Sensitive/
 * Highly Sensitive personal data of a minor,
 * docs/security/DATA-CLASSIFICATION.md) -- only the changed field names
 * and non-PII status values, mirroring GuardianContactService's
 * "identity-safe by construction" audit design.
 */
class StudentService
{
    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly TenantContext $context,
        private readonly ApprovalDecisionAuthority $authority,
        private readonly StudentNumberAllocator $numbers,
        private readonly StudentDuplicateCandidates $duplicates,
    ) {}

    /**
     * SIS 2.3: THE way a Student comes into existence -- direct creation (web
     * and API), Admissions conversion and Campus onboarding all call this.
     * Inside one transaction:
     *
     *   1. the Student number from StudentNumberAllocator (the School's
     *      counter row, FOR UPDATE). Never typed: a caller passing
     *      `student_number` is a programming error. The lock also serializes
     *      every Student creation in the School, so step 2 always sees every
     *      Student committed before it;
     *   2. the duplicate-risk check (StudentDuplicateCandidates, over
     *      $duplicateScope -- the whole School unless a Campus caller
     *      narrows it to what its actor may see). Candidates refuse with
     *      PossibleDuplicateStudentException unless $duplicateAcknowledgement
     *      is exactly the acknowledgement of the CURRENT candidate set --
     *      re-evaluated here, never trusted from the earlier warning;
     *   3. the Student row (active), then the audit -- naming the
     *      acknowledged candidate ids when there were any.
     *
     * A refusal rolls back, counter included: no Student, no number held.
     *
     * @param  array{first_name: string, middle_name?: string|null, last_name?: string|null, date_of_birth: string}  $attributes
     */
    public function create(School $school, array $attributes, ?User $actor = null, ?string $duplicateAcknowledgement = null, ?CampusScope $duplicateScope = null): Student
    {
        // Defensive: the shape forbids it, but a caller passing an untyped array must fail loudly.
        if (array_key_exists('student_number', $attributes)) { // @phpstan-ignore function.impossibleType
            throw new LogicException('A Student number is allocated by StudentService::create(), never supplied (SIS 2.3).');
        }
        // SIS 3.2: the shared date-of-birth rule as the authoritative backstop.
        DateOfBirth::assert($school, $attributes['date_of_birth']);

        return $this->context->withSchool($school, function () use ($school, $attributes, $actor, $duplicateAcknowledgement, $duplicateScope) {
            $studentNumber = null;
            try {
                return DB::transaction(function () use ($school, $attributes, $actor, $duplicateAcknowledgement, $duplicateScope, &$studentNumber) {
                    $studentNumber = $this->numbers->allocate($school);

                    $identity = [
                        'first_name' => $attributes['first_name'],
                        'middle_name' => $attributes['middle_name'] ?? null,
                        'last_name' => $attributes['last_name'] ?? null,
                        'date_of_birth' => $attributes['date_of_birth'],
                    ];
                    $candidates = $this->duplicates->find($school, $identity, $duplicateScope);
                    if ($candidates->isNotEmpty()) {
                        $expected = $this->duplicates->acknowledgement($school, $identity, $candidates);
                        if ($duplicateAcknowledgement === null || ! hash_equals($expected, $duplicateAcknowledgement)) {
                            throw new PossibleDuplicateStudentException($candidates->map(fn (Student $s): string => (string) $s->id)->values()->all(), $expected);
                        }
                    }

                    $student = Student::query()->create([
                        'school_id' => $school->id,
                        'student_number' => $studentNumber,
                        ...$identity,
                        'status' => 'active',
                    ]);

                    $this->audit->school($school, 'student.created', actor: $actor, subject: $student, metadata: $candidates->isEmpty() ? [] : [
                        'duplicateWarningAcknowledged' => true,
                        'duplicateCandidateIds' => $candidates->map(fn (Student $s): string => (string) $s->id)->values()->all(),
                    ]);

                    return $student;
                });
            } catch (UniqueConstraintViolationException) {
                // Unreachable in correct operation (the allocator skips held
                // numbers under the counter lock); the unique index stays the
                // final backstop.
                throw new DuplicateStudentNumberException((string) $studentNumber);
            }
        });
    }

    /**
     * Identity fields only -- lifecycle status changes go through the
     * dedicated, invariant-checked changeStatus() below (mirrors
     * AcademicYearController::update()'s doc comment: "status changes go
     * through activate()/close() below").
     *
     * @param  array{student_number?: string, first_name?: string, middle_name?: string|null, last_name?: string|null, date_of_birth?: string}  $attributes
     */
    public function update(Student $student, array $attributes, ?User $actor = null): Student
    {
        return $this->context->withSchool($student->school, function () use ($student, $attributes, $actor) {
            try {
                return DB::transaction(function () use ($student, $attributes, $actor) {
                    if (array_key_exists('date_of_birth', $attributes)) {
                        DateOfBirth::assert($student->school, $attributes['date_of_birth']);
                    }
                    $student->update($attributes);

                    $this->audit->school($student->school, 'student.updated', actor: $actor, subject: $student, metadata: [
                        'changedFields' => array_keys($attributes),
                    ]);

                    return $student->refresh();
                });
            } catch (UniqueConstraintViolationException) {
                throw new DuplicateStudentNumberException($attributes['student_number'] ?? $student->student_number);
            }
        });
    }

    /**
     * ORG.8E (ADR 0076 §63, 8E-D1): deactivating or reactivating a Student is
     * a direct, whole-School, high-impact action. Under the Student row lock
     * (FOR NO KEY UPDATE, rule 101): the actor's CURRENT whole-School
     * `students.manage` (and the module and School, read fresh) -> a real
     * change (the same status is refused, never a second record) -> a closed
     * reason for that direction -> the event row -> the status -> audit. The
     * caller verified a fresh MFA code for $actor first (FreshMfaRequirement);
     * the proof is required here so no caller can skip it. No Campus scope:
     * a Campus grant of `students.manage` never reaches this.
     */
    public function changeStatus(Student $student, string $status, string $reasonCode, User $actor, FreshMfaProof $mfa): Student
    {
        if (! in_array($status, Student::STATUSES, true)) {
            throw new InvalidStudentStatusException($status);
        }
        if (! $mfa->isFor($actor->id)) {
            throw new LogicException('The fresh MFA proof belongs to another User.');
        }
        $action = $status === 'inactive' ? StudentLifecycleReason::DEACTIVATE : StudentLifecycleReason::REACTIVATE;
        StudentLifecycleReason::require($action, $reasonCode);

        return $this->context->withSchool($student->school, fn () => DB::transaction(function () use ($student, $status, $reasonCode, $action, $actor) {
            $locked = Student::query()->whereKey($student->id)->lock('for no key update')->firstOrFail();
            $this->authority->requireCurrent($actor, 'students.manage', $student->school);
            $previousStatus = $locked->status;
            if ($previousStatus === $status) {
                throw new StudentStatusUnchangedException($status);
            }

            $event = StudentLifecycleEvent::query()->create([
                'school_id' => $locked->school_id, 'student_id' => $locked->id, 'action' => $action,
                'reason_code' => $reasonCode, 'acted_by_user_id' => $actor->id,
            ]);
            $locked->update(['status' => $status]);

            $this->audit->school($student->school, 'student.status_changed', actor: $actor, subject: $locked, metadata: [
                'previousStatus' => $previousStatus,
                'newStatus' => $status,
                'reasonCode' => $reasonCode,
                'lifecycleEventId' => $event->id,
                'freshMfa' => true,
            ]);

            return $locked->refresh();
        }));
    }
}
