<?php

namespace App\Domain\AcademicStructure\Application\Exceptions;

/** SIS 5.2: an AcademicYearActivationBlocker reported work that activation must not cut in half. */
class AcademicYearActivationBlockedException extends AcademicStructureException
{
    public function __construct(string $message)
    {
        parent::__construct(409, 'ACADEMIC_YEAR_ACTIVATION_BLOCKED', $message);
    }
}
