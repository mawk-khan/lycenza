<?php

namespace App\Domain\Finance\Application;

/**
 * ORG.9B (ADR 0076 §68): a ledger account's statement for a School-local
 * date range. `opening`, `closing` and each line's `balance` are net debit
 * (debit - credit); `totalDebit`/`totalCredit` cover the WHOLE range, never
 * one page.
 */
final readonly class LedgerAccountStatement
{
    /** @param  list<LedgerAccountStatementLine>  $lines */
    public function __construct(
        public LedgerAccountSummary $account,
        public string $from,
        public string $to,
        public string $opening,
        public string $totalDebit,
        public string $totalCredit,
        public string $closing,
        public array $lines,
        public int $total,
        public int $page,
        public int $perPage,
    ) {}
}
