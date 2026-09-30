<?php

namespace App\Domain\Payments\Application;

use Illuminate\Support\Carbon;

/**
 * FEE.4 (ADR 0062 §17.1, §17.5): what a receipt shows -- a PAYMENT
 * ACKNOWLEDGEMENT titled "Payment receipt", never a tax invoice. Every
 * money fact comes from the Payment and its allocations; the receipt row
 * contributes only its number, series and issue facts. There is no tax,
 * GSTIN, HSN/SAC or taxable-value field (J: DEVELOPMENT AUTHORISED — PROD
 * LEGAL SIGN-OFF REQUIRED).
 *
 * @param  list<array{chargeId: string, amount: string, description: string|null, feeHeadName: string|null, billingPeriodKey: string|null, billingPeriodLabel: string|null, academicYearId: string|null}>  $lines
 * @param  list<string>  $studentIds
 */
final class ReceiptDocument
{
    public const TITLE = 'Payment receipt';

    public function __construct(
        public readonly string $receiptId,
        public readonly string $receiptNumber,
        public readonly string $seriesKey,
        public readonly int $sequenceValue,
        public readonly Carbon $issuedAt,
        public readonly string $paymentId,
        public readonly string $source,
        public readonly ?string $method,
        public readonly ?string $manualReference,
        public readonly string $amount,
        public readonly string $currency,
        public readonly Carbon $settledAt,
        public readonly array $studentIds,
        public readonly array $lines,
    ) {}
}
