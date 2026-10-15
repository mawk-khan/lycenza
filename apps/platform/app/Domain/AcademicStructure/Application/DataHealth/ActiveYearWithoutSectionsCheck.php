<?php

namespace App\Domain\AcademicStructure\Application\DataHealth;

use App\Domain\AcademicStructure\Infrastructure\AcademicYear;
use App\Domain\AcademicStructure\Infrastructure\Section;
use App\Models\School;
use App\Support\DataHealth\DataHealthCheck;
use App\Support\DataHealth\DataHealthCheckDefinition as D;
use App\Support\DataHealth\DataHealthFinding;
use App\Support\DataHealth\DataHealthLink;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;

/**
 * The active AcademicYear has no `active` Section. A Section belongs to
 * exactly one AcademicYear (CLAUDE.md rule 66) and every placement needs
 * one, so no Student can be placed for the current year.
 */
class ActiveYearWithoutSectionsCheck implements DataHealthCheck
{
    public function definition(): D
    {
        return new D(
            code: 'academic_setup.active_year_without_sections',
            area: 'Academic setup',
            title: 'Active academic year has no Sections',
            condition: 'The active academic year has no active Sections.',
            whyItMatters: 'Every class placement belongs to a Section of its year, so no Student can be placed for the current year until Sections exist.',
            severity: D::BLOCKING,
            kind: D::INCOMPLETE,
            viewCapabilities: ['academics.structure.view'],
            repair: 'Create the year\'s Sections in School setup → Sections.',
        );
    }

    public function hasFindings(School $school): bool
    {
        return $this->activeYearWithoutSections($school) !== null;
    }

    public function findings(School $school, int $page, int $perPage): LengthAwarePaginator
    {
        $year = $this->activeYearWithoutSections($school);
        $items = $year === null ? [] : [new DataHealthFinding(
            entityType: 'academic_year',
            entityId: $year->id,
            label: $year->name,
            detail: 'Active · no active Sections',
            links: [
                new DataHealthLink('Sections', '/app/school-setup/sections', ['academics.structure.view']),
                new DataHealthLink('Create Sections', '/app/school-setup/sections', ['academics.structure.manage'], isRepair: true),
            ],
        )];

        return new Paginator($items, count($items), $perPage, $page);
    }

    private function activeYearWithoutSections(School $school): ?AcademicYear
    {
        $year = AcademicYear::query()->where('school_id', $school->id)->where('status', 'active')->first();
        if ($year === null) {
            return null;
        }

        $hasSection = Section::query()->where('school_id', $school->id)
            ->where('academic_year_id', $year->id)->where('status', 'active')->exists();

        return $hasSection ? null : $year;
    }
}
