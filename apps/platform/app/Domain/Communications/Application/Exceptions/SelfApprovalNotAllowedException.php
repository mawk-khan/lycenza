<?php

namespace App\Domain\Communications\Application\Exceptions;

/**
 * Phase 5A.12 §13 -- separation of duties: the requester of an
 * approval-required communication may never also be its approver.
 * Mandatory foundation rule, no exception path exists in 5A.12.
 */
class SelfApprovalNotAllowedException extends CommunicationException
{
    public function __construct()
    {
        parent::__construct('You cannot approve or reject your own communication request.');
    }
}
