<?php

namespace App\Domain\Communications\Application\Exceptions;

/**
 * Thrown when a Guardian/Student has no active
 * App\Domain\Identity\Infrastructure\StudentGuardianAccountLink, or
 * their linked SchoolMembership is no longer active. Brief §10/§5: an
 * account link only proves authenticated reachability -- it is never
 * created automatically, and this exception is the enforcement point
 * for "unlinked is unavailable for private conversation participation."
 */
class ConversationParticipantNotLinkedException extends CommunicationException
{
    public function __construct()
    {
        parent::__construct('This participant has no active School OS account link and cannot join a private conversation.');
    }
}
