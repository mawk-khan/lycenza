<?php

namespace App\Domain\Payroll\Application\Exceptions;

/** HR.R1-C6: a review is an explicit statement, never implied by opening the page. */
class ReconciliationAcceptanceRequiredException extends PayrollException
{
    public function __construct(public readonly string $reconciliationId)
    {
        parent::__construct(422, 'PAYROLL_RECONCILIATION_ACCEPTANCE_REQUIRED', 'Confirm that you have read what happened and accept it as reconciled.');
    }
}
