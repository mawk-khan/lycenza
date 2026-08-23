<?php

namespace App\Domain\AcademicStructure\Application;

use App\Domain\AcademicStructure\Application\Exceptions\AcademicTermOutOfRangeException;
use App\Domain\AcademicStructure\Application\Exceptions\AcademicTermOverlapException;
use App\Domain\AcademicStructure\Events\AcademicTermCreated;
use App\Domain\AcademicStructure\Infrastructure\AcademicTerm;
use App\Domain\AcademicStructure\Infrastructure\AcademicYear;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Phase 0D sections 19-21: the only sanctioned write path for Academic
 * Terms. `starts_on`/`ends_on` must fall within the parent Academic
 * Year's own range, and terms within the same year must not overlap --
 * both application-layer checks (the migration's composite FK only
 * protects the School/Year *relationship*, not date semantics).
 */
class AcademicTermService
{
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

    private function assertWithinYear(AcademicYear $year, string $startsOn, string $endsOn): void
    {
        if ($startsOn < $year->starts_on->toDateString() || $endsOn > $year->ends_on->toDateString()) {
            throw new AcademicTermOutOfRangeException;
        }
    }

    private function assertNoOverlap(AcademicYear $year, string $startsOn, string $endsOn): void
    {
        $overlaps = AcademicTerm::query()
            ->where('academic_year_id', $year->id)
            ->where('starts_on', '<', $endsOn)
            ->where('ends_on', '>', $startsOn)
            ->exists();

        if ($overlaps) {
            throw new AcademicTermOverlapException;
        }
    }
}
