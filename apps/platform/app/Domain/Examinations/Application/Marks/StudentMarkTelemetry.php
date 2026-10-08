<?php

namespace App\Domain\Examinations\Application\Marks;

use App\Support\Observability\MetricsRecorder;

/**
 * S5 observability follow-up (ADR 0068 §27.11; ADR 0051 §10): how often a
 * StudentMark request was aborted by PostgreSQL as a deadlock victim or a
 * serialization failure and answered 409 STUDENT_MARK_RETRY_REQUIRED. It is a
 * caller-retry conflict signal -- nothing was saved, nothing was lost, nothing
 * is retried automatically.
 *
 * Two CLOSED labels only: `operation` (StudentMarkOperation) and `reason`
 * (REASONS). Never a Student, mark, paper, Employee, User or School, never a
 * value or status, never SQL, an SQLSTATE, a constraint or an exception
 * message. Recording goes through the shared MetricsRecorder: outside the
 * transaction (a rollback never erases it) and best effort (a metrics store
 * failure never reaches the request).
 */
final class StudentMarkTelemetry
{
    public const string RETRYABLE_ABORTS = 'lycenza_student_mark_retryable_aborts_total';

    /** The `reason` values, in RetryableAbort::REASONS order (MetricCatalog::MARK_RETRY_REASONS, test-pinned). */
    public const array REASONS = ['deadlock', 'serialization_failure'];

    public function __construct(private readonly MetricsRecorder $metrics) {}

    /** @param  value-of<RetryableAbort::REASONS>  $reason */
    public function retryableAbort(StudentMarkOperation $operation, string $reason): void
    {
        $this->metrics->counter(self::RETRYABLE_ABORTS, 1, ['operation' => $operation->value, 'reason' => $reason]);
    }
}
