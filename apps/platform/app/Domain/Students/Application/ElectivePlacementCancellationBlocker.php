<?php

namespace App\Domain\Students\Application;

use App\Domain\Students\Infrastructure\StudentSubjectEnrollment;
use App\Models\School;

/**
 * SIS 4.2: an active elective anchored to the placement (rollover applies the
 * next year's electives to the new placement). Cancelling the placement first
 * would leave the elective anchored to a placement that was never effective.
 * An elective enroll share-locks its anchor, so it serializes with the
 * cancellation's FOR UPDATE.
 */
final class ElectivePlacementCancellationBlocker implements PlacementCancellationBlocker
{
    public function blocksCancellation(School $school, string $enrollmentId): bool
    {
        return StudentSubjectEnrollment::query()
            ->where('school_id', $school->id)
            ->where('student_enrollment_id', $enrollmentId)
            ->where('status', 'active')
            ->exists();
    }

    public function kind(): string
    {
        return 'the active elective(s) on this placement (withdraw or cancel them)';
    }
}
