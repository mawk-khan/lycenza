<?php

namespace App\Domain\Platform\Application\Groups;

use App\Models\SchoolGroup;
use App\Models\SchoolGroupRegion;
use App\Models\SchoolGroupZone;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * ORG.12D (ADR 0080 §9, OD-12.11): the governance-safe read model of ONE
 * School Group -- its overview, member-School directory and structure --
 * for the Group surface (`group.schools.view`) and the platform surface
 * (`platform.school_groups.view`). Callers authorize; this reads.
 *
 * Platform tables ONLY (`school_groups`, `school_group_members`,
 * `school_group_regions`, `school_group_zones`, `schools`): a member
 * School's id, display name, code and lifecycle status, and the Group's
 * own classification of it. Never a tenant table -- no TenantContext, no
 * RLS bypass, no Student, Staff, Guardian, Finance, attendance or Campus
 * record, no count of people (ADR 0045 §12, invariant 10). Campus counts
 * and setup readiness would read tenant tables and are deferred to an
 * ADR 0048 amendment (ORG.12H).
 *
 * Counts here are counts of member Schools, Regions and Zones -- facts the
 * directory itself lists -- never counts of anything inside a School.
 *
 * ORG.12E (ADR 0080 §10.3): every read takes the viewer's
 * GroupGovernanceScope. A Region or Zone operator sees only the memberships,
 * counts and structure inside that Region or Zone (never an unplaced
 * membership); the platform and whole-Group viewers pass the whole scope.
 * Bounded: a fixed number of queries whatever the Group size; the
 * directory is paginated.
 */
class SchoolGroupDirectoryReadService
{
    public const int PER_PAGE = 25;

    public const int MAX_SEARCH_LENGTH = 100;

    public const string UNCLASSIFIED = 'unclassified';

    /**
     * @param  array{relationship?: ?string, region_id?: ?string, zone_id?: ?string, status?: ?string, q?: ?string}  $filters
     * @return LengthAwarePaginator<int, object>
     */
    public function directory(SchoolGroup $group, array $filters, int $page, GroupGovernanceScope $scope): LengthAwarePaginator
    {
        $query = DB::table('school_group_members as m')
            ->join('schools as s', 's.id', '=', 'm.school_id')
            ->leftJoin('school_group_regions as r', 'r.id', '=', 'm.region_id')
            ->leftJoin('school_group_zones as z', 'z.id', '=', 'm.zone_id')
            ->where('m.school_group_id', $group->id);
        $scope->constrain($query);

        // Query-string filters may arrive as arrays; anything not a string is ignored.
        $filters = array_filter($filters, 'is_string');
        $relationship = $filters['relationship'] ?? null;
        if ($relationship === self::UNCLASSIFIED) {
            $query->whereNull('m.relationship_type');
        } elseif ($relationship !== null && SchoolGroupRelationship::tryFrom($relationship) !== null) {
            $query->where('m.relationship_type', $relationship);
        }
        foreach (['region_id' => 'm.region_id', 'zone_id' => 'm.zone_id'] as $key => $column) {
            if (is_string($filters[$key] ?? null) && preg_match('/^[0-9a-f-]{36}$/i', $filters[$key]) === 1) {
                $query->where($column, $filters[$key]);
            }
        }
        if (in_array($filters['status'] ?? null, ['provisioning', 'active', 'suspended', 'archived'], true)) {
            $query->where('s.status', $filters['status']);
        }
        $search = trim((string) ($filters['q'] ?? ''));
        if ($search !== '') {
            $pattern = '%'.addcslashes(mb_substr($search, 0, self::MAX_SEARCH_LENGTH), '\\%_').'%';
            $query->where(fn ($q) => $q->where('s.name', 'ilike', $pattern)->orWhere('s.code', 'ilike', $pattern));
        }

        return $query->orderBy('s.name')->orderBy('s.id')
            ->select([
                's.id', 's.name', 's.code', 's.status',
                'm.relationship_type', 'm.network_reference', 'm.region_id', 'm.zone_id',
                'r.code as region_code', 'r.name as region_name', 'r.status as region_status',
                'z.code as zone_code', 'z.name as zone_name', 'z.status as zone_status',
            ])
            ->paginate(self::PER_PAGE, ['*'], 'page', max($page, 1));
    }

