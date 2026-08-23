<?php

namespace App\Domain\AcademicStructure\Application\Exceptions;

class NoActiveAcademicYearException extends AcademicStructureException
{
    public function __construct(string $schoolId)
    {
        parent::__construct(
            422,
            'NO_ACTIVE_ACADEMIC_YEAR',
            "School {$schoolId} has no active Academic Year.",
        );
    }
}
