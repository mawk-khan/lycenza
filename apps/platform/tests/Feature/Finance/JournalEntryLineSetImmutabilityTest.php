<?php

namespace Tests\Feature\Finance;

use App\Models\School;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesFinanceFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 0G.1 closure review section 1/3/4: proves a posted
 * JournalEntry's LINE SET, not merely its existing rows, is immutable
 * after the posting transaction commits -- append-only UPDATE/DELETE
 * revocation alone does not stop a LATER transaction from INSERTing an
 * additional (even balanced) journal_lines row against an
 * already-committed entry. See the
 * `2026_08_31_090300_add_journal_entry_posting_invariants.php`
 * migration's docblock for the `posting_txid`/BEFORE INSERT trigger
 * mechanism.
 *
 * Deliberately does NOT use DatabaseTransactions for the fixtures each
 * test creates ($connectionsToTransact = []) -- the whole point is
 * that the original entry must be GENUINELY COMMITTED (a real
 * transaction that actually finished), then a SEPARATE, later
 * transaction attempts the extension. Under the ordinary
 * DatabaseTransactions-wrapped test setup, nothing is ever really
 * committed, so this invariant could never be proven this way -- same
 * reasoning as JournalReversalConcurrencyTest.
 */
class JournalEntryLineSetImmutabilityTest extends TestCase
{
    use CreatesFinanceFixtures, CreatesTenancyFixtures;

    /** @var array<int, string> */
    protected $connectionsToTransact = [];

    private ?School $school = null;

    protected function tearDown(): void
    {
        if ($this->school !== null) {
            $this->school->delete(); // cascades ledger_accounts/journal_entries/journal_lines
        }

        parent::tearDown();
    }

    #[Test]
    public function a_single_additional_debit_line_is_rejected_after_commit(): void
    {
        $this->school = $this->createSchool();
        $cash = $this->createLedgerAccount($this->school, ['type' => 'asset']);
        $income = $this->createLedgerAccount($this->school, ['type' => 'income']);
        $entry = $this->postBalancedJournalEntry($this->school, $cash, $income, '100.00');

        $this->expectException(QueryException::class);

        app(TenantContext::class)->withSchool($this->school, fn () => $entry->lines()->create([
            'school_id' => $this->school->id,
            'ledger_account_id' => $cash->id,
            'currency' => 'INR',
            'debit_amount' => '1.00',
        ]));
    }

    #[Test]
    public function a_single_additional_credit_line_is_rejected_after_commit(): void
    {
        $this->school = $this->createSchool();
        $cash = $this->createLedgerAccount($this->school, ['type' => 'asset']);
        $income = $this->createLedgerAccount($this->school, ['type' => 'income']);
        $entry = $this->postBalancedJournalEntry($this->school, $cash, $income, '100.00');

        $this->expectException(QueryException::class);

        app(TenantContext::class)->withSchool($this->school, fn () => $entry->lines()->create([
            'school_id' => $this->school->id,
            'ledger_account_id' => $income->id,
            'currency' => 'INR',
            'credit_amount' => '1.00',
        ]));
    }

    #[Test]
    public function a_balanced_debit_and_credit_pair_is_rejected_after_commit(): void
    {
        $this->school = $this->createSchool();
        $cash = $this->createLedgerAccount($this->school, ['type' => 'asset']);
        $income = $this->createLedgerAccount($this->school, ['type' => 'income']);
        $entry = $this->postBalancedJournalEntry($this->school, $cash, $income, '100.00');

        // A "balanced pair" cannot secretly slip past the check --
        // the first of the two additional lines already trips the
        // immediate BEFORE INSERT trigger, so the second is never
        // even attempted.
        $this->expectException(QueryException::class);

        app(TenantContext::class)->withSchool($this->school, function () use ($entry, $cash, $income) {
            $entry->lines()->create([
                'school_id' => $this->school->id,
                'ledger_account_id' => $cash->id,
                'currency' => 'INR',
                'debit_amount' => '25.00',
            ]);
            $entry->lines()->create([
                'school_id' => $this->school->id,
                'ledger_account_id' => $income->id,
                'currency' => 'INR',
                'credit_amount' => '25.00',
            ]);
        });
    }

    #[Test]
    public function a_balanced_multi_line_extension_is_rejected_after_commit(): void
    {
        $this->school = $this->createSchool();
        $cash = $this->createLedgerAccount($this->school, ['type' => 'asset']);
        $income = $this->createLedgerAccount($this->school, ['type' => 'income']);
        $refunds = $this->createLedgerAccount($this->school, ['type' => 'liability']);
        $entry = $this->postBalancedJournalEntry($this->school, $cash, $income, '100.00');

        $this->expectException(QueryException::class);

        app(TenantContext::class)->withSchool($this->school, function () use ($entry, $cash, $income, $refunds) {
            $entry->lines()->create([
                'school_id' => $this->school->id,
                'ledger_account_id' => $cash->id,
                'currency' => 'INR',
                'debit_amount' => '30.00',
            ]);
            $entry->lines()->create([
                'school_id' => $this->school->id,
                'ledger_account_id' => $income->id,
                'currency' => 'INR',
                'credit_amount' => '10.00',
            ]);
            $entry->lines()->create([
                'school_id' => $this->school->id,
                'ledger_account_id' => $refunds->id,
                'currency' => 'INR',
                'credit_amount' => '20.00',
            ]);
        });
    }

    #[Test]
    public function initial_atomic_posting_within_the_same_transaction_still_succeeds(): void
    {
        $this->school = $this->createSchool();
        $cash = $this->createLedgerAccount($this->school, ['type' => 'asset']);
        $income = $this->createLedgerAccount($this->school, ['type' => 'income']);

        $entry = $this->postBalancedJournalEntry($this->school, $cash, $income, '100.00');

        $count = app(TenantContext::class)->withSchool($this->school, fn () => $entry->lines()->count());
        $this->assertSame(2, $count, 'The original, same-transaction posting must still succeed and have exactly its two lines.');
    }
}
