<?php

namespace App\Domain\Communications\Application\Exceptions;

/**
 * Thrown when a School's own
 * App\Domain\Communications\Application\Policy\CommunicationConversationPolicyService
 * override disables Guardian/Student private conversations, even
 * though the acting user holds the relevant
 * communications.conversations.guardians/.students capability (brief
 * §16 -- capability and school policy are independent gates, both must
 * allow).
 */
class ConversationPolicyDisabledException extends CommunicationException
{
    public function __construct()
    {
        parent::__construct('This School has not enabled private conversations with this type of participant.');
    }
}
