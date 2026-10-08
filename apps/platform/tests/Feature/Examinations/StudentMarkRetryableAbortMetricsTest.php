<?php

namespace Tests\Feature\Examinations;

use App\Domain\Examinations\Application\Exceptions\StudentMarkRetryRequiredException;
use App\Domain\Examinations\Application\Marks\RetryableAbort;
use App\Domain\Examinations\Application\Marks\StudentMarkOperation;
use App\Domain\Examinations\Application\Marks\StudentMarkTelemetry;
use App\Domain\Examinations\Infrastructure\ExaminationPaperMarkState;
use App\Domain\Examinations\Infrastructure\StudentMarkCorrection;
use App\Models\School;
use App\Support\Observability\Metrics\MetricCatalog;
use App\Support\Observability\Metrics\MetricStore;
use App\Support\Observability\Metrics\Series;
use App\Support\Observability\Metrics\StoreMetricsRecorder;
use App\Support\Observability\MetricsRecorder;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use PDOException;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Feature\Examinations\Concerns\CreatesTeacherStudentMarkFixtures;
use Tests\TestCase;

/**
 * S5 observability follow-up (ADR 0068 §27.11; ADR 0051 §10): a StudentMark
 * request PostgreSQL aborts as a deadlock victim (40P01) or a serialization
 * failure (40001) counts ONCE in
 * `lycenza_student_mark_retryable_aborts_total{operation, reason}` -- and
 * nothing else changes: the same fixed 409, nothing persisted, no automatic
 * retry, every other database error untouched.
 *
 * Per operation, the abort is injected INSIDE the real transaction, on its
 * audit insert (after the business writes), so the rollback is proven too. A
 * TRUE PostgreSQL deadlock is GuardianProcessingAuthorizationLockOrderTest's
 * real-process proof (p_*), which also reads the child process's metric.
 */
class StudentMarkRetryableAbortMetricsTest extends TestCase
{
    use CreatesTeacherStudentMarkFixtures;

    /** One-shot: the next query whose SQL contains $fragment throws this QueryException instead of running. */
    private ?array $armed = null;

    protected function setUp(): void
    {
        parent::setUp();
        app(MetricStore::class)->flush();
        DB::connection()->beforeExecuting(function (string $query) {
            if ($this->armed !== null && str_contains($query, $this->armed[0])) {
                $sqlstate = $this->armed[1];
                $this->armed = null;

                throw self::queryException($sqlstate, $query);
            }
        });
    }

    private function abortNext(string $sqlstate, string $fragment = 'insert into "school_audit_events"'): void
    {
        $this->armed = [$fragment, $sqlstate];
    }

    /** A PostgreSQL-shaped error whose message never names a concurrency failure (so Laravel's own handling stays the plain rollback path). */
    private static function queryException(string $sqlstate, string $sql = 'select 1', string $message = 'injected by the test'): QueryException
    {
        $pdo = new PDOException("SQLSTATE[{$sqlstate}]: {$message}");
        $pdo->errorInfo = [$sqlstate, 7, $message];

        return new QueryException('pgsql', $sql, [], $pdo);
    }

    /** @return array<string, float> every recorded series of the retry metric */
    private function retrySeries(): array
    {
        return array_filter(app(MetricStore::class)->all(), fn (string $key) => str_starts_with($key, StudentMarkTelemetry::RETRYABLE_ABORTS.'|'), ARRAY_FILTER_USE_KEY);
    }

    private function assertCountedOnce(StudentMarkOperation $operation, string $reason): void
    {
        $this->assertSame([Series::key(StudentMarkTelemetry::RETRYABLE_ABORTS, ['operation' => $operation->value, 'reason' => $reason]) => 1.0], $this->retrySeries());
    }

    private function auditCount(School $school, string $type): int
    {
        return $this->inMarksSchool($school, fn () => DB::table('school_audit_events')->where('event_type', $type)->count());
    }

    private function refusedRetryable(callable $operation): void
    {
        $this->assertThrows($operation, StudentMarkRetryRequiredException::class);
    }

    // --- the classifier and the boundary -------------------------------------------------------------

    #[Test]
    public function a_deadlock_counts_once_as_deadlock_and_stays_the_fixed_409(): void
    {
        try {
            RetryableAbort::translate(StudentMarkOperation::Record, fn () => throw self::queryException('40P01'));
            $this->fail('expected the retryable 409');
        } catch (StudentMarkRetryRequiredException $e) {
            $this->assertSame([409, 'STUDENT_MARK_RETRY_REQUIRED', 'A concurrent change interrupted this request; nothing was saved. Please retry.'], [$e->getStatusCode(), $e->errorCode(), $e->getMessage()]);
        }
        $this->assertCountedOnce(StudentMarkOperation::Record, 'deadlock');
    }

