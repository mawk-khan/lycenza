<?php

namespace App\Domain\Communications\Application\Exceptions;

/**
 * Thrown for any attempted state transition/edit outside the closed
 * set the Announcement lifecycle actually supports (brief §7):
 * editing a non-draft, cancelling a non-draft, or publishing a
 * non-draft. The exact attempted transition is included so a caller
 * can render a clear validation message.
 */
class InvalidAnnouncementTransitionException extends CommunicationException
{
    public function __construct(string $fromStatus, string $action)
    {
        parent::__construct("Cannot {$action} an announcement in '{$fromStatus}' status.");
    }
}
