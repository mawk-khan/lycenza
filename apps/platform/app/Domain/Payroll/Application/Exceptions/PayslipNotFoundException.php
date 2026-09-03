<?php

namespace App\Domain\Payroll\Application\Exceptions;

/**
 * Phase 9.10 -- raised uniformly whether the given EmploymentRecord
 * genuinely has no `PayrollRunResult` on this run OR the run/School
 * combination itself does not resolve (that second case is actually
 * caught earlier, by `PayrollRunNotFoundException` -- this exception
 * covers only "the run resolved, but this EmploymentRecord has no
 * result on it"), mirroring `PayrollRunNotFoundException`'s identical
 * "no oracle" precedent.
 */
class PayslipNotFoundException extends PayrollException
{
    public function __construct(public readonly string $payrollRunId, public readonly string $employmentRecordId)
    {
        parent::__construct(404, 'PAYROLL_PAYSLIP_NOT_FOUND', "No payroll result for EmploymentRecord '{$employmentRecordId}' was found on run '{$payrollRunId}'.");
    }
}
