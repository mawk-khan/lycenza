<?php

namespace App\Domain\Guardians\Application\Retention;

use App\Models\School;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * E21.3C (E21.2G G1, docs/security/E21-RETENTION-DETERMINATION.md,
 * project-adopted, pending legal ratification): the ONE canonical answer
 * to "since when has this Guardian had no Student relationship?". It is
 * used only by maintenance (retention, erasure planning, closure
 * readiness), never by a request, and is independent of authorization.
 *
 * - A relationship is ACTIVE while its `student_guardian_relationships` row
 *   exists. There is no end column: an unlink is a hard delete (history is
 *   in audit), and D7 removes the rows of a Student who left 7 years ago.
 *   So a Guardian of a departed Student keeps its clock stopped until that
 *   Student's relationship row is gone.
 * - The time comes only from `guardians.no_relationship_since`, maintained
 *   by the database in the relationship writer's own transaction (migration
 *   2026_11_12_090000). Nothing is computed from whichever rows happen to
 *   remain, and nothing from `updated_at` or account activity.
 * - Re-link: a new relationship clears the marker (the clock stops); the
 *   next final unlink restarts it from that moment. Only the LAST
 *   relationship's end starts the clock.
 * - `lockLifecycle()` locks the Guardian row FOR UPDATE and resolves after
 *   the lock. A relationship insert takes FOR KEY SHARE on the Guardian and
 *   its trigger locks the Guardian row FOR UPDATE, so a link either commits
 *   first (the recheck sees it) or waits for the purge (and then fails on
 *   its foreign key). Only the Guardian row is locked here.
 */
final class GuardianRetentionEligibility
{
    public function __construct(private readonly TenantContext $context) {}

    /**
     * Walks one School's Guardians without a relationship in id order and
     * calls `$each` for every one whose marker is strictly before `$cutoff`.
     *
     * @param  Closure(string, GuardianLifecycle): void  $each
     * @param  string|null  $only  one Guardian (an erasure case)
     * @return int Guardians without a relationship whose time is unresolved
     */
    public function endedBefore(School $school, string $cutoff, int $batch, Closure $each, ?string $only = null): int
    {
        return $this->context->withSchool($school, function () use ($school, $cutoff, $batch, $each, $only): int {
            $unrelated = fn (): Builder => DB::table('guardians as g')->where('g.school_id', $school->id)
                ->when($only !== null, fn (Builder $q) => $q->where('g.id', $only))
                ->whereNotExists(fn (Builder $q) => $q->selectRaw('1')->from('student_guardian_relationships as r')->whereColumn('r.guardian_id', 'g.id'));

            $unresolved = $unrelated()->whereNull('g.no_relationship_since')->count();

            $unrelated()->whereNotNull('g.no_relationship_since')->where('g.no_relationship_since', '<', $cutoff)
                ->select('g.id', 'g.no_relationship_since')
                ->chunkById($batch, function ($guardians) use ($each): void {
                    foreach ($guardians as $guardian) {
                        $each($guardian->id, GuardianLifecycle::resolve(false, (string) $guardian->no_relationship_since));
                    }
                }, 'g.id', 'id');

            return $unresolved;
        });
    }

    /** E21.3C (erasure planning, read-only): one Guardian's lifecycle; null when not in this School's context. */
    public function lifecycleOf(School $school, string $guardianId): ?GuardianLifecycle
    {
        return $this->context->withSchool($school, function () use ($guardianId): ?GuardianLifecycle {
            $guardian = DB::table('guardians')->where('id', $guardianId)->first(['no_relationship_since']);

            return $guardian === null ? null : GuardianLifecycle::resolve(
                DB::table('student_guardian_relationships')->where('guardian_id', $guardianId)->exists(),
                $guardian->no_relationship_since === null ? null : (string) $guardian->no_relationship_since,
            );
        });
    }

    /**
     * Locks the Guardian row FOR UPDATE and resolves from committed data read
     * after the lock. Inside a transaction, in the Guardian's tenant context.
     * Null means the Guardian no longer exists.
     */
    public function lockLifecycle(string $guardianId): ?GuardianLifecycle
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('lockLifecycle() must run inside the purge transaction.');
        }

        $guardian = DB::table('guardians')->where('id', $guardianId)->lockForUpdate()->first(['no_relationship_since']);
        if ($guardian === null) {
            return null;
        }

        return GuardianLifecycle::resolve(
            DB::table('student_guardian_relationships')->where('guardian_id', $guardianId)->exists(),
            $guardian->no_relationship_since === null ? null : (string) $guardian->no_relationship_since,
        );
    }
}
