<?php

namespace App\Domain\Finance\Application;

use App\Domain\Finance\Infrastructure\LedgerAccount;
use Illuminate\Support\Carbon;

/**
 * Phase 0G.3 -- one row of `LedgerReadService::listAccounts()`'s Ledger
 * Account directory. A disclosure projection, not a convenience wrapper
 * around `LedgerAccount` (the 0G.2 `JournalEntryResult` result-boundary
 * lesson, applied to the read side): every property here is an
 * explicit scalar, and this list IS the entire contract -- no
 * `posting_txid`-shaped concept exists on `ledger_accounts` at all, but
 * the same discipline applies regardless (never `$account->toArray()`,
 * never a raw model returned from `LedgerReadService`).
 */
final class LedgerAccountSummary
{
    public function __construct(
        public readonly string $ledgerAccountId,
        public readonly string $code,
        public readonly string $name,
        public readonly string $type,
        public readonly string $currency,
        public readonly bool $isSystem,
        public readonly string $status,
        public readonly Carbon $createdAt,
        public readonly Carbon $updatedAt,
    ) {}

    public static function fromModel(LedgerAccount $account): self
    {
        return new self(
            ledgerAccountId: $account->id,
            code: $account->code,
            name: $account->name,
            type: $account->type,
            currency: $account->currency,
            isSystem: $account->is_system,
            status: $account->status,
            createdAt: $account->created_at,
            updatedAt: $account->updated_at,
        );
    }
}
