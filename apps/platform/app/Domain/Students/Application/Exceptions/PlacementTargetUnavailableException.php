<?php

namespace App\Domain\Students\Application\Exceptions;

/**
 * ORG.7F-2 (ADR 0076 §46, finding F-2): a new placement needs an active
 * Student in an active Section on an active Campus of the same School, and
 * (SIS 4.1, MC-2) a Section whose Academic Year is not closed. Data
 * validity, never authorization -- every enrollment-creating path (enroll,
 * transfer, rollover execution, Admissions conversion, Campus onboarding)
 * meets it under its locks.
 */
class PlacementTargetUnavailableException extends StudentException
{
    public const string STUDENT_INACTIVE = 'student_inactive';

    public const string SECTION_INACTIVE = 'section_inactive';

    public const string CAMPUS_INACTIVE = 'campus_inactive';

    public const string YEAR_CLOSED = 'year_closed';

    public function __construct(public readonly string $reason)
    {
        parent::__construct(422, 'PLACEMENT_TARGET_UNAVAILABLE', match ($reason) {
            self::STUDENT_INACTIVE => 'Only an active Student can be placed. Reactivate the Student first.',
            self::SECTION_INACTIVE => 'Choose an active Section.',
            self::YEAR_CLOSED => 'New placements cannot be added to a closed Academic Year.',
            default => 'Choose a Section on an active Campus.',
        });
    }
}
