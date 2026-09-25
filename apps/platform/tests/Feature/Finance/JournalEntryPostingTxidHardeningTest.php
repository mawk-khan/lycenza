<?php

namespace Tests\Feature\Finance;

use App\Domain\Finance\Infrastructure\JournalLine;
use App\Models\School;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantRls;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesFinanceFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Posting-txid hardening pass (post-0G.1-closure-review): proves
 * `journal_entries.posting_txid` cannot be caller-chosen or spoofed via
 * raw SQL, even though the column itself is an ordinary `xid8` a raw
 * INSERT could otherwise supply a value for. A PostgreSQL `DEFAULT`
 * only fills in a value when the caller OMITS the column entirely --
 * it does not stop an explicit value from being provided. The
 * `finance_set_journal_entry_posting_txid()` trigger (see the
 * posting-invariants migration) is what actually makes this column
 * trustworthy: it unconditionally overwrites `NEW.posting_txid` on
 * every single insert, with no `IF NEW.posting_txid IS NULL THEN`
 * guard, so a caller-supplied value is always discarded regardless of
 * what it is.
 *
 * Deliberately does NOT use DatabaseTransactions ($connectionsToTransact
 * = []) -- the historical-bypass test requires a GENUINELY committed
 * entry, then a SEPARATE later transaction attempting extension,
 * exactly like JournalEntryLineSetImmutabilityTest's identical
 * reasoning. The raw-spoof test doesn't strictly need this, but is
 * kept in the same class/connection-mode for simplicity.
 */
class JournalEntryPostingTxidHardeningTest extends TestCase
{
    use CreatesFinanceFixtures, CreatesTenancyFixtures;

    /** @var array<int, string> */
    protected $connectionsToTransact = [];

    private ?School $school = null;

    protected function tearDown(): void
    {
        if ($this->school !== null) {
            $this->deleteSchoolAsAdmin($this->school);
        }

        parent::tearDown();
    }

    private function setSchool(string $schoolId): void
    {
        DB::connection('pgsql')->select('select set_config(?, ?, false)', [TenantRls::SESSION_VAR, $schoolId]);
    }

    /**
     * Wraps the spoofed INSERT together with two balanced lines in one
     * explicit transaction -- a bare header-only INSERT would be its
     * own single-statement autocommit transaction under this test
     * class's disabled DatabaseTransactions, and the DEFERRED balance
     * trigger would then fire (and correctly reject "zero lines") at
     * THAT statement's own commit, before this test ever gets to
     * assert anything about posting_txid. This test is about
     * posting_txid spoofing specifically, not balance, so it satisfies
     * the balance invariant incidentally rather than testing it.
     */
    #[Test]
    public function a_raw_sql_spoofed_posting_txid_is_overwritten_by_the_database(): void
    {
        $this->school = $this->createSchool();
        $cash = $this->createLedgerAccount($this->school, ['type' => 'asset']);
        $income = $this->createLedgerAccount($this->school, ['type' => 'income']);

        $this->setSchool($this->school->id);

        $spoofed = '1';
        $actualTxid = null;

        $stored = DB::connection('pgsql')->transaction(function () use ($cash, $income, $spoofed, &$actualTxid) {
            // Captured INSIDE the same transaction as the insert below --
            // pg_current_xact_id() is per-transaction, not per-statement,
            // but this test disables DatabaseTransactions, so a capture
            // OUTSIDE this closure would be a different, earlier
            // transaction entirely (proven by this test's own first,
            // wrong attempt: two different, sequential single-statement
            // transactions really do get two different real ids).
            $actualTxid = DB::connection('pgsql')->selectOne('select pg_current_xact_id()::text as txid')->txid;

            $rows = DB::connection('pgsql')->select(
                'insert into journal_entries '.
                '(id, school_id, currency, description, posted_at, created_at, posting_txid) '.
                "values (gen_random_uuid(), ?, 'INR', 'Spoof attempt', now(), now(), ?::xid8) ".
                'returning id, posting_txid::text as posting_txid',
                [$this->school->id, $spoofed],
            );
            $entryId = $rows[0]->id;

            DB::connection('pgsql')->insert(
                'insert into journal_lines (id, school_id, journal_entry_id, ledger_account_id, currency, debit_amount, created_at) '.
                "values (gen_random_uuid(), ?, ?, ?, 'INR', 10.00, now())",
                [$this->school->id, $entryId, $cash->id],
            );
            DB::connection('pgsql')->insert(
                'insert into journal_lines (id, school_id, journal_entry_id, ledger_account_id, currency, credit_amount, created_at) '.
                "values (gen_random_uuid(), ?, ?, ?, 'INR', 10.00, now())",
                [$this->school->id, $entryId, $income->id],
            );

            return $rows[0]->posting_txid;
        });

        $this->assertSame($actualTxid, $stored, 'The stored posting_txid must equal the real current transaction id.');
        $this->assertNotSame($spoofed, $stored, 'The caller-supplied spoofed posting_txid must never be what is stored.');
    }

