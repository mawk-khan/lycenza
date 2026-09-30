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
 *
 * FEE.3 (ADR 0062 §15): `adjustedTotal` is the sum of the charge's live
 * (uncancelled) fee adjustments, read under the same row lock, so a
 * payment's capacity pre-check uses the NET amount; the Payments-owned
 * trigger remains the authoritative guard.
 */
final class ChargeAllocationSnapshot
{
    public function __construct(
        public readonly string $chargeId,
        public readonly Money $amount,
        public readonly string $receivableLedgerAccountId,
        public readonly bool $isCancelled,
        public readonly Money $adjustedTotal,
    ) {}

    /** The charge amount net of live fee adjustments (before any payment allocation). */
    public function netAmount(): Money
    {
        return $this->amount->add($this->adjustedTotal->negated());
    }
}
