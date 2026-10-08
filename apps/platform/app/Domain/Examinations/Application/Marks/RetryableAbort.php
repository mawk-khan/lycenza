<?php

namespace App\Domain\Examinations\Application\Marks;

use App\Domain\Examinations\Application\Exceptions\StudentMarkRetryRequiredException;
use App\Support\Observability\SafeException;
use Illuminate\Database\QueryException;
use PDOException;
use Throwable;

/**
 * S5: the narrow translation of a PostgreSQL transaction-rollback abort -- and
 * ONLY that -- at the StudentMark boundary. `40P01` (deadlock_detected) and
 * `40001` (serialization_failure) mean "the database rolled the whole
 * transaction back; the same request may be retried"; every other database
 * error keeps its existing handling. Laravel's nested-transaction
 * DeadlockException casts the SQLSTATE to an int, so the previous-exception
 * chain is read too. No automatic retry: the caller re-sends the request and
 * every check re-runs.
 *
 * Observability (S5 follow-up, ADR 0068 §27.11): each translated abort counts
 * ONCE in `lycenza_student_mark_retryable_aborts_total{operation, reason}` --
 * here, at the one boundary, once the operation's own transaction has
 * unwound (the metric is outside it, so no rollback erases it). Nothing
 * else is recorded or logged: not the exception, not the SQLSTATE text, not
 * any identifier.
 */
final class RetryableAbort
{
    /** @var list<string> */
    public const array SQLSTATES = ['40P01', '40001'];

    /** The metric's `reason` for each SQLSTATE -- semantic names, never the raw code. */
    public const array REASONS = ['40P01' => 'deadlock', '40001' => 'serialization_failure'];

    public static function is(Throwable $e): bool
    {
        return self::reason($e) !== null;
    }

    /** @return value-of<self::REASONS>|null */
    public static function reason(Throwable $e): ?string
    {
        for ($cause = $e; $cause !== null; $cause = $cause->getPrevious()) {
            $state = $cause instanceof QueryException || $cause instanceof PDOException ? SafeException::sqlstate($cause) : null;
            if ($state !== null && in_array($state, self::SQLSTATES, true)) {
                return self::REASONS[$state];
            }
        }

        return null;
    }

    /**
     * @template T
     *
     * @param  callable(): T  $work
     * @return T
     */
    public static function translate(StudentMarkOperation $operation, callable $work): mixed
    {
        try {
            return $work();
        } catch (QueryException|PDOException $e) {
            $reason = self::reason($e);
            if ($reason !== null) {
                app(StudentMarkTelemetry::class)->retryableAbort($operation, $reason);

                throw new StudentMarkRetryRequiredException;
            }
            throw $e;
        }
    }
}
