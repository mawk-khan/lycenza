<?php

namespace App\Domain\Payments\Application;

use App\Support\Money\Money;

/**
 * Phase 0G.5: one caller-specified "apply this much of the settled
 * payment to this Charge" instruction -- there is no automatic payment-
 * to-charge matching in 0G.5 (nothing in FINANCE.md defines one); the
 * trusted caller of `PaymentProviderEventService::recordSettlement()`
 * always supplies the full allocation breakdown explicitly, the same
 * "no magic lookup" discipline `AssessChargeData`'s explicit ledger
 * account ids already established.
 */
final class ChargeAllocationInput
{
    public function __construct(
        public readonly string $chargeId,
        public readonly Money $amount,
    ) {}
}
