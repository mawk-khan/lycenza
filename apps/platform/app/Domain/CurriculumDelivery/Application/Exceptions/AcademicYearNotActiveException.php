<?php

namespace App\Domain\CurriculumDelivery\Application\Exceptions;

/**
 * A NEW delivery record can only be started while its AcademicYear is
 * active. Correcting or transitioning an EXISTING delivery remains
 * possible after closure -- the same historical-correction discipline
 * Attendance established: a closed year must never make a genuine
 * clerical correction impossible.
 */
class AcademicYearNotActiveException extends CurriculumDeliveryException
{
    public function __construct(public readonly string $status)
    {
        parent::__construct(409, 'CURRICULUM_DELIVERY_ACADEMIC_YEAR_NOT_ACTIVE', "A new delivery cannot be started: this AcademicYear is '{$status}', not 'active'.");
    }
}
