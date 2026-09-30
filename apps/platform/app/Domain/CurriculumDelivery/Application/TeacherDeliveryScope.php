<?php

namespace App\Domain\CurriculumDelivery\Application;

use App\Domain\CurriculumDelivery\Infrastructure\CurriculumDelivery;
use App\Domain\TeachingAssignments\Application\OwnedTeachingPeriod;
use Illuminate\Database\Eloquent\Builder;

/**
 * TCH.3: what one verified acting teacher may SEE of Curriculum Delivery --
 * the Section + SubjectOffering periods their TeachingAssignments cover.
 *
 * Visibility rule (the read side of ADR 0063 section 11): a delivery is
 * visible when one of the teacher's periods for its exact Section and
 * SubjectOffering overlaps the delivery's own interval
 * [started_on, completed_on] (open while in progress). A completed unit
 * taught entirely under another teacher's assignment is not visible; an
 * in-progress unit is visible to a teacher who owns the class after it
 * started, so they can complete it. Filtering happens in the query; an
 * unowned row never leaves the server.
 */
final readonly class TeacherDeliveryScope
{
    /**
     * @param  list<OwnedTeachingPeriod>  $periods
     */
    public function __construct(
        public string $employeeId,
        public string $asOf,
        public array $periods,
    ) {}

    public function ownsContext(string $sectionId, string $subjectOfferingId): bool
    {
        return $this->periodsFor($sectionId, $subjectOfferingId) !== [];
    }

    public function canSee(CurriculumDelivery $delivery): bool
    {
        $from = $delivery->started_on->toDateString();
        $to = $delivery->completed_on?->toDateString();

        foreach ($this->periodsFor($delivery->section_id, $delivery->subject_offering_id) as $period) {
            if ($period->overlaps($from, $to)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Restricts a CurriculumDelivery query to visible rows (an empty scope
     * matches nothing).
     *
     * @param  Builder<CurriculumDelivery>  $query
     * @return Builder<CurriculumDelivery>
     */
    public function constrain(Builder $query, string $table = 'curriculum_deliveries'): Builder
    {
        if ($this->periods === []) {
            return $query->whereRaw('false');
        }

        return $query->where(function (Builder $any) use ($table) {
            foreach ($this->periods as $period) {
                $any->orWhere(function (Builder $q) use ($table, $period) {
                    $q->where("{$table}.section_id", $period->sectionId)
                        ->where("{$table}.subject_offering_id", $period->subjectOfferingId)
                        ->where(fn (Builder $end) => $end->whereNull("{$table}.completed_on")->orWhere("{$table}.completed_on", '>=', $period->startsOn));

                    if ($period->endsOn !== null) {
                        $q->where("{$table}.started_on", '<=', $period->endsOn);
                    }
                });
            }
        });
    }

    /**
     * The distinct owned (Section, SubjectOffering) contexts.
     *
     * @return list<array{sectionId: string, subjectOfferingId: string, periods: list<OwnedTeachingPeriod>}>
     */
    public function contexts(): array
    {
        $contexts = [];
        foreach ($this->periods as $period) {
            $key = $period->sectionId.'|'.$period->subjectOfferingId;
            $contexts[$key] ??= ['sectionId' => $period->sectionId, 'subjectOfferingId' => $period->subjectOfferingId, 'periods' => []];
            $contexts[$key]['periods'][] = $period;
        }

        return array_values($contexts);
    }

    /** @return list<OwnedTeachingPeriod> */
    private function periodsFor(string $sectionId, string $subjectOfferingId): array
    {
        return array_values(array_filter(
            $this->periods,
            fn (OwnedTeachingPeriod $p) => $p->sectionId === $sectionId && $p->subjectOfferingId === $subjectOfferingId,
        ));
    }
}
