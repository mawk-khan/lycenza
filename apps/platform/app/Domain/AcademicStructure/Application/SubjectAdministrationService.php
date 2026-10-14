<?php

namespace App\Domain\AcademicStructure\Application;

use App\Domain\AcademicStructure\Application\Concerns\AdministersReferenceData;
use App\Domain\AcademicStructure\Events\SubjectCreated;
use App\Domain\AcademicStructure\Infrastructure\AcademicDepartment;
use App\Domain\AcademicStructure\Infrastructure\Subject;
use App\Models\School;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * SWC.2 (ADR 0073 section 4.2): Subject create/update, moved from the API
 * controller so the web pages and `/api/v1` share it. School-scoped codes
 * (rule 71); an optional Academic Department of the SAME School;
 * deactivation is reversible `status` (rule 73).
 */
class SubjectAdministrationService
{
    use AdministersReferenceData;

    public const string CAPABILITY_MANAGE = 'academics.subjects.manage';

    /** The closed subject_type catalog (Phase 0D). */
    public const array SUBJECT_TYPES = ['core', 'elective', 'co_scholastic', 'language', 'other'];

    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly TenantContext $context,
    ) {}

    /** @param  array<string, mixed>  $input */
    public function create(School $school, array $input, User $actor): Subject
    {
        $this->authorizeFor($actor, self::CAPABILITY_MANAGE, $school);
        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:32', Rule::unique('subjects')->where('school_id', $school->id)],
            'short_name' => ['nullable', 'string', 'max:32'],
            'subject_type' => ['sometimes', Rule::in(self::SUBJECT_TYPES)],
            'academic_department_id' => ['nullable', 'string'],
        ];
        $validated = $this->validateInput($input, $rules);

        return $this->context->withSchool($school, function () use ($school, $validated, $actor, $input, $rules): Subject {
            $this->assertDepartmentBelongsToSchool($validated['academic_department_id'] ?? null);

            return $this->writeUnique(fn (): Subject => DB::transaction(function () use ($school, $validated, $actor): Subject {
                $subject = Subject::query()->create([...$validated, 'school_id' => $school->id]);

                $this->audit->school($school, 'subject.created', actor: $actor, subject: $subject, metadata: [
                    'name' => $subject->name,
                    'code' => $subject->code,
                ]);

                event(new SubjectCreated($school->id, $subject->id, $subject->name, $subject->code));

                return $subject;
            }), fn () => $this->validateInput($input, $rules));
        });
    }

    /** @param  array<string, mixed>  $input */
    public function update(School $school, string $subjectId, array $input, User $actor): Subject
    {
        $this->authorizeFor($actor, self::CAPABILITY_MANAGE, $school);

        return $this->context->withSchool($school, function () use ($school, $subjectId, $input, $actor): Subject {
            $subject = Subject::query()->findOrFail($subjectId);
            $rules = [
                'name' => ['sometimes', 'string', 'max:255'],
                'code' => ['sometimes', 'string', 'max:32', Rule::unique('subjects')->where('school_id', $school->id)->ignore($subject->id)],
                'short_name' => ['sometimes', 'nullable', 'string', 'max:32'],
                'subject_type' => ['sometimes', Rule::in(self::SUBJECT_TYPES)],
                'academic_department_id' => ['sometimes', 'nullable', 'string'],
                'status' => ['sometimes', 'in:active,inactive'],
            ];
            $validated = $this->validateInput($input, $rules);

            if (array_key_exists('academic_department_id', $validated)) {
                $this->assertDepartmentBelongsToSchool($validated['academic_department_id']);
            }

            $before = $subject->only(array_keys($validated));

            $this->writeUnique(fn () => DB::transaction(function () use ($school, $subject, $validated, $before, $actor): void {
                $subject->update($validated);

                $this->audit->school($school, 'subject.updated', actor: $actor, subject: $subject, metadata: [
                    'before' => $before,
                    'after' => $validated,
                ]);
            }), fn () => $this->validateInput($input, $rules));

            return $subject->refresh();
        });
    }

    /** SchoolScope makes another School's Department invisible -- the same answer as a missing one. */
    private function assertDepartmentBelongsToSchool(?string $departmentId): void
    {
        if ($departmentId !== null && AcademicDepartment::query()->where('id', $departmentId)->doesntExist()) {
            throw ValidationException::withMessages(['academic_department_id' => ['This Academic Department does not belong to this School.']]);
        }
    }
}
