<?php

namespace App\Domain\Fees\Application\Exceptions;

/**
 * Raised uniformly whether a caller-supplied charge id genuinely does
 * not exist OR exists only in a different School --
 * `ChargeService`/`ChargeReadService` always resolve the id through a
 * School-scoped query run under the trusted School's own
 * TenantContext/RLS, so a cross-School id is simply absent from the
 * result, identical to a nonexistent one (the same "no oracle"
 * principle `App\Domain\Finance\Application\Exceptions\JournalEntryNotFoundException`
 * already establishes for Finance).
 */
class ChargeNotFoundException extends FeesException
{
    public function __construct(public readonly string $chargeId)
    {
        parent::__construct(404, 'CHARGE_NOT_FOUND', "No charge with id '{$chargeId}' was found in this School.");
    }
}
