<?php

namespace App\Domain\Platform\Application\Groups;

use App\Models\SchoolGroup;
use Illuminate\Support\Facades\DB;

/**
 * ORG.12H (ADR 0080 §13.3, OD-12.20): the Network MIS read model of ONE
 * School Group, within the viewer's GroupGovernanceScope -- platform tables
 * only (`school_group_members`, `schools`, `school_group_regions`,
 * `school_group_zones`), exactly like SchoolGroupDirectoryReadService.
 *
 * - Lifecycle summary: member Schools per canonical `schools.status`.
 * - Governance distribution: member Schools per Region, per Zone inside it,
 *   Region-only, and unplaced (unplaced only for a whole-Group viewer --
 *   the scope already excludes it for Region and Zone viewers).
 *
 * Counts are of member Schools only. Never a tenant table, TenantContext,
 * RLS bypass, cache or snapshot: no Campus count, setup readiness, person,
 * money or business record (those need tenant reads; deferred, ADR 0080
 * §13.4). A fixed number of queries whatever the Group size.
 */
class NetworkReportReadService
{
    public const array LIFECYCLE = ['provisioning', 'active', 'suspended', 'archived'];

    public function __construct(private readonly SchoolGroupDirectoryReadService $directory) {}

    /** @return array<string, int> canonical lifecycle status => member Schools (one query) */
    public function lifecycle(SchoolGroup $group, GroupGovernanceScope $scope): array
    {
        $counts = DB::table('school_group_members as m')
            ->join('schools as s', 's.id', '=', 'm.school_id')
            ->where('m.school_group_id', $group->id)
            ->tap(fn ($q) => $scope->constrain($q))
            ->groupBy('s.status')
            ->selectRaw('s.status, count(*) as schools')
            ->pluck('schools', 'status')->all();

        $summary = [];
        foreach (self::LIFECYCLE as $status) {
            $summary[$status] = (int) ($counts[$status] ?? 0);
        }

        return $summary;
    }

    /**
     * Region -> Zone distribution of member Schools (a fixed three or four
     * queries: the counts and the scoped structure).
     *
     * @return array{regions: list<array<string, mixed>>, zonesWithoutRegion: list<array<string, mixed>>, unplaced: ?int}
     */
    public function distribution(SchoolGroup $group, GroupGovernanceScope $scope): array
    {
        $rows = DB::table('school_group_members as m')
            ->where('m.school_group_id', $group->id)
            ->tap(fn ($q) => $scope->constrain($q))
            ->groupBy('m.region_id', 'm.zone_id')
            ->selectRaw('m.region_id, m.zone_id, count(*) as schools')
            ->get();
        $structure = $this->directory->structure($group, $scope);

        $byZone = [];
        $regionOnly = [];
        $unplaced = 0;
        foreach ($rows as $row) {
            if ($row->zone_id !== null) {
                $byZone[$row->zone_id] = ($byZone[$row->zone_id] ?? 0) + (int) $row->schools;
            } elseif ($row->region_id !== null) {
                $regionOnly[$row->region_id] = (int) $row->schools;
            } else {
                $unplaced += (int) $row->schools;
            }
        }

        $regions = [];
        foreach ($structure['regions'] as $region) {
            $zones = array_values(array_map(
                fn (array $zone): array => ['code' => $zone['code'], 'name' => $zone['name'], 'status' => $zone['status'], 'schools' => $byZone[$zone['id']] ?? 0],
                array_filter($structure['zones'], fn (array $zone): bool => $zone['regionId'] === $region['id']),
            ));
            $regions[] = [
                'code' => $region['code'],
                'name' => $region['name'],
                'status' => $region['status'],
                'schools' => array_sum(array_column($zones, 'schools')) + ($regionOnly[$region['id']] ?? 0),
                'regionOnly' => $regionOnly[$region['id']] ?? 0,
                'zones' => $zones,
            ];
        }

        // A Zone may stand outside any Region (ORG.12D): listed on its own.
        $standalone = array_values(array_map(
            fn (array $zone): array => ['code' => $zone['code'], 'name' => $zone['name'], 'status' => $zone['status'], 'schools' => $byZone[$zone['id']] ?? 0],
            array_filter($structure['zones'], fn (array $zone): bool => $zone['regionId'] === null),
        ));

        return ['regions' => $regions, 'zonesWithoutRegion' => $standalone, 'unplaced' => $scope->wholeGroup ? $unplaced : null];
    }
}
