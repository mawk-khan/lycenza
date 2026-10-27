<?php

namespace App\Domain\Fees\Application;

use App\Domain\Fees\Infrastructure\FeeAssessment;
use App\Domain\Students\Application\PlacementCancellationBlocker;
use App\Models\School;

/**
 * SIS 4.2: a fee assessment (and so a Charge) already raised for the
 * placement. Cancelling the placement before its start must not leave a
 * charge for a placement that never took effect; the Charge is cancelled in
 * Finance first (that voids its assessment). The assessment executor re-reads
 * the placement FOR SHARE, so it serializes with the cancellation.
 */
final class FeeAssessmentPlacementCancellationBlocker implements PlacementCancellationBlocker
{
    public function blocksCancellation(School $school, string $enrollmentId): bool
    {
        return FeeAssessment::query()
            ->where('school_id', $school->id)
            ->where('student_enrollment_id', $enrollmentId)
            ->whereNull('voided_at')
            ->exists();
    }

    public function kind(): string
    {
        return 'the fee charges raised for this placement (cancel them in Finance)';
    }
}
