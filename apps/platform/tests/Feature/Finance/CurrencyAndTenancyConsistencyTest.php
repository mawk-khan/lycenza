<?php

namespace Tests\Feature\Finance;

use App\Domain\Finance\Infrastructure\JournalEntry;
use App\Domain\Finance\Infrastructure\JournalLine;
use App\Models\School;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesFinanceFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 0G.1 (docs/modules/FINANCE.md "Account / entry currency
 * consistency", "Composite tenant FKs"): proves the two composite
 * foreign keys on journal_lines --
 * (journal_entry_id, school_id, currency) -> journal_entries and
 * (ledger_account_id, school_id, currency) -> ledger_accounts --
 * structurally reject cross-School references AND currency mismatches
 * against EITHER parent, not merely application-layer validation. All
 * constraints here are IMMEDIATE (not the deferred balance trigger),
 * so no `SET CONSTRAINTS ALL IMMEDIATE` is needed.
 */
class CurrencyAndTenancyConsistencyTest extends TestCase
{
    use CreatesFinanceFixtures, CreatesTenancyFixtures;

    private function createBareEntry(School $school, string $currency = 'INR'): JournalEntry
    {
        return app(TenantContext::class)->withSchool(
            $school,
            fn () => JournalEntry::query()->create([
                'school_id' => $school->id,
                'currency' => $currency,
                'description' => 'Consistency test entry',
            ]),
        );
    }

    #[Test]
    public function a_line_cannot_reference_a_ledger_account_from_a_different_school(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $entry = $this->createBareEntry($schoolA);
        $accountB = $this->createLedgerAccount($schoolB);

        $this->expectException(QueryException::class);

        app(TenantContext::class)->withSchool($schoolA, fn () => $entry->lines()->create([
            'school_id' => $schoolA->id,
            'ledger_account_id' => $accountB->id,
            'currency' => 'INR',
            'debit_amount' => '10.00',
        ]));
    }

    #[Test]
    public function a_line_cannot_reference_a_journal_entry_from_a_different_school(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $entryB = $this->createBareEntry($schoolB);
        $accountA = $this->createLedgerAccount($schoolA);

        $this->expectException(QueryException::class);

        app(TenantContext::class)->withSchool($schoolA, fn () => JournalLine::query()->create([
            'school_id' => $schoolA->id,
            'journal_entry_id' => $entryB->id,
            'ledger_account_id' => $accountA->id,
            'currency' => 'INR',
            'debit_amount' => '10.00',
        ]));
    }

    /**
     * Closure review section 7-9: FINANCE.md's own text ("today: INR
     * only") is Phase 0G is INR-only globally -- proves
     * `journal_entries_currency_inr_only_check` directly, independent
     * of the composite-FK-driven mismatch tests below (which only
     * prove one entry can't mix currencies across its own lines, not
     * that a non-INR currency is rejected outright).
     */
    #[Test]
    public function a_non_inr_journal_entry_currency_is_rejected(): void
    {
        $school = $this->createSchool();

        $this->expectException(QueryException::class);

        $this->createBareEntry($school, 'USD');
    }

    #[Test]
    public function a_lines_currency_must_match_its_journal_entrys_currency(): void
    {
        $school = $this->createSchool();
        $entry = $this->createBareEntry($school, 'INR');
        $account = $this->createLedgerAccount($school, ['currency' => 'INR']);

        $this->expectException(QueryException::class);

        app(TenantContext::class)->withSchool($school, fn () => $entry->lines()->create([
            'school_id' => $school->id,
            'ledger_account_id' => $account->id,
            'currency' => 'USD',
            'debit_amount' => '10.00',
        ]));
    }

    #[Test]
    public function a_lines_currency_must_match_its_ledger_accounts_currency(): void
    {
        // Since ledger_accounts_currency_inr_only_check (closure review
        // section 7-9) means a USD ledger_account can never exist at
        // all, this test cannot construct "two different but both
        // valid currencies" the way its journal_entry counterpart
        // above does. It instead proves the account-side composite FK
        // (journal_lines.(ledger_account_id, school_id, currency) ->
        // ledger_accounts.(id, school_id, currency)) still rejects a
        // non-INR line even against a real INR account -- no (id,
        // school_id, 'USD') row exists on ledger_accounts for the FK
        // to match. Under full INR-only scope the entry-side composite
        // FK would independently reject the same insert too (the
        // entry is also forced to INR) -- both FKs remain structurally
        // in place for when a future multi-currency checkpoint loosens
        // the CHECK constraints (FINANCE.md's own stated evolution
        // path), even though they are redundant with each other while
        // Phase 0G stays INR-only.
        $school = $this->createSchool();
        $entry = $this->createBareEntry($school, 'INR');
        $account = $this->createLedgerAccount($school, ['currency' => 'INR']);

        $this->expectException(QueryException::class);

        app(TenantContext::class)->withSchool($school, fn () => $entry->lines()->create([
            'school_id' => $school->id,
            'ledger_account_id' => $account->id,
            'currency' => 'USD',
            'debit_amount' => '10.00',
        ]));
    }

    #[Test]
    public function a_line_with_both_debit_and_credit_set_is_rejected(): void
    {
        $school = $this->createSchool();
        $entry = $this->createBareEntry($school);
        $account = $this->createLedgerAccount($school);

        $this->expectException(QueryException::class);

        app(TenantContext::class)->withSchool($school, fn () => $entry->lines()->create([
            'school_id' => $school->id,
            'ledger_account_id' => $account->id,
            'currency' => 'INR',
            'debit_amount' => '10.00',
            'credit_amount' => '10.00',
        ]));
    }

    #[Test]
    public function a_line_with_neither_debit_nor_credit_set_is_rejected(): void
    {
        $school = $this->createSchool();
        $entry = $this->createBareEntry($school);
        $account = $this->createLedgerAccount($school);

        $this->expectException(QueryException::class);

        app(TenantContext::class)->withSchool($school, fn () => $entry->lines()->create([
            'school_id' => $school->id,
            'ledger_account_id' => $account->id,
            'currency' => 'INR',
        ]));
    }

    #[Test]
    public function a_zero_amount_line_is_rejected(): void
    {
        $school = $this->createSchool();
        $entry = $this->createBareEntry($school);
        $account = $this->createLedgerAccount($school);

        $this->expectException(QueryException::class);

        app(TenantContext::class)->withSchool($school, fn () => $entry->lines()->create([
            'school_id' => $school->id,
            'ledger_account_id' => $account->id,
            'currency' => 'INR',
            'debit_amount' => '0.00',
        ]));
    }

    #[Test]
    public function a_negative_amount_line_is_rejected(): void
    {
        $school = $this->createSchool();
        $entry = $this->createBareEntry($school);
        $account = $this->createLedgerAccount($school);

        $this->expectException(QueryException::class);

        app(TenantContext::class)->withSchool($school, fn () => $entry->lines()->create([
            'school_id' => $school->id,
            'ledger_account_id' => $account->id,
            'currency' => 'INR',
            'debit_amount' => '-10.00',
        ]));
    }

    #[Test]
    public function a_valid_debit_line_is_accepted(): void
    {
        $school = $this->createSchool();
        $entry = $this->createBareEntry($school);
        $account = $this->createLedgerAccount($school);

        $line = app(TenantContext::class)->withSchool($school, fn () => $entry->lines()->create([
            'school_id' => $school->id,
            'ledger_account_id' => $account->id,
            'currency' => 'INR',
            'debit_amount' => '10.00',
        ]));

        $this->assertTrue($line->isDebit());
        $this->assertFalse($line->isCredit());
        $this->assertSame('10.00', $line->debit()->amount());
        $this->assertSame('INR', $line->debit()->currency());
        $this->assertNull($line->credit());
    }
}
