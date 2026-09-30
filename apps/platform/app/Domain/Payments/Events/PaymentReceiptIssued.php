<?php

namespace App\Domain\Payments\Events;

use App\Support\Events\OutboxedEventDefaults;
use App\Support\Events\ShouldBeOutboxed;

/**
 * FEE.4 (ADR 0062 §20): one receipt was issued for one Payment, raised
 * exactly once, in the transaction that inserted the receipt -- never on a
 * replay. `issuance` is `settlement` (issued with the Payment) or
 * `backfill` (I2: issued later, by `finance:receipts-backfill`); both are
 * the same business fact, so there is one event type. Payload is ids, the
 * series and the issuance mode only -- no receipt number, amount, Student
 * or rendered content. Internal only: NOT registered in
 * `App\Support\Webhooks\WebhookEventRegistry` (rules 45/77).
 */
class PaymentReceiptIssued implements ShouldBeOutboxed
{
    use OutboxedEventDefaults;

    public function __construct(
        public readonly string $schoolId,
        public readonly string $paymentReceiptId,
        public readonly string $paymentId,
        public readonly string $seriesKey,
        public readonly string $issuance,
    ) {}

    public function eventType(): string
    {
        return 'payment_receipt.issued.v1';
    }

    public function eventVersion(): int
    {
        return 1;
    }

    public function schoolId(): ?string
    {
        return $this->schoolId;
    }

    public function payload(): array
    {
        return [
            'paymentReceiptId' => $this->paymentReceiptId,
            'paymentId' => $this->paymentId,
            'seriesKey' => $this->seriesKey,
            'issuance' => $this->issuance,
        ];
    }
}
