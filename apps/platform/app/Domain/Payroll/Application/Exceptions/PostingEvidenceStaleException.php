<?php

namespace App\Domain\Payroll\Application\Exceptions;

/** HR.R1-C4 (PAY-16, OD-HR-22): the attestation names evidence that is no longer current; the run stays approved. */
class PostingEvidenceStaleException extends PayrollException
{
    public function __construct(public readonly string $runId)
    {
        parent::__construct(409, 'PAYROLL_POSTING_EVIDENCE_STALE', 'The evidence for this posting changed after you reviewed it. Review the current evidence and confirm it again.');
    }
}
