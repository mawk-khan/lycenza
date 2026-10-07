<?php

namespace Tests\Unit\Examinations;

use App\Domain\Examinations\Application\Exceptions\StudentMarkRetryRequiredException;
use App\Domain\Examinations\Application\Marks\RetryableAbort;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\QueryException;
use PDOException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * S5: the StudentMark deadlock translation is NARROW -- only SQLSTATE 40P01
 * (deadlock) and 40001 (serialization failure), also when Laravel's
 * nested-transaction DeadlockException hides the code -- and the 409 it
 * becomes carries a fixed message with no database detail.
 */
class RetryableAbortTest extends TestCase
{
    private function queryException(string $sqlstate): QueryException
    {
        $pdo = new PDOException("SQLSTATE[{$sqlstate}]: secret table student_marks value 77.25");
        $pdo->errorInfo = [$sqlstate, 7, 'detail'];

        return new QueryException('pgsql', 'select * from student_marks where value = ?', ['77.25'], $pdo);
    }

    #[Test]
    public function only_transaction_rollback_aborts_are_retryable(): void
    {
        $this->assertTrue(RetryableAbort::is($this->queryException('40P01')));
        $this->assertTrue(RetryableAbort::is($this->queryException('40001')));
        $deadlock = $this->queryException('40P01');
        $this->assertTrue(RetryableAbort::is(new DeadlockException($deadlock->getMessage(), (int) $deadlock->getCode(), $deadlock)), 'nested-transaction wrapper');

        foreach (['23505', '23503', '23514', '22P02', '42501', '57014', 'P0001'] as $other) {
            $this->assertFalse(RetryableAbort::is($this->queryException($other)), $other);
        }
        $this->assertFalse(RetryableAbort::is(new RuntimeException('deadlock detected')), 'text alone never counts');
    }

    #[Test]
    public function translation_is_a_fixed_409_and_everything_else_passes_through(): void
    {
        try {
            RetryableAbort::translate(fn () => throw $this->queryException('40P01'));
            $this->fail('expected the retry refusal');
        } catch (StudentMarkRetryRequiredException $e) {
            $this->assertSame([409, 'STUDENT_MARK_RETRY_REQUIRED', 'A concurrent change interrupted this request; nothing was saved. Please retry.'], [$e->getStatusCode(), $e->errorCode(), $e->getMessage()]);
            $this->assertNull($e->getPrevious(), 'no database exception (SQL, bindings, values) travels with it');
        }

        $unique = $this->queryException('23505');
        try {
            RetryableAbort::translate(fn () => throw $unique);
            $this->fail('expected the original exception');
        } catch (QueryException $e) {
            $this->assertSame($unique, $e);
        }
        $this->assertSame('ok', RetryableAbort::translate(fn () => 'ok'));
    }
}
