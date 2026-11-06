<?php

namespace App\Domain\Payroll\Application\Exceptions;

/** HR.R1-C4 (PAY-16, OD-HR-22): a controlled refusal at posting; the run stays approved. */
class PostingAttestorNotIndependentException extends PayrollException
{
    public function __construct(public readonly string $runId)
    {
        parent::__construct(409, 'PAYROLL_POSTING_ATTESTOR_NOT_INDEPENDENT', 'You took part in this run, so you cannot attest to it. An independent person with payroll.runs.post must post it.');
    }
}
