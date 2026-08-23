<?php

namespace App\Domain\Guardians\Application\Exceptions;

/**
 * Translates `unique(school_id, student_id, guardian_id)`'s violation
 * (Phase 1A.2) into a predictable domain error -- the database
 * constraint remains authoritative; see
 * App\Domain\Guardians\Application\StudentGuardianRelationshipService::link().
 */
class DuplicateRelationshipException extends GuardianException
{
    public function __construct()
    {
        parent::__construct(
            422,
            'DUPLICATE_STUDENT_GUARDIAN_RELATIONSHIP',
            'This Guardian is already linked to this Student.',
        );
    }
}
