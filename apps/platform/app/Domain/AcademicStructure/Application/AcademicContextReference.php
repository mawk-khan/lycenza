<?php

namespace App\Domain\AcademicStructure\Application;

use App\Domain\AcademicStructure\Infrastructure\Section;
use App\Domain\AcademicStructure\Infrastructure\SubjectOffering;
use App\Models\School;
use App\Support\Tenancy\TenantContext;

/**
 * HR.13: Academic Structure's read-only answer for another module that only
 * LABELS work with an academic context (a staff task "for Grade 6 · A",
 * "for Mathematics · Grade 6") -- the active year's active Sections and
 * Subject offerings as options, and labels for given ids. It grants nothing:
 * a reference is never a teaching assignment, ownership or Student access.
 * Another School's id yields nothing.
 */
final class AcademicContextReference
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly CurrentAcademicYearResolver $years,
    ) {}

    /** @return array{sections: list<array{id: string, label: string}>, subjectOfferings: list<array{id: string, label: string}>} */
    public function options(School $school): array
    {
        return $this->context->withSchool($school, function () use ($school): array {
            $year = $this->years->tryResolve($school);
            if ($year === null) {
                return ['sections' => [], 'subjectOfferings' => []];
            }

            return [
                'sections' => $this->sections($school, fn ($q) => $q->where('academic_year_id', $year->id)->where('status', 'active')),
                'subjectOfferings' => $this->offerings($school, fn ($q) => $q->where('academic_year_id', $year->id)->where('status', 'active')),
            ];
        });
    }

    /**
     * @param  list<string>  $sectionIds
     * @param  list<string>  $subjectOfferingIds
     * @return array{sections: array<string, string>, subjectOfferings: array<string, string>}
     */
    public function labels(School $school, array $sectionIds, array $subjectOfferingIds): array
    {
        return $this->context->withSchool($school, fn (): array => [
            'sections' => $sectionIds === [] ? [] : array_column($this->sections($school, fn ($q) => $q->whereIn('id', $sectionIds)), 'label', 'id'),
            'subjectOfferings' => $subjectOfferingIds === [] ? [] : array_column($this->offerings($school, fn ($q) => $q->whereIn('id', $subjectOfferingIds)), 'label', 'id'),
        ]);
    }

    /** @return list<array{id: string, label: string}> */
    private function sections(School $school, callable $scope): array
    {
        return Section::query()->where('school_id', $school->id)->tap($scope)->with(['gradeLevel', 'academicYear'])->get()
            ->sortBy(fn (Section $s) => SectionLabel::sortKey($s))
            ->map(fn (Section $s) => ['id' => $s->id, 'label' => (string) SectionLabel::of($s)])->values()->all();
    }

    /** @return list<array{id: string, label: string}> */
    private function offerings(School $school, callable $scope): array
    {
        return SubjectOffering::query()->where('school_id', $school->id)->tap($scope)->with(['subject', 'gradeLevel'])->get()
            ->map(fn (SubjectOffering $o) => ['id' => $o->id, 'label' => trim($o->subject->name.' · '.$o->gradeLevel->name, ' ·')])
            ->sortBy('label', SORT_NATURAL | SORT_FLAG_CASE)->values()->all();
    }
}
