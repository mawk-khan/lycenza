<?php

namespace App\Domain\Fees\Application;

use App\Support\Money\Money;

/**
 * Phase 0G.5: the typed, safe result of
 * `ChargeService::lockChargeForAllocation()` -- never the raw `Charge`
 * Eloquent model (matching `ChargeResult`/`ChargeSummary`/`ChargeDetail`'s
 * established "no raw model leak" discipline). The ONLY shape
 * `App\Domain\Payments\Application\PaymentProviderEventService` (a
 * DIFFERENT module) is ever allowed to observe about a Charge.
 */
final class ChargeAllocationSnapshot
{
    public function __construct(
        public readonly string $chargeId,
        public readonly Money $amount,
        public readonly string $receivableLedgerAccountId,
        public readonly bool $isCancelled,
    ) {}
}
