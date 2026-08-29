<?php

namespace App\Domain\Payroll\Application\Exceptions;

/**
 * Phase 9.5 -- raised by `PayrollAccountingConfigurationService::resolveValidated()`
 * when a School has never configured `payroll_accounting_configurations`
 * at all, mirroring
 * `App\Domain\Canteen\Application\Exceptions\CanteenBillingNotConfiguredException`'s
 * identical role. Posting a payroll run is impossible without a
 * salary-expense/salary-payable mapping -- there is no suspense-account
 * fallback (ADR 0032 "Deduction accounting").
 */
class PayrollAccountingNotConfiguredException extends PayrollException
{
    public function __construct()
    {
        parent::__construct(422, 'PAYROLL_ACCOUNTING_NOT_CONFIGURED', 'This School has not configured Payroll accounting accounts yet -- a run cannot be posted.');
    }
}
