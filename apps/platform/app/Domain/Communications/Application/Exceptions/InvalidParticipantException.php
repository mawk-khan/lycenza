<?php

namespace App\Domain\Communications\Application\Exceptions;

/**
 * Thrown when adding a participant who has no active SchoolMembership
 * in the thread's School -- a client-supplied user_id is never trusted
 * as authorization to add someone to a conversation (root CLAUDE.md
 * rule 19's principle applied here).
 */
class InvalidParticipantException extends CommunicationException
{
    public function __construct()
    {
        parent::__construct('The user is not an active member of this School.');
    }
}
