<?php

namespace App\Domain\Attendance\Application\Exceptions;

/**
 * A NEW register can only be submitted while its AcademicYear is active. Correcting an EXISTING record remains possible after closure -- historical corrections must never become impossible.
 */
class AcademicYearNotActiveException extends AttendanceException
{
    public function __construct(public readonly string $status)
    {
        parent::__construct(409, 'ATTENDANCE_ACADEMIC_YEAR_NOT_ACTIVE', "A new register cannot be submitted: this AcademicYear is '{$status}', not 'active'.");
    }
}
