<?php

namespace App\Domain\Fees\Application\Exceptions;

/**
 * Raised uniformly whether a caller-supplied `academicYearId`
 * genuinely does not exist OR exists only in a different School --
 * same structural, database-constraint-backed pattern as
 * `StudentNotFoundException` (see its docblock), for
 * `charges_academic_year_fk` against `academic_years(id, school_id)`.
 * `App\Domain\Fees` never reads Academic Structure's `AcademicYear`
 * Eloquent model directly.
 */
class AcademicYearNotFoundException extends FeesException
{
    public function __construct(public readonly string $academicYearId)
    {
        parent::__construct(404, 'ACADEMIC_YEAR_NOT_FOUND', "No academic year with id '{$academicYearId}' was found in this School.");
    }
}
