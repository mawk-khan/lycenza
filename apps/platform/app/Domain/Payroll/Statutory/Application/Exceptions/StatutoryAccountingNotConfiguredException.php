<?php

namespace App\Domain\Payroll\Statutory\Application\Exceptions;

/**
 * Checkpoint 9.6F (ADR 0035 correction addendum §1.11) -- raised by
 * `StatutoryAccountingConfigurationService::resolveValidated()` when
 * a School has never configured
 * `payroll_statutory_accounting_configurations`. There is no
 * suspense-account fallback -- the same "fails closed before any
 * Finance side effect" rule as `PayrollAccountingNotConfiguredException`.
 */
class StatutoryAccountingNotConfiguredException extends StatutoryPayrollException
{
    public function __construct()
    {
        parent::__construct(422, 'STATUTORY_ACCOUNTING_NOT_CONFIGURED', 'This School has not configured statutory Payroll accounting accounts yet -- a statutory posting cannot proceed.');
    }
}
