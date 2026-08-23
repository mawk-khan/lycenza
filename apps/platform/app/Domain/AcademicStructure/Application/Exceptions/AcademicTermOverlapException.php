<?php

namespace App\Domain\AcademicStructure\Application\Exceptions;

/**
 * Phase 0D section 21: Terms within the same Academic Year may not
 * overlap by default.
 */
class AcademicTermOverlapException extends AcademicStructureException
{
    public function __construct()
    {
        parent::__construct(
            422,
            'ACADEMIC_TERM_OVERLAP',
            'This date range overlaps an existing Term in the same Academic Year.',
        );
    }
}
