<?php

namespace App\Domain\Guardians\Application\Exceptions;

/**
 * Defense-in-depth ahead of the database's own composite-FK rejection
 * (`student_guardian_relationships_student_id_school_id_foreign`/
 * `_guardian_id_school_id_foreign`, Phase 1A.2) -- this service never
 * relies on that FK failure alone to catch a Student/Guardian pair from
 * two different Schools; it checks first and fails with a clean domain
 * error (rule 24: "do not trust incoming references merely because RLS/
 * a constraint would eventually reject it").
 */
class CrossSchoolRelationshipException extends GuardianException
{
    public function __construct()
    {
        parent::__construct(
            422,
            'CROSS_SCHOOL_RELATIONSHIP',
            'A Student and Guardian must belong to the same School to be linked.',
        );
    }
}
