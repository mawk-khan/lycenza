<?php

namespace App\Domain\Students\Application;

use App\Domain\AcademicStructure\Application\Calendar\AcademicYearActivationBlocker;
use App\Domain\AcademicStructure\Infrastructure\AcademicYear;
use App\Domain\Students\Infrastructure\EnrollmentRolloverPlan;

/**
 * SIS 5.2: a rollover runs in bounded batches (several transactions) and only
 * into a DRAFT target. While a plan into this year is `executing`, activating
 * the year would leave the run half done in an active year, so activation
 * waits until the run is finished (completed or completed with errors) or
 * invalidated. Serialization: the claim share-locks the target year after
 * locking the plan, and activation reads this under its FOR UPDATE on the
 * year -- whichever commits first decides.
 */
final class RolloverExecutionActivationBlocker implements AcademicYearActivationBlocker
{
    public function blocksActivation(AcademicYear $year): ?string
    {
        $executing = EnrollmentRolloverPlan::query()
            ->where('school_id', $year->school_id)
            ->where('target_academic_year_id', $year->id)
            ->where('status', 'executing')
            ->exists();

        return $executing
            ? "An enrollment rollover into {$year->name} is still running. Finish it (Enrollment rollovers → Resume) before activating the year."
            : null;
    }
}
