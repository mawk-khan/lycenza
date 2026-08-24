<?php

namespace App\Domain\Communications\Application\Exceptions;

/**
 * Phase 5A.12 §28: "Submit for Approval" is only a meaningful action
 * when the School's current approval policy actually requires it for
 * THIS announcement -- there is no arbitrary "request approval anyway"
 * feature (brief §28's deliberate scope boundary).
 */
class ApprovalNotRequiredException extends CommunicationException
{
    public function __construct()
    {
        parent::__construct('This announcement does not require approval.');
    }
}
