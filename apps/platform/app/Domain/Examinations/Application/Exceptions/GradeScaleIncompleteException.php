<?php

namespace App\Domain\Examinations\Application\Exceptions;

/**
 * A GradeScale may only transition to `active` if it contains a
 * GradeBand with `min_percentage = 0.00`. Because GradeBands store only
 * a lower-bound threshold, a `0.00` band is what guarantees the whole
 * 0.00-100.00 domain is covered with no gap -- no separate gap
 * algorithm exists or is needed.
 */
class GradeScaleIncompleteException extends ExaminationException
{
    public function __construct(public readonly string $gradeScaleId)
    {
        parent::__construct(422, 'GRADE_SCALE_INCOMPLETE', 'This GradeScale cannot be activated because it has no GradeBand at the 0.00 threshold.');
    }
}
