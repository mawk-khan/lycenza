<?php

namespace App\Domain\Students\Application\Exceptions;

/**
 * Defense-in-depth ahead of the database's own composite-FK rejection
 * (`enrollment_rollover_subject_mappings`, Phase 1G.1) -- mirrors
 * CrossSchoolRolloverPlanException's identical rationale, applied to a
 * rollover Plan and a source/target SubjectOffering from two different
 * Schools.
 */
class CrossSchoolSubjectMappingException extends StudentException
{
    public function __construct()
    {
        parent::__construct(
            422,
            'CROSS_SCHOOL_SUBJECT_MAPPING',
            'A subject rollover mapping\'s Plan and source/target SubjectOffering must belong to the same School.',
        );
    }
}
