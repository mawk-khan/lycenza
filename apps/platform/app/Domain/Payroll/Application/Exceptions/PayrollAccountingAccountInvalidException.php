<?php

namespace App\Domain\Payroll\Application\Exceptions;

/**
 * Phase 9.5 -- the configured salary-expense/salary-payable
 * `LedgerAccount` no longer satisfies posting's requirements: not
 * found in this School, inactive, or the wrong `type` (salary expense
 * must be `expense`, salary payable must be `liability`). Raised both
 * at configuration-save time (better UX) and again, authoritatively,
 * at posting time (`PayrollAccountingConfigurationService::resolveValidated()`)
 * -- an account's status/type can drift after the configuration was
 * saved, mirroring
 * `App\Domain\Canteen\Application\Exceptions\CanteenBillingAccountInvalidException`'s
 * identical two-call-site shape.
 */
class PayrollAccountingAccountInvalidException extends PayrollException
{
    public function __construct(string $reason)
    {
        parent::__construct(422, 'PAYROLL_ACCOUNTING_ACCOUNT_INVALID', "Payroll accounting configuration is invalid: {$reason}");
    }
}
