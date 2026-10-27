<?php

namespace App\Domain\AcademicStructure\Application;

use App\Domain\AcademicStructure\Application\Concerns\AdministersReferenceData;
use App\Domain\AcademicStructure\Application\Exceptions\AcademicCalendarChangedException;
use App\Domain\AcademicStructure\Application\Exceptions\AcademicCalendarDatesFixedException;
use App\Domain\AcademicStructure\Application\Exceptions\AcademicTermOutOfRangeException;
use App\Domain\AcademicStructure\Application\Exceptions\AcademicTermOverlapException;
use App\Domain\AcademicStructure\Application\Exceptions\AcademicYearClosedForStructureException;
use App\Domain\AcademicStructure\Events\AcademicTermCreated;
use App\Domain\AcademicStructure\Infrastructure\AcademicTerm;
use App\Domain\AcademicStructure\Infrastructure\AcademicYear;
use App\Models\School;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Phase 0D sections 19-21: the only sanctioned write path for Academic
 * Terms. `starts_on`/`ends_on` must fall within the parent Academic
 * Year's own range, and terms within the same year must not overlap --
 * both application-layer checks (the migration's composite FK only
 * protects the School/Year *relationship*, not date semantics).
 */
class AcademicTermService
{
    use AdministersReferenceData;

