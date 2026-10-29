<?php

namespace App\Domain\Platform\Application\Groups;

use Illuminate\Database\Query\Builder;

/**
 * ORG.12E (ADR 0080 §10.3, OD-12.14): which member-School memberships of ONE
 * Group a Network-governance READ may show.
 *
 * - whole Group: every membership, including unclassified and unplaced ones;
 * - a Region: memberships whose Region is that Region -- placed directly or
 *   through one of its Zones (ORG.12D's placement trigger keeps a member's
 *   Region equal to its Zone's);
 * - a Zone: memberships in exactly that Zone.
 *
 * Unplaced memberships are whole-Group only. A scope is governance metadata
 * visibility, never a School scope.
 */
final class GroupGovernanceScope
{
    /**
     * @param  list<string>  $regionIds
     * @param  list<string>  $zoneIds
     */
    public function __construct(
        public readonly bool $wholeGroup,
        public readonly array $regionIds = [],
        public readonly array $zoneIds = [],
    ) {}

    public static function whole(): self
    {
        return new self(true);
    }

    /** Narrows a query over `school_group_members` (aliased $alias) to this scope. */
    public function constrain(Builder $query, string $alias = 'm'): void
    {
        if ($this->wholeGroup) {
            return;
        }
        $query->where(function (Builder $q) use ($alias): void {
            $q->whereRaw('false');
            if ($this->regionIds !== []) {
                $q->orWhereIn("{$alias}.region_id", $this->regionIds);
            }
            if ($this->zoneIds !== []) {
                $q->orWhereIn("{$alias}.zone_id", $this->zoneIds);
            }
        });
    }

    /** @return array{type: string, regionIds: list<string>, zoneIds: list<string>} */
    public function describe(): array
    {
        return [
            'type' => $this->wholeGroup ? 'whole_group' : 'scoped',
            'regionIds' => $this->regionIds,
            'zoneIds' => $this->zoneIds,
        ];
    }
}
