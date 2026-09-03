<?php

namespace App\Domain\Payroll\Statutory\Application\Exceptions;

/**
 * Checkpoint 9.6F (ADR 0036 correction addendum §1.2) -- every EARNING
 * salary component participating in a run's result must have a
 * `payroll_salary_component_statutory_classifications` row covering
 * the calculation date before statutory calculation can proceed. This
 * is a deliberate fail-closed choice: silently excluding an
 * unclassified earning from PF/ESI wage or taxable income risks
 * under-withholding; silently including it risks over-taxing an
 * intentionally-exempt component. Neither guess is acceptable -- the
 * component must be explicitly classified first.
 */
class StatutorySalaryComponentNotClassifiedException extends StatutoryPayrollException
{
    public function __construct(public readonly string $salaryComponentId, string $asOf)
    {
        parent::__construct(
            422,
            'STATUTORY_SALARY_COMPONENT_NOT_CLASSIFIED',
            "Salary component '{$salaryComponentId}' has no statutory classification effective as of {$asOf} -- classify it before running statutory calculation.",
        );
    }
}
