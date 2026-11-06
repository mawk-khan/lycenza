<?php

namespace App\Domain\Payroll\Application\Exceptions;

/** HR.R1-C4 (PAY-16, OD-HR-22): a controlled refusal at posting; the run stays approved. */
class PostingAttestationRequiredException extends PayrollException
{
    public function __construct(public readonly string $runId)
    {
        parent::__construct(409, 'PAYROLL_POSTING_ATTESTATION_REQUIRED', 'The evidence behind this approval changed. Review it and confirm the attestation before posting.');
    }
}
