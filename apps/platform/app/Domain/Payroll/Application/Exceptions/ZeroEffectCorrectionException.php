<?php

namespace App\Domain\Payroll\Application\Exceptions;

/**
 * A correction run's computed result for an EmploymentRecord nets to
 * exactly zero across gross, deductions, AND net -- deliberately
 * rejected rather than persisted as a valid, meaningless correction.
 * Fail-closed by design (mirroring this module's existing partial-
 * period gate): a zero-effect correction is far more likely to be a
 * data-entry mistake (e.g. a component resubmitted with the opposite
 * effect, cancelling itself out) than a deliberate action, and Phase 9
 * does not attempt to distinguish that case from a genuine no-op.
 */
class ZeroEffectCorrectionException extends PayrollException
{
    public function __construct(public readonly string $employmentRecordId, public readonly string $payrollRunId)
    {
        parent::__construct(422, 'PAYROLL_ZERO_EFFECT_CORRECTION', "Correction run '{$payrollRunId}' produces no economic effect for employment record '{$employmentRecordId}' -- rejected.");
    }
}