    /**
     * Structural metrics of the Group: member Schools by lifecycle and by
     * relationship, Regions and Zones (with their member counts), and the
     * memberships needing governance attention.
     *
     * @return array<string, mixed>
     */
    public function overview(SchoolGroup $group, GroupGovernanceScope $scope): array
    {
        $members = DB::table('school_group_members as m')
            ->join('schools as s', 's.id', '=', 'm.school_id')
            ->leftJoin('school_group_regions as r', 'r.id', '=', 'm.region_id')
            ->leftJoin('school_group_zones as z', 'z.id', '=', 'm.zone_id')
            ->where('m.school_group_id', $group->id)
            ->tap(fn ($q) => $scope->constrain($q))
            ->selectRaw("count(*) as total,
                count(*) filter (where s.status = 'active') as active,
                count(*) filter (where s.status <> 'active') as not_active,
                count(*) filter (where m.relationship_type = 'owned') as owned,
                count(*) filter (where m.relationship_type = 'franchise') as franchise,
                count(*) filter (where m.relationship_type = 'managed') as managed,
                count(*) filter (where m.relationship_type = 'affiliate') as affiliate,
                count(*) filter (where m.relationship_type is null) as unclassified,
                count(*) filter (where r.status = 'inactive' or z.status = 'inactive') as in_inactive_structure")
            ->first();

        $structure = $this->structure($group, $scope);

        return [
            'schools' => [
                'total' => (int) $members->total,
                'active' => (int) $members->active,
                'notActive' => (int) $members->not_active,
            ],
            'relationships' => [
                'owned' => (int) $members->owned,
                'franchise' => (int) $members->franchise,
                'managed' => (int) $members->managed,
                'affiliate' => (int) $members->affiliate,
                self::UNCLASSIFIED => (int) $members->unclassified,
            ],
            'attention' => [
                'unclassified' => (int) $members->unclassified,
                'inInactiveStructure' => (int) $members->in_inactive_structure,
                'schoolsNotActive' => (int) $members->not_active,
            ],
            'regions' => $structure['regions'],
            'zones' => $structure['zones'],
        ];
    }

    /**
     * Regions and Zones in their explicit order, each with its member count
     * (two queries) -- for a scoped viewer only its own Region(s), the
     * Zones inside them and its own Zone(s), counted within the scope.
     *
     * @return array{regions: list<array<string, mixed>>, zones: list<array<string, mixed>>}
     */
    public function structure(SchoolGroup $group, GroupGovernanceScope $scope): array
    {
        $count = fn ($q) => $q->where('school_group_id', $group->id)->tap(fn ($inner) => $scope->constrain($inner->getQuery(), 'school_group_members'));
        $zoneRegions = $scope->wholeGroup ? [] : SchoolGroupZone::query()->where('school_group_id', $group->id)
            ->whereIn('id', $scope->zoneIds)->whereNotNull('region_id')->pluck('region_id')->all();
        $regions = SchoolGroupRegion::query()->where('school_group_id', $group->id)
            ->when(! $scope->wholeGroup, fn ($q) => $q->whereIn('id', array_values(array_unique([...$scope->regionIds, ...$zoneRegions]))))
            ->withCount(['members' => $count])
            ->orderBy('sort_order')->orderBy('code')->get();
        $zones = SchoolGroupZone::query()->where('school_group_id', $group->id)
            ->when(! $scope->wholeGroup, fn ($q) => $q->where(fn ($w) => $w->whereIn('id', $scope->zoneIds)->orWhereIn('region_id', $scope->regionIds)))
            ->withCount(['members' => $count])
            ->orderBy('sort_order')->orderBy('code')->get();

        return [
            'regions' => $regions->map(fn (SchoolGroupRegion $r): array => [
                'id' => $r->id, 'code' => $r->code, 'name' => $r->name, 'description' => $r->description,
                'sortOrder' => $r->sort_order, 'status' => $r->status, 'schools' => (int) $r->members_count,
            ])->values()->all(),
            'zones' => $zones->map(fn (SchoolGroupZone $z): array => [
                'id' => $z->id, 'code' => $z->code, 'name' => $z->name, 'description' => $z->description, 'regionId' => $z->region_id,
                'sortOrder' => $z->sort_order, 'status' => $z->status, 'schools' => (int) $z->members_count,
            ])->values()->all(),
        ];
    }

    /** @return array<string, mixed> one directory row for the UI */
    public static function present(object $row): array
    {
        $relationship = $row->relationship_type !== null ? SchoolGroupRelationship::from($row->relationship_type) : null;

        return [
            'id' => $row->id,
            'name' => $row->name,
            'code' => $row->code,
            'status' => $row->status,
            'relationship' => $relationship?->value,
            'relationshipLabel' => $relationship?->label() ?? 'Unclassified',
            'networkReference' => $row->network_reference,
            'region' => $row->region_id !== null ? ['id' => $row->region_id, 'code' => $row->region_code, 'name' => $row->region_name, 'status' => $row->region_status] : null,
            'zone' => $row->zone_id !== null ? ['id' => $row->zone_id, 'code' => $row->zone_code, 'name' => $row->zone_name, 'status' => $row->zone_status] : null,
        ];
    }
}
