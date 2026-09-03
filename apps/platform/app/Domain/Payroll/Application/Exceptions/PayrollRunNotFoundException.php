<?php

namespace App\Domain\Payroll\Application\Exceptions;

/**
 * Phase 9.7 -- raised uniformly whether a caller-supplied payroll run
 * id genuinely does not exist OR exists only in a different School.
 * `PayrollRunResultReadService::listResults()` always resolves the id
 * through a School-scoped query
 * (`where('school_id', $school->id)->find($id)`) run under the trusted
 * School's own TenantContext/RLS -- a cross-School id is simply absent
 * from the result, identical to a nonexistent one, mirroring
 * `App\Domain\Payments\Application\Exceptions\PaymentNotFoundException`'s
 * identical "no oracle" precedent.
 */
class PayrollRunNotFoundException extends PayrollException
{
    public function __construct(public readonly string $payrollRunId)
    {
        parent::__construct(404, 'PAYROLL_RUN_NOT_FOUND', "No payroll run with id '{$payrollRunId}' was found in this School.");
    }
}
