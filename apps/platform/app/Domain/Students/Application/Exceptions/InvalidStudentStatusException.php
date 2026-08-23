<?php

namespace App\Domain\Students\Application\Exceptions;

/**
 * `status` remains a plain validated string (not a PHP backed enum),
 * matching every other lifecycle `status` column in this codebase
 * (GradeLevel/AcademicYear/Section/Subject) -- see
 * docs/modules/STUDENT-GUARDIAN-IDENTITY.md ("Status remains a plain
 * string"). This exception is the domain-level guard for the two values
 * currently supported.
 */
class InvalidStudentStatusException extends StudentException
{
    public function __construct(string $status)
    {
        parent::__construct(
            422,
            'INVALID_STUDENT_STATUS',
            "\"{$status}\" is not a valid Student status. Supported: active, inactive.",
        );
    }
}