    #[Test]
    public function a_serialization_failure_counts_once_as_serialization_failure(): void
    {
        $this->refusedRetryable(fn () => RetryableAbort::translate(StudentMarkOperation::PaperLock, fn () => throw self::queryException('40001')));
        $this->assertCountedOnce(StudentMarkOperation::PaperLock, 'serialization_failure');
    }

    #[Test]
    public function every_other_error_is_untouched_and_counts_nothing(): void
    {
        foreach (['23505', '55P03', '57014', '40002', '40003'] as $sqlstate) {
            $original = self::queryException($sqlstate);
            try {
                RetryableAbort::translate(StudentMarkOperation::Record, fn () => throw $original);
                $this->fail("{$sqlstate} must not be translated");
            } catch (QueryException $e) {
                $this->assertSame($original, $e, "{$sqlstate}: the very same exception, unchanged");
            }
        }

        // The words alone never count: a non-retryable SQLSTATE whose text says "deadlock", and a non-database error.
        $worded = self::queryException('23505', message: 'deadlock detected; could not serialize access');
        $this->assertThrows(fn () => RetryableAbort::translate(StudentMarkOperation::Record, fn () => throw $worded), QueryException::class);
        $this->assertThrows(fn () => RetryableAbort::translate(StudentMarkOperation::Record, fn () => throw new RuntimeException('deadlock detected')), RuntimeException::class);
        $this->assertSame([], $this->retrySeries());
    }

    #[Test]
    public function laravels_nested_deadlock_exception_is_classified_through_its_cause_once(): void
    {
        // ManagesTransactions casts the SQLSTATE to an int on a nested-transaction concurrency error; the cause keeps it.
        $nested = new DeadlockException('SQLSTATE[40P01]: Deadlock detected', 40, self::queryException('40P01'));
        $this->refusedRetryable(fn () => RetryableAbort::translate(StudentMarkOperation::CorrectionApprove, fn () => throw $nested));
        $this->assertCountedOnce(StudentMarkOperation::CorrectionApprove, 'deadlock');

        app(MetricStore::class)->flush();
        $nestedOther = new DeadlockException('SQLSTATE[23505]: deadlock detected', 23505, self::queryException('23505', message: 'deadlock detected'));
        $this->assertThrows(fn () => RetryableAbort::translate(StudentMarkOperation::Record, fn () => throw $nestedOther), DeadlockException::class);
        $this->assertSame([], $this->retrySeries(), 'a "deadlock" message with another SQLSTATE stays untranslated and uncounted');
    }

    #[Test]
    public function the_reason_vocabulary_is_the_classifier(): void
    {
        $this->assertSame(RetryableAbort::SQLSTATES, array_map('strval', array_keys(RetryableAbort::REASONS))); // PHP stores '40001' as an int key
        $this->assertSame(StudentMarkTelemetry::REASONS, array_values(RetryableAbort::REASONS));
        // The catalog owns the vocabulary (it never imports the marks module); the marks side matches it exactly.
        $this->assertSame(MetricCatalog::MARK_RETRY_REASONS, StudentMarkTelemetry::REASONS);
        $this->assertSame(MetricCatalog::MARK_RETRY_OPERATIONS, StudentMarkOperation::values());
    }

    // --- every translated StudentMark boundary, inside its real transaction -------------------------

    #[Test]
    public function administrative_entry_counts_once_persists_nothing_and_a_retry_commits_once(): void
    {
        $w = $this->teacherMarksWorld();
        $student = $this->markStudent($w);

        $this->abortNext('40P01');
        $this->refusedRetryable(fn () => $this->recordMarks($w, [$this->entry($student, 'present', '40')]));
        $this->assertCountedOnce(StudentMarkOperation::Record, 'deadlock');
        $this->assertNull($this->markOf($w, $student), 'no mark');
        $this->assertSame(0, $this->inMarksSchool($w['school'], fn () => DB::table('student_mark_revisions')->count()), 'no revision');
        $this->assertSame(0, $this->auditCount($w['school'], 'examinations.student_mark.recorded'), 'no success audit');

        // The caller retries the whole request: every check re-runs; exactly one mark and one audit; no further count.
        $this->recordMarks($w, [$this->entry($student, 'present', '40')]);
        $this->assertSame(['40.00', 1], [(string) $this->markOf($w, $student)->value, $this->markOf($w, $student)->version]);
        $this->assertSame(1, $this->auditCount($w['school'], 'examinations.student_mark.recorded'));
        $this->assertCountedOnce(StudentMarkOperation::Record, 'deadlock');
    }

