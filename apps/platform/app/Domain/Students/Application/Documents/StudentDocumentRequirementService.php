<?php

namespace App\Domain\Students\Application\Documents;

use App\Domain\AcademicStructure\Infrastructure\AcademicYear;
use App\Domain\AcademicStructure\Infrastructure\GradeLevel;
use App\Domain\Documents\Application\DocumentService;
use App\Domain\Students\Infrastructure\StudentDocumentRequirement;
use App\Models\Campus;
use App\Models\School;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Tenancy\SchoolTimezone;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * SIS 11.5: a School's document requirement rules -- the ONE writer.
 * Whole-School `students.documents.manage` (for Student and Applicant
 * rules alike). A rule names a closed category (StudentDocumentCategory::
 * requirable()), Required or Optional, one academic year that is not
 * closed, optionally one Campus and/or Grade of the School, and the date
 * it applies from. Closed dimensions only: no expression, no person
 * attribute. Terms never change (database guard): a correction is retire +
 * a new rule, both audited, so history stays answerable.
 */
final class StudentDocumentRequirementService
{
    use AuthorizesCapability;

    public function __construct(
        private readonly TenantContext $context,
        private readonly AuditRecorder $audit,
    ) {}

    /** @param  array<string, mixed>  $input */
    public function create(School $school, User $actor, array $input): StudentDocumentRequirement
    {
        $this->authorizeCapabilityFor($actor, DocumentService::STUDENT_DOCUMENTS_MANAGE, $school);

        return $this->context->withSchool($school, function () use ($school, $actor, $input): StudentDocumentRequirement {
            $data = Validator::make($input, [
                'subject' => ['required', Rule::in([StudentDocumentRequirement::STUDENT, StudentDocumentRequirement::APPLICANT])],
                'category' => ['required', Rule::in(StudentDocumentCategory::requirableValues())],
                'required' => ['required', 'boolean'],
                'academic_year_id' => ['required', 'uuid'],
                'campus_id' => ['nullable', 'uuid'],
                'grade_level_id' => ['nullable', 'uuid'],
                'effective_from' => ['nullable', 'date_format:Y-m-d'],
            ])->validate();

            // This School's own rows only (a foreign or unknown id is a field error).
            $year = AcademicYear::query()->find($data['academic_year_id']);
            if ($year === null || $year->status === 'closed') {
                throw ValidationException::withMessages(['academic_year_id' => 'Choose a draft or active academic year.']);
            }
            if (($data['campus_id'] ?? null) !== null && ! Campus::query()->whereKey($data['campus_id'])->exists()) {
                throw ValidationException::withMessages(['campus_id' => 'Choose a Campus from the list.']);
            }
            if (($data['grade_level_id'] ?? null) !== null && ! GradeLevel::query()->whereKey($data['grade_level_id'])->exists()) {
                throw ValidationException::withMessages(['grade_level_id' => 'Choose a Grade from the list.']);
            }

            return DB::transaction(function () use ($school, $actor, $data): StudentDocumentRequirement {
                $rule = new StudentDocumentRequirement;
                $rule->forceFill([
                    'school_id' => $school->id,
                    'subject' => $data['subject'],
                    'category' => $data['category'],
                    'required' => (bool) $data['required'],
                    'academic_year_id' => $data['academic_year_id'],
                    'campus_id' => $data['campus_id'] ?? null,
                    'grade_level_id' => $data['grade_level_id'] ?? null,
                    'effective_from' => $data['effective_from'] ?? SchoolTimezone::today($school),
                    'created_by_user_id' => $actor->id,
                ])->save();

                $this->audit->school($school, 'student_document_requirement.created', actor: $actor, subject: $rule, metadata: [
                    'requirementId' => $rule->id,
                    'subject' => $rule->subject,
                    'category' => $rule->category->value,
                    'required' => $rule->required,
                    'academicYearId' => $rule->academic_year_id,
                    'campusId' => $rule->campus_id,
                    'gradeLevelId' => $rule->grade_level_id,
                    'effectiveFrom' => $rule->effective_from->toDateString(),
                ]);

                return $rule;
            });
        });
    }

    /** Retire a rule: it stops applying at once; evidence collected under it stays. */
    public function retire(School $school, User $actor, string $requirementId): StudentDocumentRequirement
    {
        $this->authorizeCapabilityFor($actor, DocumentService::STUDENT_DOCUMENTS_MANAGE, $school);

        return $this->context->withSchool($school, fn () => DB::transaction(function () use ($school, $actor, $requirementId): StudentDocumentRequirement {
            $rule = StudentDocumentRequirement::query()->lockForUpdate()->findOrFail($requirementId);
            if ($rule->retired_at !== null) {
                throw ValidationException::withMessages(['requirement' => 'This requirement is already retired.']);
            }
            $rule->forceFill(['retired_at' => now(), 'retired_by_user_id' => $actor->id])->save();
            $this->audit->school($school, 'student_document_requirement.retired', actor: $actor, subject: $rule, metadata: [
                'requirementId' => $rule->id,
                'category' => $rule->category->value,
                'subject' => $rule->subject,
            ]);

            return $rule;
        }));
    }

    /** @return Collection<int, StudentDocumentRequirement> active first, newest first */
    public function list(School $school): Collection
    {
        return $this->context->withSchool($school, fn () => StudentDocumentRequirement::query()
            ->orderByRaw('retired_at IS NOT NULL')->orderByDesc('created_at')->get());
    }
}
