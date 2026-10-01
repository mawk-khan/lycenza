<?php

namespace App\Support\Retention;

use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * E21.2D/E21.2E: one retention unit (a Student, an Employee) through the
 * shared purge discipline. The owning domain's eligibility loop calls it
 * for each unit whose trigger has passed, so the dry run and the
 * destructive run share every rule.
 * - Dry run: counts the unit, and counts `dependency_blocked` when
 *   `$blockers` names a retained dependent.
 * - Otherwise, ONE transaction per unit:
 *   1. `$lockAndCheck` locks the unit's root row and rechecks its trigger
 *      after the lock (false keeps everything);
 *   2. `$blockers` is rechecked under the lock;
 *   3. `$purge` deletes only the caller's own rows and returns the stored
 *      objects to remove, or null when nothing was deleted;
 *   4. those bytes are deleted after commit (ObjectDeletion).
 *
 * A database failure rolls back only that unit. It is counted as `errors`
 * and retried on the next run; it is never reported as an expiry.
 */
final class RetentionUnit
{
    /**
     * @param  array{eligible: int, deleted: int, unresolved: int, dependency_blocked: int, errors: int}  $result
     * @param  Closure(): bool  $lockAndCheck
     * @param  Closure(): list<string>  $blockers  retained dependents (read-only)
     * @param  Closure(): (list<object{storage_disk: string, storage_path: string}>|null)  $purge
     */
    public static function purge(array &$result, bool $dryRun, Closure $lockAndCheck, Closure $blockers, Closure $purge): void
    {
        $result['eligible']++;

        if ($dryRun) {
            $result['dependency_blocked'] += $blockers() === [] ? 0 : 1;

            return;
        }

        try {
            $outcome = DB::transaction(function () use ($lockAndCheck, $blockers, $purge): array|string {
                if (! $lockAndCheck()) {
                    return 'kept';
                }

                if ($blockers() !== []) {
                    return 'dependency_blocked';
                }

                return $purge() ?? 'kept';
            });
        } catch (QueryException) {
            $result['errors']++;

            return;
        }

        if (is_array($outcome)) {
            $result['deleted']++;
            $result['errors'] += ObjectDeletion::afterCommit($outcome);
        } elseif ($outcome === 'dependency_blocked') {
            $result['dependency_blocked']++;
        }
    }
}