    #[Test]
    public function teacher_entry_is_the_same_record_boundary(): void
    {
        $w = $this->teacherMarksWorld();
        [$teacher, $employee] = $this->markTeacher($w);
        $this->ownSection($w, $employee, 'a1');
        $student = $this->markStudent($w, 'a1');

        $this->abortNext('40001');
        $this->refusedRetryable(fn () => $this->teacherRecord($w, $teacher, [$this->entry($student, 'present', '41')]));
        $this->assertCountedOnce(StudentMarkOperation::Record, 'serialization_failure');
        $this->assertNull($this->markOf($w, $student));
        $this->assertSame(0, $this->auditCount($w['school'], 'examinations.student_mark.teacher_recorded'));

        $this->teacherRecord($w, $teacher, [$this->entry($student, 'present', '41')]);
        $this->assertSame('41.00', (string) $this->markOf($w, $student)->value);
        $this->assertSame(1, $this->auditCount($w['school'], 'examinations.student_mark.teacher_recorded'));
    }

    #[Test]
    public function the_paper_lock_counts_as_paper_lock_and_leaves_the_paper_open(): void
    {
        $w = $this->teacherMarksWorld();
        $this->recordMarks($w, [$this->entry($this->markStudent($w), 'present', '40')]);

        $this->abortNext('40P01');
        $this->refusedRetryable(fn () => $this->lockMarks($w));
        $this->assertCountedOnce(StudentMarkOperation::PaperLock, 'deadlock');
        $state = fn () => $this->inMarksSchool($w['school'], fn () => ExaminationPaperMarkState::query()->where('examination_paper_id', $w['paper']->id)->value('state'));
        $this->assertNotSame(ExaminationPaperMarkState::STATE_LOCKED, $state());
        $this->assertSame(0, $this->auditCount($w['school'], 'examinations.student_marks.locked'));

        $this->lockMarks($w);
        $this->assertSame(ExaminationPaperMarkState::STATE_LOCKED, $state());
        $this->assertSame(1, $this->auditCount($w['school'], 'examinations.student_marks.locked'));
    }

    #[Test]
    public function each_correction_decision_counts_under_its_own_operation_and_changes_nothing(): void
    {
        $w = $this->teacherMarksWorld();
        $student = $this->markStudent($w);
        $this->recordMarks($w, [$this->entry($student, 'present', '40')]);
        $this->lockMarks($w);
        $mark = $this->markOf($w, $student);
        $corrections = fn () => $this->inMarksSchool($w['school'], fn () => StudentMarkCorrection::query()->pluck('status')->all());

        $this->abortNext('40P01');
        $this->refusedRetryable(fn () => $this->requestCorrection($w, $mark, 'present', '42'));
        $this->assertCountedOnce(StudentMarkOperation::CorrectionRequest, 'deadlock');
        $this->assertSame([], $corrections(), 'no correction row');

        $first = $this->requestCorrection($w, $mark, 'present', '42');
        $checker = $this->checker($w);
        app(MetricStore::class)->flush();
        $this->abortNext('40001');
        $this->refusedRetryable(fn () => $this->approveCorrection($w, $first, $checker));
        $this->assertCountedOnce(StudentMarkOperation::CorrectionApprove, 'serialization_failure');
        $this->assertSame([StudentMarkCorrection::STATUS_PENDING], $corrections(), 'still pending');
        $this->assertSame(['40.00', 1], [(string) $this->markOf($w, $student)->value, $this->markOf($w, $student)->version], 'the mark is unchanged');

        app(MetricStore::class)->flush();
        $this->abortNext('40P01');
        $this->refusedRetryable(fn () => $this->rejectCorrection($w, $first, $checker));
        $this->assertCountedOnce(StudentMarkOperation::CorrectionReject, 'deadlock');
        $this->assertSame([StudentMarkCorrection::STATUS_PENDING], $corrections());
        $this->assertSame(0, $this->auditCount($w['school'], 'examinations.student_mark_correction.rejected'));

        // Retried: exactly one decision commits.
        $this->rejectCorrection($w, $first, $checker);
        $this->assertSame([StudentMarkCorrection::STATUS_REJECTED], $corrections());
        $this->assertSame(1, $this->auditCount($w['school'], 'examinations.student_mark_correction.rejected'));
    }

