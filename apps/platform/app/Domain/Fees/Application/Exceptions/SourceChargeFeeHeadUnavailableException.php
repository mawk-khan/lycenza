<?php

namespace App\Domain\Fees\Application\Exceptions;

/**
 * OPF.4 (ADR 0067 §17): a source event charge names a fee head that is not
 * an active fee head of the School, so FEE has no ledger destination for it.
 */
class SourceChargeFeeHeadUnavailableException extends FeesException
{
    public function __construct(string $feeHeadId)
    {
        parent::__construct(422, 'SOURCE_CHARGE_FEE_HEAD_UNAVAILABLE', "Fee head '{$feeHeadId}' is not an active fee head of this School.");
    }
}
