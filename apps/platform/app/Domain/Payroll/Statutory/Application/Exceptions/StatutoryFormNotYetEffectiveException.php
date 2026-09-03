<?php

namespace App\Domain\Payroll\Statutory\Application\Exceptions;

/**
 * Checkpoint 9.6G (ADR 0035 correction addendum §1.4) -- Form 138
 * applies to periods from April 2026 onward only (never Form 24Q,
 * which this checkpoint does not implement at all). A draft statement
 * requested for an earlier period is refused rather than silently
 * produced under the wrong legal form.
 */
class StatutoryFormNotYetEffectiveException extends StatutoryPayrollException
{
    public function __construct(string $formName, string $effectiveFrom)
    {
        parent::__construct(422, 'STATUTORY_FORM_NOT_YET_EFFECTIVE', "{$formName} applies to periods from {$effectiveFrom} onward only.");
    }
}
