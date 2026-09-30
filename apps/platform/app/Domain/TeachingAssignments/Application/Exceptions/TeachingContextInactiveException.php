<?php

namespace App\Domain\TeachingAssignments\Application\Exceptions;

/**
 * TCH.2 (CLAUDE.md rule 73): a new assignment never references a
 * deactivated Section or SubjectOffering.
 */
class TeachingContextInactiveException extends TeachingAssignmentException
{
    public function __construct()
    {
        parent::__construct(422, 'TEACHING_ASSIGNMENT_CONTEXT_INACTIVE', 'The Section or Subject Offering is not active.');
    }
}
