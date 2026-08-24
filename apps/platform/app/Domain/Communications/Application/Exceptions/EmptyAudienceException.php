<?php

namespace App\Domain\Communications\Application\Exceptions;

/**
 * Thrown by AnnouncementService::publish() when audience resolution
 * yields zero recipients (brief §19/§27: "empty audience handled
 * safely"). Rolls back the whole publish transaction, including the
 * draft->published status claim -- the Announcement stays a draft, not
 * a persisted "published with nobody" record.
 */
class EmptyAudienceException extends CommunicationException
{
    public function __construct()
    {
        parent::__construct('The resolved audience is empty -- there is nobody to publish this announcement to.');
    }
}
