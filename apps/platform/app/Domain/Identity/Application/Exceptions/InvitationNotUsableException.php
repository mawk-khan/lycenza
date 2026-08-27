<?php

namespace App\Domain\Identity\Application\Exceptions;

/**
 * The single generic failure returned to a caller presenting an
 * unusable token -- invalid, expired, revoked, already accepted, or
 * whose destination email no longer matches the Guardian's current
 * contact (brief §29 contact-drift, §42 "no enumeration"). Deliberately
 * carries no detail about WHICH of those was true: a public,
 * unauthenticated caller must never be able to distinguish "wrong
 * token" from "right token, wrong reason" via the error message.
 */
class InvitationNotUsableException extends AccountInvitationException
{
    public function __construct()
    {
        parent::__construct('This invitation link is no longer valid.');
    }
}
