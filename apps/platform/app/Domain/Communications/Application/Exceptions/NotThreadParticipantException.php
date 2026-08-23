<?php

namespace App\Domain\Communications\Application\Exceptions;

/**
 * Thrown when a User attempting to send a Message is not an active
 * participant of the target Thread -- never manufacture a participant
 * row on the fly just to let a send through (brief §19: "Do not
 * manufacture invalid teacher/guardian relationships merely for
 * convenience").
 */
class NotThreadParticipantException extends CommunicationException
{
    public function __construct()
    {
        parent::__construct('The sender is not an active participant of this thread.');
    }
}
