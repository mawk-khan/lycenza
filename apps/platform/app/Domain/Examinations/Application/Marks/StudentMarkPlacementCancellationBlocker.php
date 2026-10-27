<?php

namespace App\Domain\Examinations\Application\Marks;

use App\Domain\Examinations\Infrastructure\StudentMark;
use App\Domain\Students\Application\PlacementCancellationBlocker;
use App\Models\School;

/**
 * SIS 4.2: a mark recorded against the placement (a mark keeps its placement
 * as provenance, ADR 0068). Such a placement is academic history and is never
 * cancelled as "never effective". Mark writers share-lock the covering
 * placement (lockEligibilityAsOf), so they serialize with the cancellation.
 */
final class StudentMarkPlacementCancellationBlocker implements PlacementCancellationBlocker
{
    public function blocksCancellation(School $school, string $enrollmentId): bool
    {
        return StudentMark::query()
            ->where('school_id', $school->id)
            ->where('student_enrollment_id', $enrollmentId)
            ->exists();
    }

    public function kind(): string
    {
        return 'the marks recorded for this placement';
    }
}
