<?php

namespace Tests\Concerns;

use App\Domain\Finance\Application\JournalLineData;
use App\Domain\Finance\Application\LedgerService;
use App\Domain\Finance\Application\PostJournalEntryData;
use App\Domain\Finance\Domain\JournalSide;
use App\Domain\Finance\Infrastructure\JournalEntry;
use App\Domain\Finance\Infrastructure\LedgerAccount;
use App\Models\School;
use App\Support\Money\Money;
use App\Support\Tenancy\TenantContext;

/**
 * Phase 0G.1/0G.2 test fixtures.
 *
 * postBalancedJournalEntry() delegates to the real
 * App\Domain\Finance\Application\LedgerService::post() (0G.2) rather
 * than constructing rows by hand -- section 46 of the 0G.2 brief: once
 * a production Application service exists, test fixtures must not
 * maintain a second, competing implementation of posting semantics.
 * Tests that specifically need to exercise raw/invalid database shapes
 * the service would reject before ever reaching PostgreSQL (e.g.
 * JournalEntryBalanceEnforcementTest's unbalanced-entry cases) continue
 * to construct rows directly via Eloquent, deliberately bypassing this
 * helper -- see those tests' own docblocks.
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
     * $currency (LedgerService's own validation, backed by the
     * composite foreign keys, rejects anything else).
     *
     * Returns the real `JournalEntry` model (fetched by the id in
     * `LedgerService::post()`'s `JournalEntryResult`), not the result
     * DTO itself -- a test fixture is allowed to reach into persistence
     * directly for convenience (existing callers need `->lines()`,
     * `->school_id`, etc.), unlike `LedgerService`'s own public
     * contract, which deliberately never exposes the raw model (see
     * `App\Domain\Finance\Application\JournalEntryResult`'s docblock).
     */
    protected function postBalancedJournalEntry(
        School $school,
        LedgerAccount $debitAccount,
        LedgerAccount $creditAccount,
        string $amount = '100.00',
        string $currency = 'INR',
    ): JournalEntry {
        $money = Money::of($amount, $currency);

        $result = app(LedgerService::class)->post($school, new PostJournalEntryData(
            currency: $currency,
            description: 'Test posting',
            lines: [
                new JournalLineData($debitAccount->id, JournalSide::Debit, $money),
                new JournalLineData($creditAccount->id, JournalSide::Credit, $money),
            ],
        ));

        return app(TenantContext::class)->withSchool(
            $school,
            fn () => JournalEntry::query()->findOrFail($result->journalEntryId),
        );
    }
}
