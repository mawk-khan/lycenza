<?php

namespace App\Domain\Students\Application;

use App\Domain\Students\Application\Exceptions\DuplicateStudentNumberException;
use App\Domain\Students\Application\Exceptions\InvalidStudentStatusException;
use App\Domain\Students\Infrastructure\Student;
use App\Models\School;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

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
    ) {}

    /**
     * @param  array{student_number: string, first_name: string, middle_name?: string|null, last_name?: string|null, date_of_birth: string}  $attributes
     */
    public function create(School $school, array $attributes, ?User $actor = null): Student
    {
        return $this->context->withSchool($school, function () use ($school, $attributes, $actor) {
            try {
                return DB::transaction(function () use ($school, $attributes, $actor) {
                    $student = Student::query()->create([
                        'school_id' => $school->id,
                        'student_number' => $attributes['student_number'],
                        'first_name' => $attributes['first_name'],
                        'middle_name' => $attributes['middle_name'] ?? null,
                        'last_name' => $attributes['last_name'] ?? null,
                        'date_of_birth' => $attributes['date_of_birth'],
                        'status' => 'active',
                    ]);

                    $this->audit->school($school, 'student.created', actor: $actor, subject: $student);

                    return $student;
                });
            } catch (UniqueConstraintViolationException) {
                throw new DuplicateStudentNumberException($attributes['student_number']);
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

    public function changeStatus(Student $student, string $status, ?User $actor = null): Student
    {
        if (! in_array($status, ['active', 'inactive'], true)) {
            throw new InvalidStudentStatusException($status);
        }

        return $this->context->withSchool($student->school, fn () => DB::transaction(function () use ($student, $status, $actor) {
            $previousStatus = $student->status;
            $student->update(['status' => $status]);

            $this->audit->school($student->school, 'student.status_changed', actor: $actor, subject: $student, metadata: [
                'previousStatus' => $previousStatus,
                'newStatus' => $status,
            ]);

            return $student->refresh();
        }));
    }
}
