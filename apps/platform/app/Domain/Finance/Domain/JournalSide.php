<?php

namespace App\Domain\Finance\Domain;

/**
 * Phase 0G.2: the Application-layer boundary representation of ADR
 * 0030's debit/credit posting shape -- callers of
 * App\Domain\Finance\Application\LedgerService state a side explicitly
 * (never inferred from a positive/negative amount sign, which
 * `journal_lines`' own schema already rejects via its
 * exactly-one-of-debit-or-credit CHECK constraint). `LedgerService`
 * maps this directly onto the persisted `debit_amount`/`credit_amount`
 * columns -- never both, never a signed single column, matching
 * `docs/modules/FINANCE.md` "Ledger structure (0G.1 design target)".
 */
enum JournalSide: string
{
    case Debit = 'debit';
    case Credit = 'credit';
}
