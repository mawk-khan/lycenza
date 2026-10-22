<?php

namespace App\Domain\Payroll\Application\Exceptions;

/**
 * ORG.8B (ADR 0076 §60): the run's results are not a complete calculation of
 * every recorded adjustment -- no calculation is recorded, the latest one left
 * an employment unresolved, or an adjustment came after it. Recalculate (and
 * resolve every employment) first.
 */
class RunRecalculationRequiredException extends PayrollException
{
    public function __construct(public readonly string $runId)
    {
        parent::__construct(409, 'PAYROLL_RUN_RECALCULATION_REQUIRED', "Payroll run {$runId} needs a complete recalculation before approval.");
    }
}
