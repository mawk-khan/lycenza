<?php

namespace App\Domain\AcademicStructure\Application;

use App\Domain\AcademicStructure\Application\Concerns\AdministersReferenceData;
use App\Domain\AcademicStructure\Application\Exceptions\SubjectOfferingClassificationLockedException;
use App\Domain\AcademicStructure\Events\SubjectOfferingCreated;
use App\Domain\AcademicStructure\Infrastructure\AcademicYear;
use App\Domain\AcademicStructure\Infrastructure\GradeLevel;
use App\Domain\AcademicStructure\Infrastructure\Subject;
use App\Domain\AcademicStructure\Infrastructure\SubjectOffering;
use App\Domain\Campuses\Application\ActiveCampus;
use App\Models\Campus;
use App\Models\School;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ADR 0069 (S1) -- the ONE update path for a SubjectOffering's mutable fields
 * (`is_required`, `sequence`, `weekly_periods_target`, `status`).
 *
 * The required/elective classification is frozen once the Offering has any
 * dependent academic evidence. The rule lives in the database
 * (`subject_offering_classification_freeze`, migration
 * 2026_12_09_090000): only it can see every dependent table without
 * Academic Structure depending on the modules that depend on it (CLAUDE.md
 * rule 4), and only it is race-free against the first evidence row (each
 * evidence insert holds the Offering FOR SHARE). This service takes the
 * Offering FOR UPDATE, writes, and translates the database refusal into a
 * domain error -- it never pre-checks dependent tables itself.
 *
 * A no-op classification (the same value) is not a change. Other fields stay
 * editable whatever the evidence. Audited as `subject_offering.updated`
 * (before/after of the submitted fields), unchanged.
 */
class SubjectOfferingService
{
    use AdministersReferenceData;

    public const string CAPABILITY_MANAGE = 'academics.subjects.manage';

    /**
     * The only fields an existing Offering may change. Its identity --
     * Year, Campus, Grade level, Subject -- never changes (rule 72), and
     * Attendance's subject provenance depends on that.
     */
    public const array MUTABLE_FIELDS = ['is_required', 'sequence', 'weekly_periods_target', 'status'];

    public const string INACTIVE_CAMPUS = 'This Subject Offering is on an inactive Campus and cannot be reactivated.';

