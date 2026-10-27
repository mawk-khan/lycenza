<?php

namespace App\Domain\Attendance\Application\Exceptions;

/**
 * A NEW register can only be submitted while its AcademicYear is active. Correcting an EXISTING record remains possible after closure -- historical corrections must never become impossible.
 */
class AcademicYearNotActiveException extends AttendanceException
{
    public function __construct(public readonly string $status)
    {
        // SIS 9.1: the operator's words, never a status key (same code).
        $state = ['draft' => 'is still a draft', 'closed' => 'is closed'][$status] ?? 'is not active';
        parent::__construct(409, 'ATTENDANCE_ACADEMIC_YEAR_NOT_ACTIVE', "A register can be taken only in the active academic year; this class's year {$state}.");
    }
}
