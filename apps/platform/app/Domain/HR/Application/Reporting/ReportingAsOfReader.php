<?php

namespace App\Domain\HR\Application\Reporting;

use App\Domain\HR\Application\CurrentEmployeeAssignments;
use App\Domain\HR\Infrastructure\ReportingRelationship;
use App\Models\School;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * HR.3A: the canonical historical reader of reporting lines, on any date,
 * from `reporting_relationships` only (live = not voided, not an empty
 * interval). Employment-level: a subordinate EMPLOYMENT reports to a manager
 * EMPLOYMENT. Bounded queries:
 *
 *   managersOn()        one query for any number of subordinates
 *   directReportsOn()   one query
 *   chainOn()           one recursive query, depth-capped, loop-safe
 *   depthOn()           chainOn()'s length (the "L0/L1/L2" presentation of
 *                       the reference pack is this depth, never a column)
 *   treeOn()            one recursive query below a root, depth-capped
 *   historyOf()         every line of a subordinate, voided included
 *
 * Data, never authorization: a reporting line grants no capability and no
 * data access (docs/hr/HR-TEST-STRATEGY.md §2.7). Callers apply their own
 * Campus scope to what they show. MUST be used inside the School's context
 * (it enters it itself).
 */
final class ReportingAsOfReader
{
    /** Far deeper than any School's hierarchy; a guard, never a business rule. */
    public const MAX_DEPTH = 64;

    public function __construct(private readonly TenantContext $context) {}

    /**
     * subordinate employment id => manager employment id, for the lines live on
     * the date. Absent = no manager.
     *
     * @param  list<string>  $employmentIds
     * @return array<string, string>
     */
    public function managersOn(School $school, array $employmentIds, string $date): array
    {
        $employmentIds = self::uuids($employmentIds);
        if ($employmentIds === []) {
            return [];
        }

        return $this->context->withSchool($school, fn (): array => ReportingRelationship::query()
            ->where('school_id', $school->id)->whereIn('subordinate_employment_id', $employmentIds)->effectiveOn($date)
            ->pluck('manager_employment_id', 'subordinate_employment_id')
            ->mapWithKeys(fn ($manager, $subordinate) => [(string) $subordinate => (string) $manager])->all());
    }

    /**
     * subordinate employment id => the manager's EMPLOYEE id, for the lines
     * live on the date (one query; a second when $visibleOnCampuses is given).
     * $visibleOnCampuses (a Campus reader's scope) keeps only managers
     * operationally current on one of those Campuses (CurrentEmployeeAssignments),
     * so a scoped reader never learns of an out-of-scope manager (ORG.7E-1
     * security review L1).
     *
     * @param  list<string>  $employmentIds
     * @param  list<string>|null  $visibleOnCampuses
     * @return array<string, string>
     */
    public function managerEmployeesOn(School $school, array $employmentIds, string $date, ?array $visibleOnCampuses = null): array
    {
        $employmentIds = self::uuids($employmentIds);
        if ($employmentIds === [] || $visibleOnCampuses === []) {
            return [];
        }

        return $this->context->withSchool($school, function () use ($school, $employmentIds, $date, $visibleOnCampuses): array {
            $managers = DB::table('reporting_relationships as rr')
                ->join('employment_records as mer', fn ($j) => $j->on('mer.id', '=', 'rr.manager_employment_id')->on('mer.school_id', '=', 'rr.school_id'))
                ->where('rr.school_id', $school->id)->whereIn('rr.subordinate_employment_id', $employmentIds)
                ->whereNull('rr.voided_at')->where('rr.starts_on', '<=', $date)
                ->where(fn ($w) => $w->whereNull('rr.ends_on')->orWhere('rr.ends_on', '>=', $date))
                ->pluck('mer.employee_id', 'rr.subordinate_employment_id')
                ->mapWithKeys(fn ($employee, $subordinate) => [(string) $subordinate => (string) $employee])->all();
            if ($visibleOnCampuses === null || $managers === []) {
                return $managers;
            }

            $sets = app(CurrentEmployeeAssignments::class)->campusSets($school, array_values(array_unique($managers)), $date);

            return array_filter($managers, fn (string $employeeId) => array_intersect($sets[strtolower($employeeId)] ?? [], $visibleOnCampuses) !== []);
        });
    }

    public function managerOn(School $school, string $employmentId, string $date): ?string
    {
        return $this->managersOn($school, [$employmentId], $date)[strtolower($employmentId)] ?? null;
    }

    /**
     * The subordinate employment ids reporting to $managerEmploymentId on the date.
     *
     * @return list<string>
     */
    public function directReportsOn(School $school, string $managerEmploymentId, string $date): array
    {
        if (! Str::isUuid($managerEmploymentId)) {
            return [];
        }

        return $this->context->withSchool($school, fn (): array => ReportingRelationship::query()
            ->where('school_id', $school->id)->where('manager_employment_id', $managerEmploymentId)->effectiveOn($date)
            ->orderBy('subordinate_employment_id')->pluck('subordinate_employment_id')->map(fn ($id) => (string) $id)->all());
    }

