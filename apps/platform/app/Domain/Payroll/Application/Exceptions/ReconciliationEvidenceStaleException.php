<?php

namespace App\Domain\Payroll\Application\Exceptions;

/** HR.R1-C6: what the reviewer read is no longer what is true, so they read it again. */
class ReconciliationEvidenceStaleException extends PayrollException
{
    public function __construct(public readonly string $reconciliationId)
    {
        parent::__construct(409, 'PAYROLL_RECONCILIATION_EVIDENCE_STALE', 'The evidence changed since you opened this page. Read it again before reviewing.');
    }
}
