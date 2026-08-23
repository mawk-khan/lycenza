<?php

namespace App\Domain\Communications\Application\Exceptions;

/**
 * Phase 5A.10 §6: `dispatch_mode = EMERGENCY AND requirement !=
 * REQUIRED` is always rejected -- Emergency reuses Phase 5A.5's
 * existing REQUIRED preference-bypass semantics rather than a second
 * implementation, so an Emergency communication that isn't Required
 * would have no defined preference-bypass behavior at all.
 */
class EmergencyMustBeRequiredException extends CommunicationException
{
    public function __construct()
    {
        parent::__construct('An Emergency communication must be Required.');
    }
}
