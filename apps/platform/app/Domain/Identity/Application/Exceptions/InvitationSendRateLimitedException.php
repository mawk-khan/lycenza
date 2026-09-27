<?php

namespace App\Domain\Identity\Application\Exceptions;

/**
 * ADR 0055 section 15: invitation send/resend throttled. The message is
 * the same whatever the Guardian or address -- it never discloses whether
 * a recipient exists.
 */
class InvitationSendRateLimitedException extends AccountInvitationException
{
    public function __construct()
    {
        parent::__construct('Too many invitation emails were requested. Please wait and try again later.');
    }
}
