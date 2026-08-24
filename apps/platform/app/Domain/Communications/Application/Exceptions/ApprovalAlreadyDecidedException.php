<?php

namespace App\Domain\Communications\Application\Exceptions;

/**
 * Phase 5A.12 §31/§61 -- thrown when a decide/withdraw attempt's
 * atomic conditional UPDATE (`WHERE status = 'pending'`) matches zero
 * rows: either a concurrent approver already decided it, the requester
 * already withdrew it, or the caller is looking at a stale page. The
 * database's row-level locking is the actual concurrency guarantee --
 * this exception is just the deterministic, safe outcome for whichever
 * caller lost the race.
 */
class ApprovalAlreadyDecidedException extends CommunicationException
{
    public function __construct()
    {
        parent::__construct('This approval request has already been decided or withdrawn.');
    }
}
