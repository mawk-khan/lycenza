<?php

namespace App\Domain\Visitor\Application\Retention;

use App\Models\School;
use App\Support\Retention\ReferencingRows;
use App\Support\Retention\RetentionUnit;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * E21.3E (E21.2G O3, project-adopted, pending legal ratification): a
 * checked-out visit is kept OPERATIONS_VISIT_RETENTION_YEARS (adopted 1)
 * calendar year after its checkout, then deleted; the visitor goes with
 * its last visit. Only `platform:operations-retention-prune` calls this.
 *
 * - The trigger is `checked_out_at`, set with `status = 'checked_out'` in
 *   the one-way conditional UPDATE (VisitorVisitService; a CHECK ties the
 *   two): a checkout is never reopened. A visit still `checked_in` is never
 *   eligible; one checked in more than a period ago and never checked out
 *   is counted `unresolved` and kept (no checkout is ever inferred).
 * - The unit is the VISITOR, one per transaction: lock it FOR UPDATE (a new
 *   visit takes FOR KEY SHARE on it), delete its expired visits, and delete
 *   the visitor only once no visit of it remains. A visitor who returned
 *   keeps its identity with the newer visit.
 * - A visit's host reference (`host_employee_id`) goes with it, never
 *   earlier. Any other referencing row keeps the visit or visitor.
 * - A held School is counted only. Counts only, never a name or phone.
 */
final class VisitorRetentionService
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly ReferencingRows $references,
    ) {}

    /**
     * @param  CarbonInterface  $cutoff  UTC: a visit checked out strictly before it is eligible
     * @return array{eligible: int, deleted: int, held: int, unresolved: int, dependency_blocked: int, errors: int}
     */
    public function prune(School $school, CarbonInterface $cutoff, int $batch, bool $dryRun, bool $held): array
    {
        return $this->context->withSchool($school, function () use ($school, $cutoff, $batch, $dryRun, $held): array {
            $at = $cutoff->copy()->utc()->format('Y-m-d H:i:s');
            $result = ['eligible' => 0, 'deleted' => 0, 'unresolved' => 0, 'dependency_blocked' => 0, 'errors' => 0];
            $result['unresolved'] = DB::table('visitor_visits')->where('school_id', $school->id)->where('status', '!=', 'checked_out')->where('checked_in_at', '<', $at)->count();

            DB::table('visitors as v')->where('v.school_id', $school->id)->select('v.id')
                ->whereExists(fn (Builder $q) => $this->expired($q->selectRaw('1')->from('visitor_visits as x')->whereColumn('x.visitor_id', 'v.id'), $at))
                ->chunkById($batch, function ($visitors) use (&$result, $school, $at, $dryRun, $held): void {
                    foreach ($visitors as $visitor) {
                        RetentionUnit::purge(
                            $result,
                            $dryRun || $held,
                            fn (): bool => DB::table('visitors')->where('id', $visitor->id)->lockForUpdate()->first(['id']) !== null
                                && $this->expired(DB::table('visitor_visits as x')->where('x.visitor_id', $visitor->id), $at)->exists(),
                            fn (): array => array_filter([$this->blocker($school->id, $visitor->id, $at)]),
                            function () use ($school, $visitor, $at): ?array {
                                if ($this->expired(DB::table('visitor_visits as x')->where('x.school_id', $school->id)->where('x.visitor_id', $visitor->id), $at)->delete() === 0) {
                                    return null;
                                }
                                DB::table('visitors as v')->where('v.school_id', $school->id)->where('v.id', $visitor->id)
                                    ->whereNotExists(fn (Builder $q) => $q->selectRaw('1')->from('visitor_visits as x')->whereColumn('x.visitor_id', 'v.id'))
                                    ->delete();

                                return [];
                            },
                        );
                    }
                }, 'v.id', 'id');

            if ($held) {
                return ['eligible' => $result['eligible'], 'deleted' => 0, 'held' => $result['eligible'], 'unresolved' => $result['unresolved'], 'dependency_blocked' => 0, 'errors' => 0];
            }

            return $result + ['held' => 0];
        });
    }

    /** Narrows `x` to checked-out visits whose checkout is strictly before `$at`. */
    private function expired(Builder $query, string $at): Builder
    {
        return $query->where('x.status', 'checked_out')->whereNotNull('x.checked_out_at')->where('x.checked_out_at', '<', $at);
    }

    private function blocker(string $schoolId, string $visitorId, string $at): ?string
    {
        $expired = $this->expired(DB::table('visitor_visits as x')->where('x.visitor_id', $visitorId), $at)->orderBy('x.id')->pluck('x.id')->all();
        $released = ! DB::table('visitor_visits')->where('visitor_id', $visitorId)->whereNotIn('id', $expired)->exists();

        return $this->references->first('visitor_visits', $schoolId, $expired)
            ?? ($released ? $this->references->first('visitors', $schoolId, [$visitorId], ['visitor_visits']) : null);
    }
}
