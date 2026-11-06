<?php

namespace App\Domain\Payroll\Application\Exceptions;

/** HR.R1-C6: a review is final, so a second one changes nothing. */
class ReconciliationAlreadyReviewedException extends PayrollException
{
    public function __construct(public readonly string $reconciliationId)
    {
        parent::__construct(409, 'PAYROLL_RECONCILIATION_ALREADY_REVIEWED', 'This reconciliation has already been reviewed.');
    }
}
