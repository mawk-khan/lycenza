<?php

namespace App\Domain\Payroll\Application\Exceptions;

/**
 * HR.R1-C6 (PAY-19, OD-HR-24): the actor is the Employee of a compensation change
 * this run pays first. A closed reason: it names neither the change nor the amount.
 * There is no override: someone not paid by that change must decide.
 */
class SelfBeneficiaryConflictException extends PayrollException
{
    public function __construct(public readonly string $runId)
    {
        parent::__construct(409, 'PAYROLL_SELF_BENEFICIARY_CONFLICT', 'This run pays a compensation change of your own for the first time, so you cannot decide on it. Someone whose own pay it does not change must do this.');
    }
}
