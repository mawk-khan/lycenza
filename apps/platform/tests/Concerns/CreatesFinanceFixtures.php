<?php

namespace Tests\Concerns;

use App\Domain\Finance\Infrastructure\JournalEntry;
use App\Domain\Finance\Infrastructure\LedgerAccount;
use App\Models\School;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Phase 0G.1 test fixtures. Deliberately goes through the SAME
 * TenantContext::withSchool()/Eloquent path production code will
 * eventually use (0G.2's LedgerService), not a raw-SQL shortcut --
 * matching CreatesTenancyFixtures' existing convention.
 *
 * postBalancedJournalEntry() is the ONE safe way these tests construct
 * a real, valid posted entry -- see JournalEntryFactory/
 * JournalLineFactory's own docblocks for why a bare factory ->create()
 * call cannot do this safely by itself.
 */
trait CreatesFinanceFixtures
{
    protected function createLedgerAccount(School $school, array $attributes = []): LedgerAccount
    {
        return app(TenantContext::class)->withSchool(
            $school,
            fn () => LedgerAccount::factory()->for($school, 'school')->create($attributes),
        );
    }

    /**
     * Creates one balanced, two-line journal entry: a debit of $amount
     * against $debitAccount, a credit of $amount against
     * $creditAccount. Both accounts must belong to $school and share
     * $currency (the composite foreign keys reject anything else).
     */
    protected function postBalancedJournalEntry(
        School $school,
        LedgerAccount $debitAccount,
        LedgerAccount $creditAccount,
        string $amount = '100.00',
        string $currency = 'INR',
        array $entryAttributes = [],
    ): JournalEntry {
        return app(TenantContext::class)->withSchool($school, function () use (
            $school, $debitAccount, $creditAccount, $amount, $currency, $entryAttributes,
        ) {
            return DB::transaction(function () use (
                $school, $debitAccount, $creditAccount, $amount, $currency, $entryAttributes,
            ) {
                $entry = JournalEntry::query()->create(array_merge([
                    'school_id' => $school->id,
                    'currency' => $currency,
                    'description' => 'Test posting',
                ], $entryAttributes));

                $entry->lines()->create([
                    'school_id' => $school->id,
                    'ledger_account_id' => $debitAccount->id,
                    'currency' => $currency,
                    'debit_amount' => $amount,
                    'credit_amount' => null,
                ]);

                $entry->lines()->create([
                    'school_id' => $school->id,
                    'ledger_account_id' => $creditAccount->id,
                    'currency' => $currency,
                    'debit_amount' => null,
                    'credit_amount' => $amount,
                ]);

                return $entry;
            });
        });
    }
}
