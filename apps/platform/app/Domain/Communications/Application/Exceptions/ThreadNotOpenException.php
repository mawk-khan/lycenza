<?php

namespace App\Domain\Communications\Application\Exceptions;

/**
 * Phase 5A.7 §28/§35 -- thrown when an action that requires a
 * sendable conversation (attaching a pending file, sending a message)
 * targets a Thread whose `status` is not `open` (`archived`/`closed`).
 * Mirrors InvalidAnnouncementTransitionException's role for the
 * Announcement lifecycle, kept as a distinct class since a Thread's
 * state machine is not an Announcement's.
 */
class ThreadNotOpenException extends CommunicationException
{
    public function __construct(public readonly string $status)
    {
        parent::__construct("This conversation is {$status} and cannot be modified.");
    }
}