    #[Test]
    public function an_unrelated_database_error_inside_a_mark_write_keeps_its_existing_behaviour(): void
    {
        $w = $this->teacherMarksWorld();
        $student = $this->markStudent($w);

        $this->abortNext('23505');
        $this->assertThrows(fn () => $this->recordMarks($w, [$this->entry($student, 'present', '40')]), QueryException::class);
        $this->assertSame([], $this->retrySeries());
        $this->assertNull($this->markOf($w, $student));
    }

    #[Test]
    public function the_http_answer_is_unchanged_and_carries_no_metric_or_database_detail(): void
    {
        $w = $this->teacherMarksWorld();
        $student = $this->markStudent($w);
        $this->actingAs($w['admin'])->post("/app/schools/{$w['school']->id}/activate");
        session(['mfa_verified_at' => now()->toIso8601String()]);

        $this->abortNext('40P01');
        $response = $this->putJson('/app/examination-papers/'.$w['paper']->id.'/marks', ['marks' => [['student_id' => $student->id, 'status' => 'present', 'value' => '64.5', 'expected_version' => null]]]);

        $response->assertStatus(409)->assertJsonPath('error.code', 'STUDENT_MARK_RETRY_REQUIRED');
        foreach (['40P01', 'deadlock', 'serializ', 'SQLSTATE', 'school_audit_events', $student->id, '64.5', 'lycenza_'] as $leak) {
            $this->assertStringNotContainsStringIgnoringCase($leak, (string) $response->getContent());
        }
        $this->assertCountedOnce(StudentMarkOperation::Record, 'deadlock');
    }

    // --- privacy, cardinality and failure isolation ---------------------------------------------------

    #[Test]
    public function the_metric_is_pinned_to_two_closed_labels_and_carries_nothing_identifying(): void
    {
        $this->assertSame(
            ['type' => 'counter', 'labels' => ['operation' => ['record', 'paper_lock', 'correction_request', 'correction_approve', 'correction_reject'], 'reason' => ['deadlock', 'serialization_failure']]],
            array_intersect_key(MetricCatalog::definitions()[StudentMarkTelemetry::RETRYABLE_ABORTS], ['type' => true, 'labels' => true]),
        );

        $w = $this->teacherMarksWorld();
        $student = $this->markStudent($w);
        $this->abortNext('40P01');
        $this->refusedRetryable(fn () => $this->recordMarks($w, [$this->entry($student, 'present', '40')]));

        foreach (array_keys(app(MetricStore::class)->all()) as $key) {
            [$name, $labels] = Series::parse($key);
            $this->assertSame(StudentMarkTelemetry::RETRYABLE_ABORTS, $name);
            $this->assertSame(['operation', 'reason'], array_keys($labels));
            foreach ([$w['school']->id, $w['school']->name, $student->id, $w['paper']->id, $w['admin']->id, '40.00', 'present', '40P01', 'insert', 'school_audit_events', 'injected'] as $forbidden) {
                $this->assertStringNotContainsString((string) $forbidden, $key);
            }
        }

        // The recorder refuses anything beyond the two closed labels (local/testing throw; production drops and counts).
        foreach ([['operation' => 'record', 'reason' => 'deadlock', 'school_id' => $w['school']->id], ['operation' => '/app/examination-papers', 'reason' => 'deadlock'], ['operation' => 'record', 'reason' => '40P01'], ['operation' => 'record']] as $labels) {
            $this->assertThrows(fn () => app(MetricsRecorder::class)->counter(StudentMarkTelemetry::RETRYABLE_ABORTS, 1, $labels), InvalidArgumentException::class);
        }
    }

    #[Test]
    public function a_failing_metrics_store_never_replaces_the_retryable_409_or_commits_anything(): void
    {
        $failing = new class implements MetricStore
        {
            public int $attempts = 0;

            public function increment(string $series, float $by): void
            {
                $this->attempts++;
                throw new RuntimeException('metrics store down');
            }

            public function set(string $series, float $value): void
            {
                $this->attempts++;
                throw new RuntimeException('metrics store down');
            }

            public function all(): array
            {
                return [];
            }

            public function flush(): void {}
        };
        $this->app->instance(MetricStore::class, $failing);
        $this->app->forgetInstance(MetricsRecorder::class);
        $this->app->singleton(MetricsRecorder::class, fn () => new StoreMetricsRecorder($failing));

        $w = $this->teacherMarksWorld();
        $student = $this->markStudent($w);
        $this->abortNext('40P01');
        $this->refusedRetryable(fn () => $this->recordMarks($w, [$this->entry($student, 'present', '40')]));
        $this->assertGreaterThan(0, $failing->attempts, 'the metric was attempted and failed -- the proof is not vacuous');
        $this->assertNull($this->markOf($w, $student));
    }
}
