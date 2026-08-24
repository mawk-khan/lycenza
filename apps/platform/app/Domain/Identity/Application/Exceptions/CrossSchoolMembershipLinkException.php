<?php

namespace App\Domain\Identity\Application\Exceptions;

/**
 * Thrown when the target SchoolMembership does not belong to the same
 * School as the Student/Guardian being linked -- root CLAUDE.md rule
 * 19's "an id alone is not authorization" applied to account linking.
 * Rejected here BEFORE any write, with a clean message; the composite
 * FK (sgal_membership_school_foreign) is the database-level backstop,
 * not the primary defense.
 */
class CrossSchoolMembershipLinkException extends AccountLinkException
{
    public function __construct()
    {
        parent::__construct('That School OS account does not belong to this School.');
    }
}