    /**
     * The manager chain above $employmentId on the date, nearest first
     * (manager, manager's manager, ...). Stops at the top, at MAX_DEPTH, or
     * at a repeat (the database refuses loops; this is defence in depth).
     *
     * @return list<string>
     */
    public function chainOn(School $school, string $employmentId, string $date): array
    {
        if (! Str::isUuid($employmentId)) {
            return [];
        }

        return $this->context->withSchool($school, function () use ($school, $employmentId, $date): array {
            $rows = DB::select(<<<'SQL'
                WITH RECURSIVE chain(node, depth, path) AS (
                    SELECT r.manager_employment_id, 1, ARRAY[r.subordinate_employment_id, r.manager_employment_id]
                      FROM reporting_relationships r
                     WHERE r.school_id = ? AND r.subordinate_employment_id = ? AND r.voided_at IS NULL
                       AND r.starts_on <= ?::date AND (r.ends_on IS NULL OR r.ends_on >= ?::date)
                    UNION ALL
                    SELECT r.manager_employment_id, c.depth + 1, c.path || r.manager_employment_id
                      FROM chain c JOIN reporting_relationships r
                        ON r.school_id = ? AND r.subordinate_employment_id = c.node AND r.voided_at IS NULL
                       AND r.starts_on <= ?::date AND (r.ends_on IS NULL OR r.ends_on >= ?::date)
                     WHERE c.depth < ? AND NOT (r.manager_employment_id = ANY (c.path))
                )
                SELECT node FROM chain ORDER BY depth
                SQL, [$school->id, $employmentId, $date, $date, $school->id, $date, $date, self::MAX_DEPTH]);

            return array_map(fn ($r) => (string) $r->node, $rows);
        });
    }

    /** 0 = reports to nobody on the date; 1 = has a manager who reports to nobody; ... */
    public function depthOn(School $school, string $employmentId, string $date): int
    {
        return count($this->chainOn($school, $employmentId, $date));
    }

    /**
     * Everyone below $rootEmploymentId on the date, breadth-first:
     * employment id => [manager employment id, depth below the root].
     *
     * @return array<string, array{manager: string, depth: int}>
     */
    public function treeOn(School $school, string $rootEmploymentId, string $date, int $maxDepth = self::MAX_DEPTH): array
    {
        if (! Str::isUuid($rootEmploymentId)) {
            return [];
        }
        $maxDepth = max(1, min($maxDepth, self::MAX_DEPTH));

        return $this->context->withSchool($school, function () use ($school, $rootEmploymentId, $date, $maxDepth): array {
            $rows = DB::select(<<<'SQL'
                WITH RECURSIVE tree(node, manager, depth, path) AS (
                    SELECT r.subordinate_employment_id, r.manager_employment_id, 1, ARRAY[r.manager_employment_id, r.subordinate_employment_id]
                      FROM reporting_relationships r
                     WHERE r.school_id = ? AND r.manager_employment_id = ? AND r.voided_at IS NULL
                       AND r.starts_on <= ?::date AND (r.ends_on IS NULL OR r.ends_on >= ?::date)
                    UNION ALL
                    SELECT r.subordinate_employment_id, r.manager_employment_id, t.depth + 1, t.path || r.subordinate_employment_id
                      FROM tree t JOIN reporting_relationships r
                        ON r.school_id = ? AND r.manager_employment_id = t.node AND r.voided_at IS NULL
                       AND r.starts_on <= ?::date AND (r.ends_on IS NULL OR r.ends_on >= ?::date)
                     WHERE t.depth < ? AND NOT (r.subordinate_employment_id = ANY (t.path))
                )
                SELECT node, manager, depth FROM tree ORDER BY depth, node
                SQL, [$school->id, $rootEmploymentId, $date, $date, $school->id, $date, $date, $maxDepth]);

            $tree = [];
            foreach ($rows as $row) {
                $tree[(string) $row->node] ??= ['manager' => (string) $row->manager, 'depth' => (int) $row->depth];
            }

            return $tree;
        });
    }

    /**
     * Every line of the subordinate employment, voided ones included (the
     * correction trail), oldest first.
     *
     * @return Collection<int, ReportingRelationship>
     */
    public function historyOf(School $school, string $subordinateEmploymentId): Collection
    {
        return $this->context->withSchool($school, fn (): Collection => ReportingRelationship::query()
            ->where('school_id', $school->id)->where('subordinate_employment_id', $subordinateEmploymentId)
            ->orderBy('starts_on')->orderBy('created_at')->orderBy('id')->get());
    }

    /**
     * @param  list<string>  $ids
     * @return list<string>
     */
    private static function uuids(array $ids): array
    {
        return array_values(array_unique(array_map('strtolower', array_filter($ids, fn (string $id) => Str::isUuid($id)))));
    }
}
