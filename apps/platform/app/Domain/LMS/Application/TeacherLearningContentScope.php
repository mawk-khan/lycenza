<?php

namespace App\Domain\LMS\Application;

use App\Domain\LMS\Application\Ownership\LmsResourceOwnership;
use App\Domain\LMS\Infrastructure\LearningContent;
use App\Domain\TeachingAssignments\Application\OwnedTeachingPeriod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * TCH.5C (ADR 0063 sections 34.7, 36) -- what one teacher may SEE of
 * Learning Content today: their ActingEmployee, and the (Section,
 * SubjectOffering) pairs a TeachingAssignment covers on the School-local
 * current date (never created_at, an audit date or the Timetable).
 *
 * - their own row, any status: only while they teach EVERY audience Section;
 * - a published teacher-owned row: while they teach ANY audience Section;
 * - a published Offering-wide (owner NULL) row: while they teach any
 *   Section of its Offering;
 * - nothing else (another teacher's unpublished row, an unpublished
 *   Offering-wide row, another School).
 *
 * Writing needs more: ownership AND every audience Section, re-checked under
 * lock by TeacherLearningContentGuard.
 */
final readonly class TeacherLearningContentScope
{
    /** @var list<array{section: string, offering: string}> */
    public array $taught;

    /** @param  list<OwnedTeachingPeriod>  $periods */
    public function __construct(
        public string $employeeId,
        public string $asOf,
        array $periods,
    ) {
        $taught = [];
        foreach ($periods as $period) {
            if ($period->covers($asOf)) {
                $taught[$period->sectionId.'|'.$period->subjectOfferingId] = ['section' => $period->sectionId, 'offering' => $period->subjectOfferingId];
            }
        }
        $this->taught = array_values($taught);
    }

    /** @return list<string> the Sections of $offeringId taught today, sorted */
    public function taughtSections(string $offeringId): array
    {
        $sections = array_values(array_map(fn (array $p) => $p['section'], array_filter($this->taught, fn (array $p) => $p['offering'] === $offeringId)));
        sort($sections);

        return $sections;
    }

    /** @return list<string> */
    public function taughtOfferings(): array
    {
        return array_values(array_unique(array_map(fn (array $p) => $p['offering'], $this->taught)));
    }

    public function isOwner(LmsResourceOwnership $ownership): bool
    {
        return $ownership->ownerEmployeeId !== null && $ownership->ownerEmployeeId === $this->employeeId;
    }

    public function teachesEvery(LmsResourceOwnership $ownership): bool
    {
        return $ownership->audienceSectionIds !== []
            && array_diff($ownership->audienceSectionIds, $this->taughtSections($ownership->subjectOfferingId)) === [];
    }

    public function canRead(LmsResourceOwnership $ownership, string $status): bool
    {
        if ($ownership->isOfferingWide()) {
            return $status === LearningContent::STATUS_PUBLISHED && $this->taughtSections($ownership->subjectOfferingId) !== [];
        }

        if ($this->isOwner($ownership) && $this->teachesEvery($ownership)) {
            return true;
        }

        return $status === LearningContent::STATUS_PUBLISHED
            && array_intersect($ownership->audienceSectionIds, $this->taughtSections($ownership->subjectOfferingId)) !== [];
    }

    /** Fresh (non-locking) answer; the guard re-checks under lock. */
    public function canWrite(LmsResourceOwnership $ownership): bool
    {
        return $this->isOwner($ownership) && $this->teachesEvery($ownership);
    }

    /**
     * Filters a Learning Content query to exactly what canRead() allows, in
     * SQL (nothing School-wide is loaded and filtered later).
     *
     * @param  Builder<LearningContent>  $query
     * @return Builder<LearningContent>
     */
    public function constrain(Builder $query): Builder
    {
        if ($this->taught === []) {
            return $query->whereRaw('false');
        }

        $audience = fn (QueryBuilder $q) => $q->selectRaw('1')->from('learning_content_section_audiences as a')
            ->whereColumn('a.learning_content_id', 'learning_content.id');
        $taughtPair = function (QueryBuilder $q): void {
            $q->where(function (QueryBuilder $any) {
                foreach ($this->taught as $pair) {
                    $any->orWhere(fn (QueryBuilder $p) => $p->where('a.section_id', $pair['section'])->where('a.subject_offering_id', $pair['offering']));
                }
            });
        };

        return $query->where(function (Builder $visible) use ($audience, $taughtPair) {
            // Own rows (any status) while every audience Section is taught.
            $visible->orWhere(fn (Builder $q) => $q->where('learning_content.owner_employee_id', $this->employeeId)
                ->whereNotExists(fn (QueryBuilder $s) => $audience($s)->whereNot($taughtPair)));
            // Published teacher-owned rows with any taught audience Section.
            $visible->orWhere(fn (Builder $q) => $q->where('learning_content.status', LearningContent::STATUS_PUBLISHED)
                ->whereNotNull('learning_content.owner_employee_id')
                ->whereExists(fn (QueryBuilder $s) => $taughtPair($audience($s))));
            // Published Offering-wide rows of a taught Offering.
            $visible->orWhere(fn (Builder $q) => $q->where('learning_content.status', LearningContent::STATUS_PUBLISHED)
                ->whereNull('learning_content.owner_employee_id')
                ->whereIn('learning_content.subject_offering_id', $this->taughtOfferings()));
        });
    }
}
