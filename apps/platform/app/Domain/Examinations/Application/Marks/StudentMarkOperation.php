<?php

namespace App\Domain\Examinations\Application\Marks;

/**
 * S5 observability follow-up (ADR 0068 §27.11): the CLOSED set of StudentMark
 * operations whose transaction boundary translates a retryable abort
 * (RetryableAbort::translate()). Its values are the `operation` label of
 * `lycenza_student_mark_retryable_aborts_total` -- chosen by the calling
 * service, never derived from a route, a request or an exception. Teacher and
 * administrative entry are both `record` (one service, one boundary). A new
 * StudentMark boundary needs a new case, reviewed against MetricCatalog.
 */
enum StudentMarkOperation: string
{
    case Record = 'record';
    case PaperLock = 'paper_lock';
    case CorrectionRequest = 'correction_request';
    case CorrectionApprove = 'correction_approve';
    case CorrectionReject = 'correction_reject';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $operation) => $operation->value, self::cases());
    }
}
