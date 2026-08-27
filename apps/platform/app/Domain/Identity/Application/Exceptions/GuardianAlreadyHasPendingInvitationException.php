<?php

namespace App\Domain\Identity\Application\Exceptions;

/**
 * Brief §18: a Guardian may have at most one usable pending
 * invitation at a time -- issuing a second requires resending
 * (revoke-then-reissue), never accumulating unbounded concurrent
 * tokens. Backed by `giai_one_pending_per_guardian`, never a
 * check-then-insert.
 */
class GuardianAlreadyHasPendingInvitationException extends AccountInvitationException
{
    public function __construct()
    {
        parent::__construct('This Guardian already has a pending invitation. Resend it instead of creating a new one.');
    }
}
