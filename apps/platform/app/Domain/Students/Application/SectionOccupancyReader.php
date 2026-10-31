<?php

namespace App\Domain\Students\Application;

use App\Domain\AcademicStructure\Infrastructure\Section;
use App\Models\School;
use App\Support\Tenancy\SchoolTimezone;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Collection;

/**
 * SIS 11.3 (OD-SIS-6): the occupancy of Sections a caller has ALREADY chosen
 * to show (an authorized option list, a page of Sections) -- in ONE grouped
 * query, whatever their number. Authorization-neutral: it adds a count and a
 * capacity to Sections the caller may already show, never another Section.
 *
 * Strength is StudentEnrollmentRosterReadService's (the ONE placement
 * predicate) as of the School-local today clamped into each Section's year;
 * a closed year's Section shows capacity only.
 */
final class SectionOccupancyReader
{
    public function __construct(
        private readonly StudentEnrollmentRosterReadService $roster,
        private readonly TenantContext $context,
    ) {}

    /**
     * @param  iterable<Section>  $sections  Sections of $school (their `capacity` is read from the models)
     * @return array<string, SectionOccupancy> by Section id
     */
    public function forSections(School $school, iterable $sections): array
    {
        $sections = Collection::make($sections)->keyBy(fn (Section $s) => (string) $s->id);
        if ($sections->isEmpty()) {
            return [];
        }

        $rows = $this->context->withSchool($school, fn () => $this->roster
            ->sectionStrengthQuery($school->id, SchoolTimezone::today($school))
            ->whereIn('s.id', $sections->keys()->all())
            ->get()
            ->keyBy(fn (object $r) => (string) $r->section_id));

        return $sections->map(fn (Section $s, string $id) => new SectionOccupancy(
            $s->capacity,
            ($row = $rows->get($id)) === null ? null : (int) $row->strength,
            $row === null ? null : substr((string) $row->as_of, 0, 10),
        ))->all();
    }
}
