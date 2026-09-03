<?php

namespace App\Domain\Payroll\Application\Exceptions;

/**
 * A correction run may not be created against a regular run whose
 * ORIGINAL posting has already been reversed -- once reversed, the
 * original's accounting effect no longer exists in Finance's ledger,
 * so there is nothing left to correct. This is the "no contradictory
 * reversal/correction state" guard: creating a NEW correction against
 * an already-void original would produce a delta with no economic
 * basis. The reverse direction is deliberately NOT restricted --
 * reversing an original that already has one or more correction runs
 * referencing it is allowed (a correction is an independent,
 * already-posted delta in its own right, exactly like
 * `App\Domain\Finance\Application\LedgerService::reverse()`'s own
 * "any posted entry may be reversed, uniformly" stance; adding a
 * restriction neither the schema nor ADR 0034 requires would itself
 * be inventing policy, mirroring that class's identical reasoning).
 */
class CorrectionTargetAlreadyReversedException extends PayrollException
{
    public function __construct(public readonly string $payrollRunId)
    {
        parent::__construct(422, 'PAYROLL_CORRECTION_TARGET_ALREADY_REVERSED', "Cannot create a correction run against payroll run '{$payrollRunId}' -- its original posting has already been reversed.");
    }
}