    #[Test]
    public function a_spoofed_posting_txid_at_creation_cannot_be_used_to_reopen_the_entry_for_a_new_debit(): void
    {
        $this->school = $this->createSchool();
        $cash = $this->createLedgerAccount($this->school, ['type' => 'asset']);
        $income = $this->createLedgerAccount($this->school, ['type' => 'income']);

        $entryId = $this->createBalancedEntryWithSpoofedPostingTxid($cash->id, $income->id);

        $this->expectException(QueryException::class);

        app(TenantContext::class)->withSchool($this->school, fn () => JournalLine::query()->create([
            'school_id' => $this->school->id,
            'journal_entry_id' => $entryId,
            'ledger_account_id' => $cash->id,
            'currency' => 'INR',
            'debit_amount' => '1.00',
        ]));
    }

    #[Test]
    public function a_spoofed_posting_txid_at_creation_cannot_be_used_to_reopen_the_entry_for_a_balanced_pair(): void
    {
        $this->school = $this->createSchool();
        $cash = $this->createLedgerAccount($this->school, ['type' => 'asset']);
        $income = $this->createLedgerAccount($this->school, ['type' => 'income']);

        $entryId = $this->createBalancedEntryWithSpoofedPostingTxid($cash->id, $income->id);

        $this->expectException(QueryException::class);

        app(TenantContext::class)->withSchool($this->school, function () use ($entryId, $cash, $income) {
            JournalLine::query()->create([
                'school_id' => $this->school->id,
                'journal_entry_id' => $entryId,
                'ledger_account_id' => $cash->id,
                'currency' => 'INR',
                'debit_amount' => '5.00',
            ]);
            JournalLine::query()->create([
                'school_id' => $this->school->id,
                'journal_entry_id' => $entryId,
                'ledger_account_id' => $income->id,
                'currency' => 'INR',
                'credit_amount' => '5.00',
            ]);
        });
    }

    /**
     * Creates a real, genuinely committed, balanced two-line entry
     * whose header INSERT explicitly (and unsuccessfully) attempts to
     * plant `posting_txid = '1'::xid8`. Returns the entry's id.
     */
    private function createBalancedEntryWithSpoofedPostingTxid(string $debitAccountId, string $creditAccountId): string
    {
        $this->setSchool($this->school->id);

        return DB::connection('pgsql')->transaction(function () use ($debitAccountId, $creditAccountId) {
            $rows = DB::connection('pgsql')->select(
                'insert into journal_entries '.
                '(id, school_id, currency, description, posted_at, created_at, posting_txid) '.
                "values (gen_random_uuid(), ?, 'INR', 'Spoofed original posting', now(), now(), '1'::xid8) ".
                'returning id',
                [$this->school->id],
            );
            $entryId = $rows[0]->id;

            DB::connection('pgsql')->insert(
                'insert into journal_lines (id, school_id, journal_entry_id, ledger_account_id, currency, debit_amount, created_at) '.
                "values (gen_random_uuid(), ?, ?, ?, 'INR', 100.00, now())",
                [$this->school->id, $entryId, $debitAccountId],
            );
            DB::connection('pgsql')->insert(
                'insert into journal_lines (id, school_id, journal_entry_id, ledger_account_id, currency, credit_amount, created_at) '.
                "values (gen_random_uuid(), ?, ?, ?, 'INR', 100.00, now())",
                [$this->school->id, $entryId, $creditAccountId],
            );

            return $entryId;
        });
    }
}
