<?php

namespace App\Domain\Finance\Application;

use App\Domain\Finance\Domain\JournalSide;
use App\Support\Money\Money;

/**
 * Phase 0G.2: one line of a `LedgerService::post()` command. Deliberately
 * does NOT carry `school_id` -- the posting School is always the
 * trusted `School` parameter `LedgerService::post()` itself receives,
 * never something a line can individually choose (rule 19-equivalent
 * for Finance: `school_id` is never accepted from caller-controlled
 * input per line). Deliberately exposes an explicit `side`
 * (`JournalSide`) rather than raw `debit_amount`/`credit_amount`
 * fields, so the Application boundary cannot express the ambiguous
 * "both set" or "sign implies side" shapes the database schema already
 * rejects -- this class makes that shape unrepresentable one layer
 * earlier.
 */
final class JournalLineData
{
    public function __construct(
        public readonly string $ledgerAccountId,
        public readonly JournalSide $side,
        public readonly Money $amount,
    ) {}
}
