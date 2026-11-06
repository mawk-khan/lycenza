<?php

namespace App\Domain\Payroll\Application\Exceptions;

/** HR.R1-C4 (PAY-16, OD-HR-22): a controlled refusal at posting; the run stays approved. */
class PostingAttestorCannotReviewException extends PayrollException
{
    public function __construct(public readonly string $runId)
    {
        parent::__construct(403, 'PAYROLL_POSTING_ATTESTOR_CANNOT_REVIEW', 'Attesting needs sight of the compensation evidence. An independent person who holds payroll.compensation.sensitive.view must post this run.');
    }
}
