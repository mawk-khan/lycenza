<?php

namespace Tests\Support\Concurrency;

use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Child-process side of a DETERMINISTIC two-process race (see
 * Tests\Concerns\ForcesConcurrentOverlap for the parent side).
 *
 * Starting two OS processes "at the same time" does not make their
 * transactions overlap: under load one process can boot, run and commit
 * before the other begins, and a service that legitimately accepts a
 * sequential second call (activate-then-supersede, set-primary-then-
 * replace, ...) then reports two successes. Instead of hoping for
 * overlap, the parent forces it:
 *
 *  - the HOLDER (env CONCURRENCY_HOLD_DIR) runs the operation inside an
 *    outer transaction, signals `acted`, and does not commit until the
 *    parent creates `release` -- its writes stay uncommitted;
 *  - the CONTENDER (env CONCURRENCY_SESSION_NAME) names its PostgreSQL
 *    session so the parent can observe, via pg_stat_activity, that it is
 *    genuinely blocked on the holder's uncommitted row/index entry
 *    before releasing the holder.
 *
 * Without either variable, run() simply executes the operation, so the
 * support scripts keep working unchanged for every other caller.
 */
final class HeldTransaction
{
    /** Upper bound on how long a holder waits to be released (failure bound, not a race window). */
    private const RELEASE_DEADLINE_SECONDS = 120;

    /**
     * @template T
     *
     * @param  callable(): T  $operation
     * @return T
     */
    public static function run(callable $operation): mixed
    {
        $sessionName = getenv('CONCURRENCY_SESSION_NAME');
        if ($sessionName !== false && $sessionName !== '') {
            DB::connection()->statement('SET application_name TO '.DB::connection()->getPdo()->quote($sessionName));
        }

        $holdDir = getenv('CONCURRENCY_HOLD_DIR');
        if ($holdDir === false || $holdDir === '') {
            return $operation();
        }

        DB::beginTransaction();

        try {
            $result = $operation();
        } catch (Throwable $e) {
            DB::rollBack();

            throw $e;
        }

        touch($holdDir.'/acted');

        $deadline = microtime(true) + self::RELEASE_DEADLINE_SECONDS;
        while (! file_exists($holdDir.'/release')) {
            if (microtime(true) > $deadline) {
                DB::rollBack();

                throw new RuntimeException('HeldTransaction: the parent never released the holder.');
            }
            usleep(1_000);
        }

        DB::commit();

        return $result;
    }
}
