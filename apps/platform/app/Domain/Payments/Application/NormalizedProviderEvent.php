<?php

namespace App\Domain\Payments\Application;

use App\Support\Money\Money;
use Illuminate\Support\Carbon;

/**
 * Phase 0G.5: the ALREADY-VERIFIED, normalized shape a trusted caller
 * hands to `PaymentProviderEventService::recordSettlement()` -- rule 18
 * of the 0G.5 brief ("Callback authenticity boundary"): signature/
 * authenticity verification is DEFERRED TO a future provider/HTTP
 * adapter (0G.6+, not implemented here); this Application-layer service
 * trusts its caller completely, exactly like `LedgerService::post()`/
 * `ChargeService::assess()` trust theirs. No raw provider payload is
 * ever accepted here -- a future adapter is responsible for producing
 * this exact minimized shape from whatever a real provider actually
 * sends (rule 15: no blind JSON dump).
 */
final class NormalizedProviderEvent
{
    public function __construct(
        public readonly string $provider,
        public readonly string $providerEventId,
        public readonly string $providerPaymentReference,
        public readonly string $eventType,
        public readonly Money $amount,
        public readonly Carbon $occurredAt,
    ) {}
}
