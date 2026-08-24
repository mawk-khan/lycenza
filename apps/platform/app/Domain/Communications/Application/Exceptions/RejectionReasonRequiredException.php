<?php

namespace App\Domain\Communications\Application\Exceptions;

/**
 * Phase 5A.12 §30/§65 -- rejecting an approval request always requires
 * a non-empty, bounded plain-text reason (approving remains optional).
 */
class RejectionReasonRequiredException extends CommunicationException
{
    public function __construct()
    {
        parent::__construct('A reason is required to reject this approval request.');
    }
}
