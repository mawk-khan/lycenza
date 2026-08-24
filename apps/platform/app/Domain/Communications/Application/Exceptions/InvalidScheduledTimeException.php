<?php

namespace App\Domain\Communications\Application\Exceptions;

/**
 * Thrown by AnnouncementService::schedule()/reschedule() when the
 * requested time is not strictly in the future at the moment of the
 * request (brief §16: "scheduled time is in the future at time of
 * scheduling").
 */
class InvalidScheduledTimeException extends CommunicationException
{
    public function __construct()
    {
        parent::__construct('The scheduled time must be in the future.');
    }
}
