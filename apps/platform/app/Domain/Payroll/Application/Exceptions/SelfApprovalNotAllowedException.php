<?php

namespace App\Domain\Payroll\Application\Exceptions;

/**
 * Phase 9.4 (ADR 0034 "Separation of duties") -- the preparer of a
 * payroll run may never approve it. Mirrors
 * `App\Domain\Communications\Application\Exceptions\SelfApprovalNotAllowedException`'s
 * exact role and rule, the one existing repository precedent for this
 * specific invariant -- enforced here at the Application layer AND
 * backed by the database CHECK `payroll_runs_sod_check` (Checkpoint
 * 9.1), which rejects it independently even if this check were ever
 * bypassed.
 */
class SelfApprovalNotAllowedException extends PayrollException
{
    public function __construct(public readonly string $runId)
    {
        parent::__construct(422, 'PAYROLL_SELF_APPROVAL_NOT_ALLOWED', "Payroll run {$runId} cannot be approved by the same user who prepared it.");
    }
}
