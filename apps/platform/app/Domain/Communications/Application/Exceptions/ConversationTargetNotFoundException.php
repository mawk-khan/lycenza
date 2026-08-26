<?php

namespace App\Domain\Communications\Application\Exceptions;

/**
 * Thrown when a supplied Guardian/Student id does not resolve within
 * the acting School -- covers both a genuinely nonexistent id and a
 * cross-School forgery attempt (root CLAUDE.md rule 19/40: an id alone
 * is never trusted, and a cross-School id must never leak whether it
 * exists elsewhere). Deliberately indistinguishable from "does not
 * exist at all" in its message.
 */
class ConversationTargetNotFoundException extends CommunicationException
{
    public function __construct()
    {
        parent::__construct('The selected participant could not be found in this School.');
    }
}
