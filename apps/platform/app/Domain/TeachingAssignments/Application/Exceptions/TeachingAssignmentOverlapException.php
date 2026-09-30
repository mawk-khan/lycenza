<?php

namespace App\Domain\TeachingAssignments\Application\Exceptions;

/**
 * TCH.2 (ADR 0063 section 10): the Employee already owns this Section + SubjectOffering
 * for an overlapping period (inclusive dates). Checked under the assignment
 * key's advisory lock, so two concurrent creates can never both succeed.
 */
class TeachingAssignmentOverlapException extends TeachingAssignmentException
{
    public function __construct()
    {
        parent::__construct(409, 'TEACHING_ASSIGNMENT_OVERLAP', 'This Employee already has a Teaching Assignment for this class and subject overlapping these dates.');
    }
}
