<?php

namespace App\Domain\Communications\Application\Exceptions;

/**
 * ORG.9D-2 (ADR 0076 §74): the fee reminder's initiator no longer holds the
 * Finance visibility its selection needs (finance.charges.view and
 * finance.payments.view, plus whole-School enrollments.view for a class
 * filter) -- the reminder is neither previewed nor published, and nothing
 * is narrowed or widened silently.
 */
class FeeReminderAuthorityException extends CommunicationException
{
    public function __construct()
    {
        parent::__construct('The person who prepared this fee reminder can no longer see these fee balances, so it cannot be sent.');
    }
}
