<?php

namespace App\Domain\Payments\Application\Collections;

use Carbon\CarbonImmutable;

/**
 * LC-4 (M4): one receipt on the collections list. `recordedAt` is in the
 * School's timezone; `receivedOn` is the date the money was received as
 * entered (it may be earlier than the day it was recorded). Amounts are
 * exact decimal strings.
 */
final readonly class DailyCollectionRow
{
    /** @param  list<string>  $studentIds  the Students its charges belong to */
    public function __construct(
        public string $paymentId,
        public ?string $receiptNumber,
        public string $amount,
        public string $currency,
        public CarbonImmutable $recordedAt,
        public string $receivedOn,
        public string $source,
        public ?string $provider,
        public ?string $method,
        public string $status,
        public string $refunded,
        public bool $reallocated,
        public array $studentIds,
        public ?string $recordedByUserId,
    ) {}
}
