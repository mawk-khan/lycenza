<?php

namespace App\Domain\AcademicStructure\Application\Exceptions;

/**
 * SIS 5.2 (owner decision): a closed Academic Year is historical. It keeps
 * its existing Sections and Terms (readable, never deleted or deactivated for
 * it) but takes no new ones.
 */
class AcademicYearClosedForStructureException extends AcademicStructureException
{
    public function __construct(string $yearName, string $what)
    {
        parent::__construct(
            422,
            'ACADEMIC_YEAR_CLOSED',
            "Academic Year {$yearName} is closed. It keeps its existing {$what}s but takes no new ones.",
        );
    }
}
