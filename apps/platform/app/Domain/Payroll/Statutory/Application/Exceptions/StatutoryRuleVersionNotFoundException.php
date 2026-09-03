<?php

namespace App\Domain\Payroll\Statutory\Application\Exceptions;

class StatutoryRuleVersionNotFoundException extends StatutoryPayrollException
{
    public function __construct(string $ruleName, string $asOf)
    {
        parent::__construct(422, 'STATUTORY_RULE_VERSION_NOT_FOUND', "No active {$ruleName} rule version is effective as of {$asOf}.");
    }
}
