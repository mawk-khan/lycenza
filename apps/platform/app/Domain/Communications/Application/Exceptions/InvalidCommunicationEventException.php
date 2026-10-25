<?php

namespace App\Domain\Communications\Application\Exceptions;

/**
 * ORG.9D-3 (ADR 0076 §75): an Event's terms or lifecycle step refused --
 * the message names the problem in the School's words, never another
 * School's data.
 */
class InvalidCommunicationEventException extends CommunicationException
{
    public function __construct(string $message, public readonly string $field = 'event')
    {
        parent::__construct($message);
    }
}
