<?php

namespace App\Domain\Fees\Application\Exceptions;

class FeeLateFeeRuleNotEditableException extends FeesException
{
    public function __construct(string $ruleId)
    {
        parent::__construct(409, 'LATE_FEE_RULE_NOT_EDITABLE', "Late-fee rule '{$ruleId}' is active; deactivate it before editing.");
    }
}
