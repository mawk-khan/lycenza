<?php

namespace App\Domain\Students\Application\Exceptions;

/**
 * Phase 1C.1A: a SubjectOffering must be active to become the TARGET of
 * new Student subject participation -- mirrors this codebase's
 * established "deactivate, never delete" reference-entity convention
 * (docs/modules/ACADEMIC-STRUCTURE.md, "Reference-data lifecycle") and
 * the identical precedent already enforced by
 * App\Domain\HR\Application\EmployeeAssignmentService for
 * Position/Department. Thrown by `enroll()` and by `transfer()`'s
 * target-offering check only -- never by withdraw()/cancel(), and never
 * by transfer()'s source offering, so existing participation against an
 * offering that later becomes inactive remains fully manageable.
 */
class InactiveSubjectOfferingException extends StudentException
{
    public function __construct()
    {
        parent::__construct(
            422,
            'INACTIVE_SUBJECT_OFFERING',
            'This SubjectOffering is not active and cannot accept new Student subject participation.',
        );
    }
}
