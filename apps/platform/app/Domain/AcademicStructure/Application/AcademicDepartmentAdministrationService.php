<?php

namespace App\Domain\AcademicStructure\Application;

use App\Domain\AcademicStructure\Application\Concerns\AdministersReferenceData;
use App\Domain\AcademicStructure\Infrastructure\AcademicDepartment;
use App\Models\School;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * SWC.2 (ADR 0073 section 4.2): Academic Department create/update, moved
 * from the API controller so the web pages and `/api/v1` share it. A
 * Department is a reference for Subjects only; it carries no HR or teacher
 * ownership semantics. Deactivation is reversible `status` (rule 73).
 */
class AcademicDepartmentAdministrationService
{
    use AdministersReferenceData;

    public const string CAPABILITY_MANAGE = 'academics.structure.manage';

    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly TenantContext $context,
    ) {}

    /** @param  array<string, mixed>  $input */
    public function create(School $school, array $input, User $actor): AcademicDepartment
    {
        $this->authorizeFor($actor, self::CAPABILITY_MANAGE, $school);
        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:32', Rule::unique('academic_departments')->where('school_id', $school->id)],
        ];
        $validated = $this->validateInput($input, $rules);

        return $this->context->withSchool($school, fn (): AcademicDepartment => $this->writeUnique(
            fn (): AcademicDepartment => DB::transaction(function () use ($school, $validated, $actor): AcademicDepartment {
                $department = AcademicDepartment::query()->create([...$validated, 'school_id' => $school->id]);

                $this->audit->school($school, 'academic_department.created', actor: $actor, subject: $department, metadata: $validated);

                return $department;
            }),
            fn () => $this->validateInput($input, $rules),
        ));
    }

    /** @param  array<string, mixed>  $input */
    public function update(School $school, string $departmentId, array $input, User $actor): AcademicDepartment
    {
        $this->authorizeFor($actor, self::CAPABILITY_MANAGE, $school);

        return $this->context->withSchool($school, function () use ($school, $departmentId, $input, $actor): AcademicDepartment {
            $department = AcademicDepartment::query()->findOrFail($departmentId);
            $rules = [
                'name' => ['sometimes', 'string', 'max:255'],
                'code' => ['sometimes', 'string', 'max:32', Rule::unique('academic_departments')->where('school_id', $school->id)->ignore($department->id)],
                'status' => ['sometimes', 'in:active,inactive'],
            ];
            $validated = $this->validateInput($input, $rules);
            $before = $department->only(array_keys($validated));

            $this->writeUnique(fn () => DB::transaction(function () use ($school, $department, $validated, $before, $actor): void {
                $department->update($validated);

                $this->audit->school($school, 'academic_department.updated', actor: $actor, subject: $department, metadata: [
                    'before' => $before,
                    'after' => $validated,
                ]);
            }), fn () => $this->validateInput($input, $rules));

            return $department->refresh();
        });
    }
}
