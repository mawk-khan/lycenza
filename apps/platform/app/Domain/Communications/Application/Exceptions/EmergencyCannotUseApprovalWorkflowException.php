<?php

namespace App\Domain\Communications\Application\Exceptions;

/**
 * Phase 5A.12 §10/§40 -- a deliberate governance decision: Emergency
 * communications never enter the normal approval queue (they already
 * require the dedicated `communications.emergency` capability and
 * explicit publish-time acknowledgement, brief §10/§40) -- so
 * "Submit for Approval" on an Emergency draft is always rejected,
 * never silently accepted into a queue that could delay a
 * time-critical communication.
 */
class EmergencyCannotUseApprovalWorkflowException extends CommunicationException
{
    public function __construct()
    {
        parent::__construct('Emergency communications do not use the normal approval workflow.');
    }
}
