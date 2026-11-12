<?php

namespace App\Domain\Payroll\Application\Exceptions;

/**
 * HR.R1-C34 (WO-16, C33): the decider's account is known to be the same person as someone who
 * took part (two accounts with uncorrected links to one Employee, rule 145). Names no account.
 * Approval, posting attestation and reconciliation review; the item is unchanged.
 */
class KnownSamePersonDecisionException extends PayrollException
{
    public function __construct(public readonly string $itemId)
    {
        parent::__construct(409, 'PAYROLL_SAME_PERSON_DECISION', 'One of your accounts is known to belong to a person who took part in this, so another person must decide it.');
    }
}
