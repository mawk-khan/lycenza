<?php

namespace App\Domain\Examinations\Application\Exceptions;

/**
 * A GradeBand may be created, updated, or deleted only while its
 * parent GradeScale is `draft`. Once the parent has ever been
 * `active`, its bands are frozen forever -- including while the parent
 * is later `inactive` -- which is what makes a GradeScale's identity a
 * permanently stable historical reference. A grading-policy change is
 * represented by creating a new GradeScale, never by rewriting bands
 * on one that has ever been active.
 */
class GradeBandNotMutableException extends ExaminationException
{
    public function __construct(public readonly string $gradeScaleId, public readonly string $gradeScaleStatus)
    {
        parent::__construct(422, 'GRADE_SCALE_BAND_NOT_MUTABLE', "GradeBands cannot be modified while the GradeScale status is '{$gradeScaleStatus}'.");
    }
}
