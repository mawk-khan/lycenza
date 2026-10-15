<?php

namespace App\Domain\AcademicStructure\Application\DataHealth;

use App\Domain\AcademicStructure\Infrastructure\AcademicYear;
use App\Models\School;
use App\Support\DataHealth\DataHealthCheck;
use App\Support\DataHealth\DataHealthCheckDefinition as D;
use App\Support\DataHealth\DataHealthFinding;
use App\Support\DataHealth\DataHealthLink;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;

/**
 * The School has no `active` AcademicYear. Current placement
 * (CurrentAcademicYearResolver), the Teaching Assignment and Enrollment
 * defaults, rosters and fee runs all resolve "this year" from it, so
 * day-to-day academic work has nothing to attach to.
 */
class NoActiveAcademicYearCheck implements DataHealthCheck
{
    public function definition(): D
    {
        return new D(
            code: 'academic_setup.no_active_year',
            area: 'Academic setup',
            title: 'No active academic year',
            condition: 'The School has no academic year marked active.',
            whyItMatters: 'Class placements, registers, teaching assignments and fee runs all work from the active academic year. Without one, current-year screens are empty and new placements cannot be made for the current year.',
            severity: D::BLOCKING,
            kind: D::INCOMPLETE,
            viewCapabilities: ['academics.years.view'],
            repair: 'Activate the right academic year in School setup → Academic years (activating one closes the previous year).',
        );
    }

    public function hasFindings(School $school): bool
    {
        return ! AcademicYear::query()->where('school_id', $school->id)->where('status', 'active')->exists();
    }

    public function findings(School $school, int $page, int $perPage): LengthAwarePaginator
    {
        $items = $this->hasFindings($school) ? [new DataHealthFinding(
            entityType: 'school',
            entityId: $school->id,
            label: $school->name,
            detail: 'No academic year is active',
            links: [
                new DataHealthLink('Academic years', '/app/school-setup/academic-years', ['academics.years.view']),
                new DataHealthLink('Activate a year', '/app/school-setup/academic-years', ['academics.years.manage'], isRepair: true),
            ],
        )] : [];

        return new Paginator($items, count($items), $perPage, $page);
    }
}
