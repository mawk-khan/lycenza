<?php

namespace App\Domain\Examinations\Application\Exceptions;

/**
 * The requested GradeScale status transition is not one of the exactly
 * three legal transitions (draft->active, active->inactive,
 * inactive->active). Every other transition, INCLUDING every no-op
 * (draft->draft, active->active, inactive->inactive), is illegal --
 * mirroring `CurriculumDeliveryService`'s own `NoOpTransitionException`
 * precedent of rejecting a no-op rather than silently succeeding.
 */
class GradeScaleIllegalTransitionException extends ExaminationException
{
    public function __construct(public readonly string $fromStatus, public readonly string $toStatus)
    {
        parent::__construct(422, 'GRADE_SCALE_ILLEGAL_TRANSITION', "Cannot transition a GradeScale from '{$fromStatus}' to '{$toStatus}'.");
    }
}
