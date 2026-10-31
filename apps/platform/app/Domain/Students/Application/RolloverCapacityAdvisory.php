<?php

namespace App\Domain\Students\Application;

use App\Domain\AcademicStructure\Application\SectionLabel;
use App\Domain\AcademicStructure\Infrastructure\Section;
use App\Domain\Students\Infrastructure\EnrollmentRolloverPlan;
use App\Models\School;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * SIS 11.3 (OD-SIS-6): the ADVISORY capacity warning of a rollover plan --
 * for each target Section with a capacity, the Students already placed in it
 * (its strength, SectionOccupancyReader) plus the plan's promote/repeat items
 * not yet executed that resolve to it. A warning only: it never blocks
 * validation or execution, and it is never a roll-number collision (that is
 * the dry run's own hard rule).
 *
 * The planned target follows EnrollmentRolloverItemExecutionService::
 * resolveTargetSectionId()'s precedence exactly -- the item's own target
 * Section, else the Plan's Section-specific mapping for the source Section,
 * else its Grade-level default -- in one grouped query (pinned by
 * SectionCapacityTest against the executed result). An item without a
 * resolvable target is not counted (the dry run blocks it anyway).
 */
final class RolloverCapacityAdvisory
{
    public function __construct(
        private readonly SectionOccupancyReader $occupancy,
        private readonly TenantContext $context,
    ) {}

    /**
     * @return list<array{sectionId: string, section: string, capacity: int, placed: int, planned: int, over: int, message: string}>
     */
    public function warnings(School $school, EnrollmentRolloverPlan $plan): array
    {
        return $this->context->withSchool($school, function () use ($school, $plan): array {
            $planned = DB::table('enrollment_rollover_items as i')
                ->join('student_enrollments as se', fn ($j) => $j->on('se.id', '=', 'i.source_enrollment_id')->where('se.school_id', '=', $school->id))
                ->leftJoin('enrollment_rollover_mappings as ms', fn ($j) => $j->on('ms.plan_id', '=', 'i.plan_id')->on('ms.source_section_id', '=', 'se.section_id'))
                ->leftJoin('enrollment_rollover_mappings as mg', fn ($j) => $j->on('mg.plan_id', '=', 'i.plan_id')->whereNull('mg.source_section_id')->on('mg.source_grade_level_id', '=', 'se.grade_level_id'))
                ->where('i.plan_id', $plan->id)
                ->where('i.school_id', $school->id)
                ->whereIn('i.decision', ['promote', 'repeat'])
                ->whereNull('i.target_enrollment_id')
                ->groupByRaw('coalesce(i.target_section_id, ms.target_section_id, mg.target_section_id)')
                ->selectRaw('coalesce(i.target_section_id, ms.target_section_id, mg.target_section_id) AS section_id, count(*) AS planned')
                ->get()
                ->filter(fn (object $r) => $r->section_id !== null)
                ->mapWithKeys(fn (object $r) => [(string) $r->section_id => (int) $r->planned]);

            $sections = Section::query()->where('academic_year_id', $plan->target_academic_year_id)->whereNotNull('capacity')
                ->with(['gradeLevel:id,name', 'campus:id,name'])->get();
            $occupancy = $this->occupancy->forSections($school, $sections);

            $warnings = [];
            foreach ($sections as $section) {
                $placed = $occupancy[(string) $section->id]->strength ?? 0;
                $add = $planned->get((string) $section->id, 0);
                $over = $placed + $add - (int) $section->capacity;
                if ($add > 0 && $over > 0) {
                    $name = SectionLabel::of($section).' · '.$section->campus->name;
                    $warnings[] = [
                        'sectionId' => (string) $section->id,
                        'section' => $name,
                        'capacity' => (int) $section->capacity,
                        'placed' => $placed,
                        'planned' => $add,
                        'over' => $over,
                        'message' => "{$name} has an advisory capacity of {$section->capacity}; {$placed} already placed and {$add} planned would make ".($placed + $add).", over by {$over}.",
                    ];
                }
            }

            return $warnings;
        });
    }
}
