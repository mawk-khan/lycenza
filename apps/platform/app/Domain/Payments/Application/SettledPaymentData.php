<?php

namespace App\Domain\Payments\Application;

use App\Support\Money\Money;
use Illuminate\Support\Carbon;

/**
 * Phase 0O.11A: the typed input of `SettledPaymentRecorder::record()` --
 * one already-settled payment, whichever ingress produced it.
 *
 * @param  list<ChargeAllocationInput>  $allocations
 */
final class SettledPaymentData
{
    public function __construct(
        public readonly Money $amount,
        public readonly string $settlementLedgerAccountId,
        public readonly array $allocations,
        public readonly Carbon $settledAt,
        public readonly string $journalDescription,
        public readonly PaymentProvenance $provenance,
    ) {}
}
