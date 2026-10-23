<?php

namespace App\Domain\Finance\Application;

use Carbon\CarbonImmutable;

/** ORG.9B (ADR 0076 §68): one posted line of a ledger account statement, with the running net-debit balance after it. */
final readonly class LedgerAccountStatementLine
{
    public function __construct(
        public string $journalEntryId,
        public CarbonImmutable $postedAt,
        public string $description,
        public bool $isReversal,
        public string $debit,
        public string $credit,
        public string $balance,
    ) {}
}
