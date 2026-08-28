<?php

namespace App\Domain\AcademicStructure\Application\Exceptions;

/**
 * Phase 1F.3: once ANY StudentSubjectEnrollment row exists for a
 * SubjectOffering (active, withdrawn, cancelled, OR transferred -- this
 * is historical immutability, not current occupancy), its ElectiveGroup
 * assignment is frozen: assigning it to a group for the first time,
 * changing its group, and removing its group assignment are all
 * rejected. This protects the same "configuration cannot silently
 * invalidate history" invariant architecture doc §13/§18A already
 * established -- a legacy participation row whose own snapshot is NULL
 * still counts as history and still blocks assignment (initial-adoption
 * semantics, §18A).
 *
 * Safe context is the SubjectOffering id only -- no Student identity is
 * required to explain a configuration error, and no participant count
 * is returned.
 */
class ElectiveGroupAssignmentLockedException extends AcademicStructureException
{
    public function __construct()
    {
        parent::__construct(
            422,
            'ELECTIVE_GROUP_ASSIGNMENT_LOCKED',
            'This SubjectOffering already has Student participation history -- its ElectiveGroup assignment cannot change.',
        );
    }
}
