<?php

namespace App\Domain\Identity\Application\Exceptions;

/**
 * Thrown when attempting to invite a Guardian who already has an
 * active School OS account link -- inviting again would be
 * meaningless (and would collide with `giai_one_pending_per_guardian`
 * only incidentally; this check gives a clearer domain error first).
 */
class GuardianAlreadyHasAccountLinkException extends AccountInvitationException
{
    public function __construct()
    {
        parent::__construct('This Guardian already has an active School OS account link.');
    }
}
