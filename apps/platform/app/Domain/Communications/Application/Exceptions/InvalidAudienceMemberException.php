<?php

namespace App\Domain\Communications\Application\Exceptions;

/**
 * Thrown when authoring an `individual` audience with a user id that
 * has no active SchoolMembership in the Announcement's School -- the
 * same "an id alone is never authorization" principle as
 * InvalidParticipantException, applied to audience membership instead
 * of thread participation (root CLAUDE.md rule 19).
 */
class InvalidAudienceMemberException extends CommunicationException
{
    public function __construct()
    {
        parent::__construct('The user is not an active member of this School and cannot be targeted.');
    }
}
