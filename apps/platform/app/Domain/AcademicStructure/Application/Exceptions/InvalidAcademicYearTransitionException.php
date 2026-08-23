<?php

namespace App\Domain\AcademicStructure\Application\Exceptions;

/**
 * Phase 0D sections 16-18: only `draft` -> `active` (activation) and
 * `active` -> `closed` (closing) are valid transitions in this
 * checkpoint. Reopening a closed year and archiving are deliberately
 * out of scope (section 18) -- see docs/modules/ACADEMIC-STRUCTURE.md.
 */
class InvalidAcademicYearTransitionException extends AcademicStructureException
{
    public function __construct(string $from, string $to)
    {
        parent::__construct(
            422,
            'INVALID_ACADEMIC_YEAR_TRANSITION',
            "Cannot transition an Academic Year from '{$from}' to '{$to}'.",
        );
    }
}
