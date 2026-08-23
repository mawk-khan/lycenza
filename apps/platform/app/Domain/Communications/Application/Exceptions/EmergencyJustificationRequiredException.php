<?php

namespace App\Domain\Communications\Application\Exceptions;

/**
 * Phase 5A.10 §23: a short internal justification is mandatory
 * whenever dispatch_mode=Emergency is set, enforced here independently
 * of the controller's own `required_if` validation rule -- Application-
 * layer callers (including direct service tests) get the same
 * guarantee HTTP callers do.
 */
class EmergencyJustificationRequiredException extends CommunicationException
{
    public function __construct()
    {
        parent::__construct('An Emergency communication requires a short internal justification.');
    }
}