    private const string DUPLICATE_OFFERING = 'This Subject is already offered for this Academic Year, Campus and Grade Level.';

    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly TenantContext $context,
        private readonly ActiveCampus $activeCampus,
    ) {}

    /**
     * @param  array{is_required?: bool, sequence?: int|null, weekly_periods_target?: int|null, status?: string}  $attributes
     */
    /**
     * SWC.2 (ADR 0073 section 4.2): create, moved from the API controller so
     * the web pages and `/api/v1` share it. An Offering is specific to one
     * Academic Year, Campus and Grade Level (rule 72) and unique per Subject
     * there. Correction (SWC.2): a duplicate Offering is now a field error.
     * Before, it reached `subject_offerings_unique_offering` as a 500.
     *
     * @param  array<string, mixed>  $input
     */
    public function create(School $school, string $academicYearId, array $input, User $actor): SubjectOffering
    {
        $this->authorizeFor($actor, self::CAPABILITY_MANAGE, $school);

        return $this->context->withSchool($school, function () use ($school, $academicYearId, $input, $actor): SubjectOffering {
            $year = AcademicYear::query()->findOrFail($academicYearId);
            $validated = $this->validateInput($input, [
                'campus_id' => ['required', 'string'],
                'grade_level_id' => ['required', 'string'],
                'subject_id' => ['required', 'string'],
                'is_required' => ['sometimes', 'boolean'],
                'sequence' => ['nullable', 'integer', 'min:0'],
                'weekly_periods_target' => ['nullable', 'integer', 'min:0'],
            ]);

            // Another School's Campus/Grade/Subject is invisible under SchoolScope: the same 404 as a missing one.
            Campus::query()->findOrFail($validated['campus_id']);
            GradeLevel::query()->findOrFail($validated['grade_level_id']);
            Subject::query()->findOrFail($validated['subject_id']);

            $assertUnique = function () use ($year, $validated): void {
                $taken = SubjectOffering::query()
                    ->where('academic_year_id', $year->id)
                    ->where('campus_id', $validated['campus_id'])
                    ->where('grade_level_id', $validated['grade_level_id'])
                    ->where('subject_id', $validated['subject_id'])
                    ->exists();

                if ($taken) {
                    throw ValidationException::withMessages(['subject_id' => [self::DUPLICATE_OFFERING]]);
                }
            };
            $assertUnique();

            return $this->writeUnique(fn (): SubjectOffering => DB::transaction(function () use ($school, $year, $validated, $actor): SubjectOffering {
                // ORG.7D-0 R5: no new Offering on an inactive Campus.
                $this->activeCampus->require($school, $validated['campus_id']);

                $offering = SubjectOffering::query()->create([
                    ...$validated,
                    // Explicit default (matching the migration's DB-level
                    // default) -- create() does not re-hydrate DB-defaulted
                    // columns onto the in-memory model automatically.
                    'is_required' => $validated['is_required'] ?? true,
                    'school_id' => $school->id,
                    'academic_year_id' => $year->id,
                ]);

                $this->audit->school($school, 'subject_offering.created', actor: $actor, subject: $offering, metadata: [
                    'academicYearId' => $year->id,
                    'gradeLevelId' => $offering->grade_level_id,
                    'subjectId' => $offering->subject_id,
                ]);

                event(new SubjectOfferingCreated(
                    $school->id, $offering->id, $year->id, $offering->grade_level_id, $offering->subject_id,
                ));

                return $offering;
            }), $assertUnique);
        });
    }

    /**
     * SWC.2: the validating entry point for update() shared by the API and
     * the web form (allowed fields only: classification, ordering, weekly
     * target, status). The classification freeze stays update()'s.
     *
     * @param  array<string, mixed>  $input
     */
    public function updateFromInput(School $school, string $subjectOfferingId, array $input, User $actor): SubjectOffering
    {
        $this->authorizeFor($actor, self::CAPABILITY_MANAGE, $school);
        $this->context->withSchool($school, fn () => SubjectOffering::query()->findOrFail($subjectOfferingId));

        $validated = $this->validateInput(array_intersect_key($input, array_flip(self::MUTABLE_FIELDS)), [
            'is_required' => ['sometimes', 'boolean'],
            'sequence' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'weekly_periods_target' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'status' => ['sometimes', 'in:active,inactive'],
        ]);

        return $this->update($school, $subjectOfferingId, $validated, $actor);
    }

    public function update(School $school, string $subjectOfferingId, array $attributes, User $actor): SubjectOffering
    {
        $this->authorizeFor($actor, self::CAPABILITY_MANAGE, $school);

        return $this->context->withSchool($school, fn (): SubjectOffering => DB::transaction(function () use ($school, $subjectOfferingId, $attributes, $actor): SubjectOffering {
            $offering = SubjectOffering::query()->where('school_id', $school->id)->lockForUpdate()->findOrFail($subjectOfferingId);
            $before = $offering->only(array_keys($attributes));

            // ORG.7D-1 D1-2 (ADR 0076 §37): reactivating puts the Offering
            // back into operation, so its Campus must be active. Data
            // validity only; an already-active Offering is unaffected.
            if (($attributes['status'] ?? null) === 'active' && $offering->status !== 'active'
                && $this->activeCampus->find($school, $offering->campus_id) === null) {
                throw ValidationException::withMessages(['status' => [self::INACTIVE_CAMPUS]]);
            }

            try {
                $offering->update($attributes);
            } catch (QueryException $e) {
                if (str_contains($e->getMessage(), 'subject_offering_classification_locked')) {
                    throw new SubjectOfferingClassificationLockedException;
                }
                throw $e;
            }

            $this->audit->school($school, 'subject_offering.updated', actor: $actor, subject: $offering, metadata: [
                'before' => $before,
                'after' => $attributes,
            ]);

            return $offering->refresh();
        }));
    }
}
