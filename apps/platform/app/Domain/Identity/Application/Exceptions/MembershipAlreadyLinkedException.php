<?php

namespace App\Domain\Identity\Application\Exceptions;

/**
 * Thrown when the target SchoolMembership already has a different
 * active Student/Guardian persona link -- one membership has at most
 * one active domain-identity link at a time (docs/communication-hub/
 * PHASE-5B-2-STUDENT-GUARDIAN-ACCOUNT-LINK-INAPP.md "Link
 * constraints"). A membership's own staff role is unaffected either
 * way; this exception is purely about the persona-link table.
 */
class MembershipAlreadyLinkedException extends AccountLinkException
{
    public function __construct()
    {
        parent::__construct('This School OS account is already linked to a different Student or Guardian.');
    }
}
