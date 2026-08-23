<?php

namespace App\Domain\AcademicStructure\Application\Exceptions;

/**
 * Phase 0D section 15: within one School, Academic Years may not
 * overlap by default.
 */
class AcademicYearOverlapException extends AcademicStructureException
{
    public function __construct()
    {
        parent::__construct(
            422,
            'ACADEMIC_YEAR_OVERLAP',
            'This date range overlaps an existing Academic Year for this School.',
        );
    }
}
