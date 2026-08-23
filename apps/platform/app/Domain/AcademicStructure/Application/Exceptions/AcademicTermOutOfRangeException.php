<?php

namespace App\Domain\AcademicStructure\Application\Exceptions;

/**
 * Phase 0D section 20: a Term's dates must fall within its parent
 * Academic Year's date range.
 */
class AcademicTermOutOfRangeException extends AcademicStructureException
{
    public function __construct()
    {
        parent::__construct(
            422,
            'ACADEMIC_TERM_OUT_OF_RANGE',
            'This Term\'s dates must fall within its Academic Year\'s date range.',
        );
    }
}
