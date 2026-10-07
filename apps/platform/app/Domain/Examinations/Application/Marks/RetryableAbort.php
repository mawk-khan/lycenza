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
 */
final class RetryableAbort
{
    /** @var list<string> */
    public const array SQLSTATES = ['40P01', '40001'];

    public static function is(Throwable $e): bool
    {
        for ($cause = $e; $cause !== null; $cause = $cause->getPrevious()) {
            if (($cause instanceof QueryException || $cause instanceof PDOException) && in_array(SafeException::sqlstate($cause), self::SQLSTATES, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @template T
     *
     * @param  callable(): T  $work
     * @return T
     */
    public static function translate(callable $work): mixed
    {
        try {
            return $work();
        } catch (QueryException|PDOException $e) {
            if (self::is($e)) {
                throw new StudentMarkRetryRequiredException;
            }
            throw $e;
        }
    }
}
