<?php

namespace App\Domain\Payments\Application\Exceptions;

/**
 * Phase 0G.5 (rule 9/34): 0G.5's resolution of the "overpayment /
 * unallocated money" pre-code gate is MANDATORY full allocation -- the
 * caller-supplied allocation set must sum to exactly the settled
 * Payment amount. Rather than inventing unapplied-cash/customer-credit
 * accounting (undefined by FINANCE.md), a mismatched allocation set is
 * rejected outright: no Payment, no allocation, no ledger posting, no
 * unexplained money silently credited anywhere. True overpayment/
 * unapplied-cash handling remains explicitly deferred.
 */
class AllocationDoesNotSumToPaymentAmountException extends PaymentsException
{
    public function __construct(string $allocatedTotal, string $paymentAmount)
    {
        parent::__construct(
            422,
            'ALLOCATION_DOES_NOT_SUM_TO_PAYMENT_AMOUNT',
            "Allocation total '{$allocatedTotal}' does not equal the settled payment amount '{$paymentAmount}'; a settled payment must be fully allocated."
        );
    }
}
