<?php

namespace App\Domain\Payments\Application;

use App\Domain\Payments\Domain\ManualPaymentMethod;
use App\Domain\Payments\Infrastructure\PaymentCorrection;
use App\Support\Money\Money;

/**
 * UAT-C3: one requested payment correction. `kind` decides which fields
 * apply: a reallocation moves `amount` from `chargeId` to `toChargeId`; a
 * refund pays `amount` of what this payment has on `chargeId` back out of
 * `accountId` by `method` on `refundedOn`; a reversal takes everything the
 * payment still has back to its own settlement account.
 */
final readonly class PaymentCorrectionData
{
    public const string REALLOCATION = PaymentCorrection::REALLOCATION;

    public const string REVERSAL = PaymentCorrection::REVERSAL;

    public const string REFUND = PaymentCorrection::REFUND;

    public function __construct(
        public string $paymentId,
        public string $kind,
        public string $reason,
        public string $idempotencyKey,
        public ?string $chargeId = null,
        public ?string $toChargeId = null,
        public ?Money $amount = null,
        public ?string $accountId = null,
        public ?ManualPaymentMethod $method = null,
        public ?string $refundedOn = null,
        public ?string $reference = null,
    ) {}
}
