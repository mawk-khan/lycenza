<?php

namespace App\Domain\Communications\Application\Exceptions;

/**
 * Phase 5A.12 §32/§33/§82/§83 -- thrown by
 * App\Domain\Communications\Application\AnnouncementService::publish()/
 * schedule() whenever the School's current policy requires approval
 * for this announcement and no currently-valid (status = 'approved',
 * fingerprint still matching the announcement's CURRENT content)
 * approval request exists. Never bypassed by a forged direct-publish
 * HTTP request, and never satisfied by a stale `status = 'approved'`
 * flag alone if the underlying content has since diverged (brief §32:
 * "Do not trust an `approved` flag alone").
 */
class ApprovalRequiredException extends CommunicationException
{
    public function __construct()
    {
        parent::__construct('This announcement requires a valid, matching approval before it can be published or scheduled.');
    }
}
