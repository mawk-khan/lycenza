<?php

namespace App\Domain\Identity\Application\Exceptions;

/**
 * Brief §11-13/§20-21: when the invited email already belongs to a
 * real, previously-activated User, activation must never accept a new
 * password from the (unauthenticated) invitation link itself -- that
 * would let mere possession of the email prove ownership of an
 * ALREADY-established credential, a strictly weaker guarantee than the
 * credential itself. The caller must already be authenticated as that
 * exact User before `GuardianAccountActivationService::accept()` will
 * proceed for this branch.
 */
class ExistingAccountConfirmationRequiredException extends AccountInvitationException
{
    public function __construct()
    {
        parent::__construct('An account with this email already exists. Log in with that account, then return to this invitation to confirm.');
    }
}
