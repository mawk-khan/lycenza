<?php

namespace App\Domain\Communications\Application\Exceptions;

/**
 * Phase 5A.12 §27: at most one active PENDING approval request may
 * exist per Announcement at a time -- enforced authoritatively by a
 * Postgres partial unique index, never a check-then-insert.
 */
class ApprovalAlreadyPendingException extends CommunicationException
{
    public function __construct()
    {
        parent::__construct('An approval request is already pending for this announcement.');
    }
}
