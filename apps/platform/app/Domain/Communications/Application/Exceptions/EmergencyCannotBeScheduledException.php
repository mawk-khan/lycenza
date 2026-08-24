<?php

namespace App\Domain\Communications\Application\Exceptions;

/**
 * Phase 5A.10 §28: an Emergency communication can never enter the
 * `scheduled` state -- neither by calling schedule() on an Emergency
 * draft, nor by setting dispatch_mode=Emergency on an announcement
 * that is already scheduled. Emergency is an immediate exceptional
 * dispatch mode; scheduled Emergency dispatch raises unresolved
 * governance questions (stale incident context, authorization
 * revocation, policy drift between schedule-time and due-time) that
 * are explicitly deferred, not solved here.
 */
class EmergencyCannotBeScheduledException extends CommunicationException
{
    public function __construct()
    {
        parent::__construct('An Emergency communication cannot be scheduled -- it must be published immediately.');
    }
}
