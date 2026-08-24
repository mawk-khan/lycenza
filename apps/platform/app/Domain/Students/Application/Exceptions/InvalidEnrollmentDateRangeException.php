<?php

namespace App\Domain\Students\Application\Exceptions;

/**
 * Guards the same invariant as the database's
 * `student_enrollments_date_range_check` CHECK constraint
 * (`ends_on IS NULL OR ends_on >= starts_on`, Phase 1B.1), but caught
 * before any write for lifecycle transitions/transfers whose effective
 * date would otherwise produce an impossible historical interval --
 * e.g. a transfer effective on or before the source Enrollment's own
 * `starts_on` (which would compute a source `ends_on` before its
 * `starts_on`). The database CHECK constraint remains the
 * authoritative backstop; this is a clean, pre-write translation of
 * the same rule.
 */
class InvalidEnrollmentDateRangeException extends StudentException
{
    public function __construct(string $reason)
    {
        parent::__construct(422, 'INVALID_ENROLLMENT_DATE_RANGE', $reason);
    }
}
