<?php

namespace App\Domain\AcademicStructure\Application\Exceptions;

/**
 * Phase 1F.3: a stable domain error for an academic-context mismatch --
 * used both at ElectiveGroup creation (a supplied AcademicYear/Campus/
 * GradeLevel that does not belong to the supplied School) and at
 * SubjectOffering assignment (an ElectiveGroup and SubjectOffering that
 * do not share the exact same School/AcademicYear/Campus/GradeLevel).
 * Returned BEFORE the database's own composite FKs would reject the
 * same mismatch, so normal application flow never surfaces a raw
 * QueryException for this case -- those FKs remain the structural
 * backstop, not the primary application-level signal.
 */
class ElectiveGroupContextMismatchException extends AcademicStructureException
{
    public function __construct()
    {
        parent::__construct(
            422,
            'ELECTIVE_GROUP_CONTEXT_MISMATCH',
            'The ElectiveGroup and the supplied academic context (School/AcademicYear/Campus/GradeLevel) do not match.',
        );
    }
}
