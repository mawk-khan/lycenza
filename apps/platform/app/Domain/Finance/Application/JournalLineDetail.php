<?php

namespace App\Domain\Finance\Application;

use App\Domain\Finance\Domain\JournalSide;
use App\Support\Money\Money;

/**
 * Phase 0G.3 -- one line within `JournalEntryDetail::$lines`. Exposes
 * an explicit `side` (`JournalSide`) plus a single `amount` (`Money`),
 * never the raw `debit_amount`/`credit_amount` pair `journal_lines`
 * persists -- the same "side + amount, not two nullable columns"
 * boundary `JournalLineData` already establishes on the write side
 * (`App\Domain\Finance\Application\JournalLineData`'s docblock),
 * applied here to the read side. `amount` is the real `Money` value
 * object (exact NUMERIC-backed decimal, never a PHP float) built from
 * `JournalLine::debit()`/`credit()` -- never `(float)`, never
 * `number_format()`.
 */
final class JournalLineDetail
{
    public function __construct(
        public readonly string $journalLineId,
        public readonly string $ledgerAccountId,
        public readonly string $accountCode,
        public readonly string $accountName,
        public readonly JournalSide $side,
        public readonly Money $amount,
        public readonly string $currency,
    ) {}
}
