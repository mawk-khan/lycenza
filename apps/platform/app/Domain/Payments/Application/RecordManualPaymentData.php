<?php

namespace App\Domain\Payments\Application;

use App\Domain\Payments\Domain\ManualPaymentMethod;
use App\Support\Money\Money;

/**
 * Phase 0O.11A: the typed input of `ManualPaymentRecordingService::record()`
 * -- a payment the School already received outside Lycenza.
 *
 * `occurredOn` is the calendar date (`Y-m-d`) the School says the money
 * was received, in the School's own timezone; `recorded_at` is never an
 * input (server-set on commit). `idempotencyKey` is the opaque UUID the
 * server issued with the recording form.
 *
 * @param  list<ChargeAllocationInput>  $allocations
 */
final class RecordManualPaymentData
{
    public function __construct(
        public readonly ManualPaymentMethod $method,
        public readonly Money $amount,
        public readonly string $occurredOn,
        public readonly ?string $reference,
        public readonly string $settlementLedgerAccountId,
        public readonly array $allocations,
        public readonly string $idempotencyKey,
    ) {}
}
