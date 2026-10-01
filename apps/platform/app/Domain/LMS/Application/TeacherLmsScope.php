<?php

namespace App\Domain\LMS\Application;

use App\Domain\LMS\Application\Ownership\LmsResourceOwnership;
use App\Domain\TeachingAssignments\Application\OwnedTeachingPeriod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * TCH.5C/TCH.5D (ADR 0063 sections 34.7, 36, 37) -- what one teacher may SEE
 * of an LMS resource kind (Learning Content, Assignment) today. The rule is
 * the same for both kinds; each subclass names its table and audience
 * bridge. Today: their ActingEmployee, and the (Section,
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
 * lock by the kind's write guard (TeacherLmsGuard).
 */
abstract readonly class TeacherLmsScope
{
    /** The one shared (teacher-readable) status of both LMS lifecycles. */
    public const PUBLISHED = 'published';

    /** @var list<array{section: string, offering: string}> */
    public array $taught;

    /** The resource table, e.g. `learning_content`. */
    abstract protected function table(): string;

    /** The Section audience bridge, e.g. `learning_content_section_audiences`. */
    abstract protected function bridge(): string;

    /** The bridge's parent column, e.g. `learning_content_id`. */
    abstract protected function parentColumn(): string;

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
            return $status === self::PUBLISHED && $this->taughtSections($ownership->subjectOfferingId) !== [];
        }

        if ($this->isOwner($ownership) && $this->teachesEvery($ownership)) {
            return true;
        }

        return $status === self::PUBLISHED
            && array_intersect($ownership->audienceSectionIds, $this->taughtSections($ownership->subjectOfferingId)) !== [];
    }

    /** Fresh (non-locking) answer; the guard re-checks under lock. */
    public function canWrite(LmsResourceOwnership $ownership): bool
    {
        return $this->isOwner($ownership) && $this->teachesEvery($ownership);
    }

    /**
     * Filters a query on the kind's table to exactly what canRead() allows,
     * in SQL (nothing School-wide is loaded and filtered later).
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public function constrain(Builder $query): Builder
    {
        if ($this->taught === []) {
            return $query->whereRaw('false');
        }

        $table = $this->table();
        $audience = fn (QueryBuilder $q) => $q->selectRaw('1')->from($this->bridge().' as a')
            ->whereColumn('a.'.$this->parentColumn(), "{$table}.id");
        $taughtPair = function (QueryBuilder $q): void {
            $q->where(function (QueryBuilder $any) {
                foreach ($this->taught as $pair) {
                    $any->orWhere(fn (QueryBuilder $p) => $p->where('a.section_id', $pair['section'])->where('a.subject_offering_id', $pair['offering']));
                }
            });
        };

        return $query->where(function (Builder $visible) use ($audience, $taughtPair, $table) {
            // Own rows (any status) while every audience Section is taught.
            $visible->orWhere(fn (Builder $q) => $q->where("{$table}.owner_employee_id", $this->employeeId)
                ->whereNotExists(fn (QueryBuilder $s) => $audience($s)->whereNot($taughtPair)));
            // Published teacher-owned rows with any taught audience Section.
            $visible->orWhere(fn (Builder $q) => $q->where("{$table}.status", self::PUBLISHED)
                ->whereNotNull("{$table}.owner_employee_id")
                ->whereExists(fn (QueryBuilder $s) => $taughtPair($audience($s))));
            // Published Offering-wide rows of a taught Offering.
            $visible->orWhere(fn (Builder $q) => $q->where("{$table}.status", self::PUBLISHED)
                ->whereNull("{$table}.owner_employee_id")
                ->whereIn("{$table}.subject_offering_id", $this->taughtOfferings()));
        });
    }
}
