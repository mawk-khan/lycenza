<?php

namespace App\Domain\Examinations\Application\Exceptions;

/**
 * This GradeScale already has a GradeBand at this exact
 * `min_percentage` threshold.
 *
 * The authoritative guarantee is the database's own
 * `grade_bands_min_percentage_unique` constraint -- a plain UNIQUE
 * index, inherently race-safe. The service translates ONLY that
 * specific named constraint's violation into this exception; any other
 * unique violation stays an unexpected failure rather than being
 * silently mislabelled.
 */
class DuplicateGradeBandThresholdException extends ExaminationException
{
    public function __construct(public readonly string $minPercentage)
    {
        parent::__construct(422, 'GRADE_SCALE_BAND_DUPLICATE_THRESHOLD', "A GradeBand at the threshold {$minPercentage} already exists in this GradeScale.");
    }
}
