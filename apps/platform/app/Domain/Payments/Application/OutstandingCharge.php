<?php

namespace App\Domain\Payments\Application;

use Illuminate\Support\Carbon;

/**
 * Phase 0O.11A: one uncancelled Charge a manual payment may be allocated
 * to, with its recognized allocations so far -- a display projection for
 * the recording form. The authoritative remaining balance is re-derived
 * under the Charge row lock when the payment is recorded.
 *
 * FEE.3 (ADR 0062 §15): `adjusted` is the live fee-adjustment total and
 * `outstanding` = amount - allocated - adjusted.
 */
final class OutstandingCharge
{
    public function __construct(
        public readonly string $chargeId,
        public readonly string $description,
        public readonly string $amount,
        public readonly string $allocated,
        public readonly string $outstanding,
        public readonly string $currency,
        public readonly ?Carbon $dueDate,
        public readonly string $adjusted = '0.00',
    ) {}
}
