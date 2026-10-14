<?php

namespace App\Domain\AcademicStructure\Application;

use App\Domain\AcademicStructure\Application\Concerns\AdministersReferenceData;
use App\Domain\AcademicStructure\Events\GradeLevelCreated;
use App\Domain\AcademicStructure\Infrastructure\GradeLevel;
use App\Models\School;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * SWC.2 (ADR 0073 section 4.2): Grade Level create/update, moved from the
 * API controller so the web pages and `/api/v1` share it. Codes and the
 * pedagogical `sequence` (rule 67) are unique per School; deactivation is
 * reversible `status` (rule 73).
 */
class GradeLevelAdministrationService
{
    use AdministersReferenceData;

    public const string CAPABILITY_MANAGE = 'academics.structure.manage';

    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly TenantContext $context,
    ) {}

    /** @param  array<string, mixed>  $input */
    public function create(School $school, array $input, User $actor): GradeLevel
    {
        $this->authorizeFor($actor, self::CAPABILITY_MANAGE, $school);
        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:32', Rule::unique('grade_levels')->where('school_id', $school->id)],
            'sequence' => ['required', 'integer', 'min:0', Rule::unique('grade_levels')->where('school_id', $school->id)],
            'education_stage' => ['nullable', 'string', 'max:64'],
        ];
        $validated = $this->validateInput($input, $rules);

        return $this->context->withSchool($school, fn (): GradeLevel => $this->writeUnique(
            fn (): GradeLevel => DB::transaction(function () use ($school, $validated, $actor): GradeLevel {
                $gradeLevel = GradeLevel::query()->create([...$validated, 'school_id' => $school->id]);

                $this->audit->school($school, 'grade_level.created', actor: $actor, subject: $gradeLevel, metadata: [
                    'name' => $gradeLevel->name,
                    'code' => $gradeLevel->code,
                ]);

                event(new GradeLevelCreated($school->id, $gradeLevel->id, $gradeLevel->name, $gradeLevel->code));

                return $gradeLevel;
            }),
            fn () => $this->validateInput($input, $rules),
        ));
    }

    /** @param  array<string, mixed>  $input */
    public function update(School $school, string $gradeLevelId, array $input, User $actor): GradeLevel
    {
        $this->authorizeFor($actor, self::CAPABILITY_MANAGE, $school);

        return $this->context->withSchool($school, function () use ($school, $gradeLevelId, $input, $actor): GradeLevel {
            $gradeLevel = GradeLevel::query()->findOrFail($gradeLevelId);
            $rules = [
                'name' => ['sometimes', 'string', 'max:255'],
                'code' => ['sometimes', 'string', 'max:32', Rule::unique('grade_levels')->where('school_id', $school->id)->ignore($gradeLevel->id)],
                'sequence' => ['sometimes', 'integer', 'min:0', Rule::unique('grade_levels')->where('school_id', $school->id)->ignore($gradeLevel->id)],
                'education_stage' => ['sometimes', 'nullable', 'string', 'max:64'],
                'status' => ['sometimes', 'in:active,inactive'],
            ];
            $validated = $this->validateInput($input, $rules);
            $before = $gradeLevel->only(array_keys($validated));

            $this->writeUnique(fn () => DB::transaction(function () use ($school, $gradeLevel, $validated, $before, $actor): void {
                $gradeLevel->update($validated);

                $this->audit->school($school, 'grade_level.updated', actor: $actor, subject: $gradeLevel, metadata: [
                    'before' => $before,
                    'after' => $validated,
                ]);
            }), fn () => $this->validateInput($input, $rules));

            return $gradeLevel->refresh();
        });
    }
}