    /** Terms are part of the Academic Year calendar: the year capabilities govern them. */
    public const string CAPABILITY_MANAGE = 'academics.years.manage';

    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly TenantContext $context,
    ) {}

    /**
     * @param  array{name: string, code: string, starts_on: string, ends_on: string, sequence: int}  $attributes
     */
    public function create(AcademicYear $year, array $attributes, ?User $actor = null): AcademicTerm
    {
        return $this->context->withSchool($year->school, fn () => DB::transaction(function () use ($year, $attributes, $actor) {
            // SIS 5.1: the year's dates are read FOR SHARE, so a concurrent
            // correction of a draft year's dates either is seen or waits.
            $year = AcademicYear::query()->whereKey($year->id)->sharedLock()->firstOrFail();
            // SIS 5.2: a closed year keeps its Terms but takes no new one.
            if ($year->status === 'closed') {
                throw new AcademicYearClosedForStructureException($year->name, 'Term');
            }
            $this->assertWithinYear($year, $attributes['starts_on'], $attributes['ends_on']);
            $this->assertNoOverlap($year, $attributes['starts_on'], $attributes['ends_on']);

            $term = AcademicTerm::query()->create([
                'school_id' => $year->school_id,
                'academic_year_id' => $year->id,
                'name' => $attributes['name'],
                'code' => $attributes['code'],
                'starts_on' => $attributes['starts_on'],
                'ends_on' => $attributes['ends_on'],
                'sequence' => $attributes['sequence'],
            ]);

            $this->audit->school($year->school, 'academic_term.created', actor: $actor, subject: $term, metadata: [
                'academicYearId' => $year->id,
                'name' => $term->name,
            ]);

            event(new AcademicTermCreated($year->school_id, $year->id, $term->id, $term->name));

            return $term;
        }));
    }

    /**
     * SWC.2 (ADR 0073 section 4.2): the validating entry point shared by the
     * API and the web form. Range and overlap stay create()'s invariants.
     *
     * @param  array<string, mixed>  $input
     */
    public function createFromInput(School $school, string $academicYearId, array $input, User $actor): AcademicTerm
    {
        $this->authorizeFor($actor, self::CAPABILITY_MANAGE, $school);

        return $this->context->withSchool($school, function () use ($school, $academicYearId, $input, $actor): AcademicTerm {
            $year = AcademicYear::query()->findOrFail($academicYearId);
            $rules = [
                'name' => ['required', 'string', 'max:255'],
                'code' => ['required', 'string', 'max:32', Rule::unique('academic_terms')->where('school_id', $school->id)->where('academic_year_id', $year->id)],
                'starts_on' => ['required', 'date'],
                'ends_on' => ['required', 'date', 'after:starts_on'],
                'sequence' => ['required', 'integer', 'min:1'],
            ];
            $validated = $this->validateInput($input, $rules);

            return $this->writeUnique(fn (): AcademicTerm => $this->create($year, $validated, $actor), fn () => $this->validateInput($input, $rules));
        });
    }

    /**
     * SWC.2: renaming only, moved from the API controller -- dates and
     * sequence never change here (they would bypass the range/overlap
     * invariants create() enforces).
     *
     * @param  array<string, mixed>  $input
     */
    public function rename(School $school, string $academicTermId, array $input, User $actor): AcademicTerm
    {
        $this->authorizeFor($actor, self::CAPABILITY_MANAGE, $school);

        return $this->context->withSchool($school, function () use ($school, $academicTermId, $input, $actor): AcademicTerm {
            $term = AcademicTerm::query()->findOrFail($academicTermId);
            $rules = [
                'name' => ['sometimes', 'string', 'max:255'],
                'code' => ['sometimes', 'string', 'max:32', Rule::unique('academic_terms')->where('school_id', $school->id)->where('academic_year_id', $term->academic_year_id)->ignore($term->id)],
            ];
            $validated = $this->validateInput($input, $rules);
            $before = $term->only(array_keys($validated));

            $this->writeUnique(fn () => DB::transaction(function () use ($school, $term, $validated, $before, $actor): void {
                $term->update($validated);

                $this->audit->school($school, 'academic_term.updated', actor: $actor, subject: $term, metadata: [
                    'before' => $before,
                    'after' => $validated,
                ]);
            }), fn () => $this->validateInput($input, $rules));

            return $term->refresh();
        });
    }

    /**
     * SIS 5.1 (MC-3, ADR 0077): correct a Term's dates while its Academic Year
     * is DRAFT -- dates only (name and code keep rename(); the sequence never
     * changes). Under the year's FOR UPDATE lock, so it serializes with the
     * year's own correction, its activation and other Term corrections: the
     * year must still be draft, the Term unchanged since the form was opened,
     * and the new dates inside the year without overlapping another Term.
     *
     * @param  array<string, mixed>  $input
     */
    public function correctDraftDates(School $school, string $academicTermId, array $input, User $actor): AcademicTerm
    {
        $this->authorizeFor($actor, self::CAPABILITY_MANAGE, $school);
        $validated = $this->validateInput($input, [
            'starts_on' => ['required', 'date_format:Y-m-d'],
            'ends_on' => ['required', 'date_format:Y-m-d', 'after:starts_on'],
            'expected_updated_at' => ['required', 'string', 'max:64'],
        ]);

        return $this->context->withSchool($school, function () use ($school, $academicTermId, $validated, $actor): AcademicTerm {
            // Discovery only: which year to lock. The Term is re-read under it.
            $yearId = AcademicTerm::query()->where('school_id', $school->id)->findOrFail($academicTermId)->academic_year_id;

            return DB::transaction(function () use ($school, $academicTermId, $yearId, $validated, $actor): AcademicTerm {
                $year = AcademicYear::query()->where('school_id', $school->id)->lockForUpdate()->findOrFail($yearId);
                $term = AcademicTerm::query()->where('academic_year_id', $year->id)->lockForUpdate()->findOrFail($academicTermId);

                if ($year->status !== 'draft') {
                    throw new AcademicCalendarDatesFixedException($year->status);
                }
                if ($term->updated_at?->toIso8601String() !== $validated['expected_updated_at']) {
                    throw new AcademicCalendarChangedException;
                }

                $before = ['starts_on' => $term->starts_on->toDateString(), 'ends_on' => $term->ends_on->toDateString()];
                $after = ['starts_on' => $validated['starts_on'], 'ends_on' => $validated['ends_on']];
                if ($before === $after) {
                    return $term;
                }

                $this->assertWithinYear($year, $after['starts_on'], $after['ends_on']);
                $this->assertNoOverlap($year, $after['starts_on'], $after['ends_on'], $term->id);

                $term->update($after);

                $this->audit->school($school, 'academic_term.dates_corrected', actor: $actor, subject: $term, metadata: [
                    'academicYearId' => $year->id,
                    'changed' => array_keys(array_diff_assoc($after, $before)),
                    'before' => $before,
                    'after' => $after,
                ]);

                return $term->refresh();
            });
        });
    }

    private function assertWithinYear(AcademicYear $year, string $startsOn, string $endsOn): void
    {
        if ($startsOn < $year->starts_on->toDateString() || $endsOn > $year->ends_on->toDateString()) {
            throw new AcademicTermOutOfRangeException;
        }
    }

    private function assertNoOverlap(AcademicYear $year, string $startsOn, string $endsOn, ?string $exceptTermId = null): void
    {
        $overlaps = AcademicTerm::query()
            ->where('academic_year_id', $year->id)
            ->when($exceptTermId !== null, fn ($q) => $q->whereKeyNot($exceptTermId))
            ->where('starts_on', '<', $endsOn)
            ->where('ends_on', '>', $startsOn)
            ->exists();

        if ($overlaps) {
            throw new AcademicTermOverlapException;
        }
    }
}
