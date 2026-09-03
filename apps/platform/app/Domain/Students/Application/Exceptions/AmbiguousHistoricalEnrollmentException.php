<?php

namespace App\Domain\Students\Application\Exceptions;

use RuntimeException;

/**
 * Phase 0H.2: more than one StudentEnrollment for the SAME Student
 * qualifies for one as-of-date Section roster.
 *
 * `student_enrollments_one_active_per_student_year` (a PARTIAL unique
 * index, `WHERE status = 'active'`) structurally guarantees only ONE
 * CURRENTLY-ACTIVE placement per Student per AcademicYear. It says
 * nothing about terminal rows (`completed`/`withdrawn`/`transferred`/
 * `cancelled`), whose `[starts_on, ends_on]` intervals are what the
 * historical roster predicate actually reads
 * (App\Domain\Students\Application\StudentEnrollmentRosterReadService).
 * Two terminal intervals for one Student COULD therefore overlap on a
 * given date through bad or backdated data.
 *
 * When that happens the roster is genuinely ambiguous and Attendance
 * must fail closed: silently picking the first row, deduplicating, or
 * writing two AttendanceRecords for one human Student would each be a
 * different way of inventing an answer the data does not contain.
 */
class AmbiguousHistoricalEnrollmentException extends RuntimeException
{
    public function __construct(
        public readonly string $studentId,
        public readonly string $sectionId,
        public readonly string $asOfDate,
        public readonly int $qualifyingCount,
    ) {
        parent::__construct(
            "Student {$studentId} has {$qualifyingCount} StudentEnrollment rows qualifying for Section ".
            "{$sectionId} as of {$asOfDate}; the historical roster is ambiguous and cannot be used."
        );
    }

    /**
     * Rendered by the shared /api error envelope (bootstrap/app.php),
     * which reads these two methods. 409: the data is genuinely in
     * conflict with itself and a human must resolve it -- retrying the
     * identical request cannot help.
     */
    public function getStatusCode(): int
    {
        return 409;
    }

    public function errorCode(): string
    {
        return 'STUDENT_ENROLLMENT_AMBIGUOUS_HISTORICAL_PLACEMENT';
    }
}
