<?php

namespace Tests\Feature\Finance;

use App\Domain\Finance\Infrastructure\JournalEntry;
use App\Models\School;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesFinanceFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 0G.1 (ADR 0030 "Consequences", FINANCE.md "Balancing
 * enforcement"): proves the DEFERRABLE INITIALLY DEFERRED constraint
 * trigger added by
 * database/migrations/2026_08_31_090300_add_journal_entry_posting_invariants.php
 * against real PostgreSQL, running as the real `school_os_app`
 * runtime role (phpunit.xml's default `pgsql` connection).
 *
 * Every test here explicitly forces `SET CONSTRAINTS ALL IMMEDIATE`
 * after inserting -- this suite runs under Tests\TestCase's
 * DatabaseTransactions (ADR 0024), which wraps each test in one
 * outer transaction that is always ROLLED BACK, never COMMITted. A
 * DEFERRED constraint trigger only runs at an actual COMMIT or at an
 * explicit `SET CONSTRAINTS` immediate-check -- without forcing it
 * here, these tests would silently never exercise the trigger at all
 * and would pass even if the trigger were completely broken. Forcing
 * the check still happens entirely inside the outer (eventually
 * rolled back) test transaction, so no real data is left behind.
 */
class JournalEntryBalanceEnforcementTest extends TestCase
{
    use CreatesFinanceFixtures, CreatesTenancyFixtures;

    /**
     * Forces the deferred trigger to run NOW. Deliberately wrapped in
     * TenantContext::withSchool($school, ...) rather than run bare --
     * the trigger's own internal `SELECT ... FROM journal_lines` is
     * itself RLS-scoped (no SECURITY DEFINER, see the migration's
     * docblock), so it must run while `app.current_school_id` is still
     * set to this entry's School. postBalancedJournalEntry()'s own
     * withSchool() call already restored/cleared that GUC by the time
     * it returns -- calling `SET CONSTRAINTS ALL IMMEDIATE` bare
     * afterward would make the trigger's own SELECT see zero lines
     * (RLS-filtered) regardless of how many actually exist, which is
     * not what this suite is trying to prove. A real production
     * request/job never hits this: TenantContext stays set for the
     * whole request, so a real COMMIT (not a test's forced check)
     * happens while context is still active.
     */
    private function forceConstraintCheck(School $school): void
    {
        app(TenantContext::class)->withSchool(
            $school,
            fn () => DB::connection('pgsql')->statement('SET CONSTRAINTS ALL IMMEDIATE'),
        );
    }

    #[Test]
    public function a_balanced_two_line_entry_passes_the_deferred_check(): void
    {
        $school = $this->createSchool();
        $context = app(TenantContext::class);
        $cash = $this->createLedgerAccount($school, ['type' => 'asset']);
        $income = $this->createLedgerAccount($school, ['type' => 'income']);

        $entry = $this->postBalancedJournalEntry($school, $cash, $income, '100.00');

        $this->forceConstraintCheck($school);

        $this->assertSame(2, $context->withSchool($school, fn () => $entry->lines()->count()));
    }

    #[Test]
    public function a_balanced_multi_line_entry_passes_the_deferred_check(): void
    {
        $school = $this->createSchool();
        $context = app(TenantContext::class);
        $cash = $this->createLedgerAccount($school, ['type' => 'asset']);
        $income = $this->createLedgerAccount($school, ['type' => 'income']);
        $refunds = $this->createLedgerAccount($school, ['type' => 'liability']);

        $entry = $context->withSchool($school, function () use ($school, $cash, $income, $refunds) {
            return DB::transaction(function () use ($school, $cash, $income, $refunds) {
                $entry = JournalEntry::query()->create([
                    'school_id' => $school->id,
                    'currency' => 'INR',
                    'description' => 'Multi-line split',
                ]);

                $entry->lines()->create(['school_id' => $school->id, 'ledger_account_id' => $cash->id, 'currency' => 'INR', 'debit_amount' => '150.00']);
                $entry->lines()->create(['school_id' => $school->id, 'ledger_account_id' => $income->id, 'currency' => 'INR', 'credit_amount' => '100.00']);
                $entry->lines()->create(['school_id' => $school->id, 'ledger_account_id' => $refunds->id, 'currency' => 'INR', 'credit_amount' => '50.00']);

                return $entry;
            });
        });

        $this->forceConstraintCheck($school);

        $this->assertSame(3, $context->withSchool($school, fn () => $entry->lines()->count()));
    }

    #[Test]
    public function an_unbalanced_entry_is_rejected_at_commit(): void
    {
        $school = $this->createSchool();
        $context = app(TenantContext::class);
        $cash = $this->createLedgerAccount($school, ['type' => 'asset']);
        $income = $this->createLedgerAccount($school, ['type' => 'income']);

        $context->withSchool($school, function () use ($school, $cash, $income) {
            DB::transaction(function () use ($school, $cash, $income) {
                $entry = JournalEntry::query()->create([
                    'school_id' => $school->id,
                    'currency' => 'INR',
                    'description' => 'Unbalanced',
                ]);

                $entry->lines()->create(['school_id' => $school->id, 'ledger_account_id' => $cash->id, 'currency' => 'INR', 'debit_amount' => '100.00']);
                $entry->lines()->create(['school_id' => $school->id, 'ledger_account_id' => $income->id, 'currency' => 'INR', 'credit_amount' => '80.00']);
            });
        });

        $this->expectException(QueryException::class);
        $this->forceConstraintCheck($school);
    }

    #[Test]
    public function a_header_with_zero_lines_is_rejected_at_commit(): void
    {
        $school = $this->createSchool();
        $context = app(TenantContext::class);

        $context->withSchool($school, function () use ($school) {
            JournalEntry::query()->create([
                'school_id' => $school->id,
                'currency' => 'INR',
                'description' => 'No lines',
            ]);
        });

        $this->expectException(QueryException::class);
        $this->forceConstraintCheck($school);
    }

    #[Test]
    public function a_single_line_entry_is_rejected_at_commit(): void
    {
        $school = $this->createSchool();
        $context = app(TenantContext::class);
        $cash = $this->createLedgerAccount($school, ['type' => 'asset']);

        $context->withSchool($school, function () use ($school, $cash) {
            $entry = JournalEntry::query()->create([
                'school_id' => $school->id,
                'currency' => 'INR',
                'description' => 'Single line',
            ]);

            $entry->lines()->create(['school_id' => $school->id, 'ledger_account_id' => $cash->id, 'currency' => 'INR', 'debit_amount' => '100.00']);
        });

        $this->expectException(QueryException::class);
        $this->forceConstraintCheck($school);
    }

    /**
     * Disambiguates a per-entry check from an incorrect global-sum
     * check (section 26's "do not create a global-sum check
     * accidentally"): entry A is individually unbalanced by +20,
     * entry B by -20 -- GLOBALLY the two entries' total debits equal
     * their total credits (180 = 180), which a buggy global-sum
     * trigger would incorrectly accept. The correct per-row trigger
     * must reject both, because each is individually unbalanced.
     */
    #[Test]
    public function two_entries_that_are_individually_unbalanced_but_globally_balanced_are_both_rejected(): void
    {
        $school = $this->createSchool();
        $context = app(TenantContext::class);
        $cash = $this->createLedgerAccount($school, ['type' => 'asset']);
        $income = $this->createLedgerAccount($school, ['type' => 'income']);

        $context->withSchool($school, function () use ($school, $cash, $income) {
            DB::transaction(function () use ($school, $cash, $income) {
                $entryA = JournalEntry::query()->create(['school_id' => $school->id, 'currency' => 'INR', 'description' => 'A']);
                $entryA->lines()->create(['school_id' => $school->id, 'ledger_account_id' => $cash->id, 'currency' => 'INR', 'debit_amount' => '100.00']);
                $entryA->lines()->create(['school_id' => $school->id, 'ledger_account_id' => $income->id, 'currency' => 'INR', 'credit_amount' => '80.00']);

                $entryB = JournalEntry::query()->create(['school_id' => $school->id, 'currency' => 'INR', 'description' => 'B']);
                $entryB->lines()->create(['school_id' => $school->id, 'ledger_account_id' => $cash->id, 'currency' => 'INR', 'debit_amount' => '80.00']);
                $entryB->lines()->create(['school_id' => $school->id, 'ledger_account_id' => $income->id, 'currency' => 'INR', 'credit_amount' => '100.00']);
            });
        });

        $this->expectException(QueryException::class);
        $this->forceConstraintCheck($school);
    }

    #[Test]
    public function two_independently_balanced_entries_in_one_transaction_both_pass(): void
    {
        $school = $this->createSchool();
        $context = app(TenantContext::class);
        $cash = $this->createLedgerAccount($school, ['type' => 'asset']);
        $income = $this->createLedgerAccount($school, ['type' => 'income']);

        [$entryA, $entryB] = $context->withSchool($school, function () use ($school, $cash, $income) {
            return DB::transaction(function () use ($school, $cash, $income) {
                $entryA = JournalEntry::query()->create(['school_id' => $school->id, 'currency' => 'INR', 'description' => 'A']);
                $entryA->lines()->create(['school_id' => $school->id, 'ledger_account_id' => $cash->id, 'currency' => 'INR', 'debit_amount' => '100.00']);
                $entryA->lines()->create(['school_id' => $school->id, 'ledger_account_id' => $income->id, 'currency' => 'INR', 'credit_amount' => '100.00']);

                $entryB = JournalEntry::query()->create(['school_id' => $school->id, 'currency' => 'INR', 'description' => 'B']);
                $entryB->lines()->create(['school_id' => $school->id, 'ledger_account_id' => $cash->id, 'currency' => 'INR', 'debit_amount' => '50.00']);
                $entryB->lines()->create(['school_id' => $school->id, 'ledger_account_id' => $income->id, 'currency' => 'INR', 'credit_amount' => '50.00']);

                return [$entryA, $entryB];
            });
        });

        $this->forceConstraintCheck($school);

        $this->assertSame(2, $context->withSchool($school, fn () => $entryA->lines()->count()));
        $this->assertSame(2, $context->withSchool($school, fn () => $entryB->lines()->count()));
    }
}
