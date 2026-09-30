<?php

namespace App\Domain\Fees\Application\Exceptions;

class FeeLateFeeRuleNotFoundException extends FeesException
{
    public function __construct(string $ruleId)
    {
        parent::__construct(404, 'LATE_FEE_RULE_NOT_FOUND', "Late-fee rule '{$ruleId}' was not found.");
    }
}
