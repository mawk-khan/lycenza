<?php

namespace Tests\Feature\Postgres;

use App\Domain\Finance\Infrastructure\FinancialPeriod;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantRls;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesFinanceFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 0G.1 (CLAUDE.md rule 28, mandatory): proves tenant isolation
 * for ledger_accounts/journal_entries/journal_lines at the raw-SQL
 * level against real PostgreSQL, under the unprivileged
 * `school_os_app` runtime role -- independent of Eloquent's
 * SchoolScope, exactly like DocumentRawIsolationTest/HrRawIsolationTest.
 * Also proves the append-only privilege revocation (journal_entries/
 * journal_lines) and the deferred balance trigger, both under this
 * same real runtime role.
 */
class FinanceRawIsolationTest extends TestCase
{
    use CreatesFinanceFixtures, CreatesTenancyFixtures;

    private function setSchool(string $schoolId): void
    {
        DB::connection('pgsql')->select('select set_config(?, ?, false)', [TenantRls::SESSION_VAR, $schoolId]);
    }

    private function forceConstraintCheck(): void
    {
        DB::connection('pgsql')->statement('SET CONSTRAINTS ALL IMMEDIATE');
    }

    public static function financeTables(): array
    {
        return [
            'ledger_accounts' => ['ledger_accounts'],
            'journal_entries' => ['journal_entries'],
            'journal_lines' => ['journal_lines'],
        ];
    }

    #[Test]
    #[DataProvider('financeTables')]
    public function the_table_has_rls_enabled_and_forced(string $table): void
    {
        $row = DB::connection('pgsql_admin')->selectOne(
            'select relrowsecurity, relforcerowsecurity from pg_class '.
            'where relname = ? and relnamespace = ?::regnamespace',
            [$table, 'public'],
        );

        $this->assertNotNull($row, "{$table} must exist");
        $this->assertTrue($row->relrowsecurity, "{$table} must have RLS enabled");
        $this->assertTrue($row->relforcerowsecurity, "{$table} must FORCE RLS");
    }

    #[Test]
    #[DataProvider('financeTables')]
    public function no_school_context_sees_zero_rows(string $table): void
    {
        $school = $this->createSchool();
        $account = $this->createLedgerAccount($school);
        if ($table !== 'ledger_accounts') {
            $account2 = $this->createLedgerAccount($school);
            $this->postBalancedJournalEntry($school, $account, $account2);
        }

        DB::connection('pgsql')->statement('RESET '.TenantRls::SESSION_VAR);

        $count = DB::connection('pgsql')->selectOne("select count(*) as c from {$table}")->c;
        $this->assertSame(0, (int) $count, "{$table} must be invisible with no TenantContext");
    }

    #[Test]
    public function school_a_cannot_read_school_bs_ledger_account(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $accountB = $this->createLedgerAccount($schoolB);

        $this->setSchool($schoolA->id);

        $rows = DB::connection('pgsql')->select('select id from ledger_accounts where id = ?', [$accountB->id]);
        $this->assertCount(0, $rows, "School A must not see School B's ledger account");
    }

    #[Test]
    public function school_a_cannot_read_school_bs_journal_entry_or_lines(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $cashB = $this->createLedgerAccount($schoolB, ['type' => 'asset']);
        $incomeB = $this->createLedgerAccount($schoolB, ['type' => 'income']);
        $entryB = $this->postBalancedJournalEntry($schoolB, $cashB, $incomeB);

        $this->setSchool($schoolA->id);

        $entryRows = DB::connection('pgsql')->select('select id from journal_entries where id = ?', [$entryB->id]);
        $this->assertCount(0, $entryRows, "School A must not see School B's journal entry");

        $lineRows = DB::connection('pgsql')->select('select id from journal_lines where journal_entry_id = ?', [$entryB->id]);
        $this->assertCount(0, $lineRows, "School A must not see School B's journal lines");
    }

    #[Test]
    public function cross_school_writes_on_ledger_accounts_affect_zero_rows(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $accountB = $this->createLedgerAccount($schoolB);

        $this->setSchool($schoolA->id);

        $this->assertSame(0, DB::connection('pgsql')->update(
            "update ledger_accounts set status = 'inactive' where id = ?",
            [$accountB->id],
        ));
    }

    #[Test]
    public function raw_insert_of_a_ledger_account_for_a_different_school_than_the_active_context_is_rejected(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();

        $this->setSchool($schoolA->id);

        $this->expectException(QueryException::class);

        DB::connection('pgsql')->insert(
            'insert into ledger_accounts (id, school_id, code, name, type, currency, is_system, status, created_at, updated_at) '.
            "values (gen_random_uuid(), ?, 'X', 'X', 'asset', 'INR', false, 'active', now(), now())",
            [$schoolB->id],
        );
    }

    #[Test]
    public function raw_insert_of_a_journal_entry_for_a_different_school_than_the_active_context_is_rejected(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();

        $this->setSchool($schoolA->id);

        $this->expectException(QueryException::class);

        DB::connection('pgsql')->insert(
            'insert into journal_entries (id, school_id, currency, description, posted_at, created_at) '.
            "values (gen_random_uuid(), ?, 'INR', 'x', now(), now())",
            [$schoolB->id],
        );
    }

    #[Test]
    public function journal_entries_cannot_be_updated_by_the_runtime_role_even_for_its_own_school(): void
    {
        $school = $this->createSchool();
        $cash = $this->createLedgerAccount($school, ['type' => 'asset']);
        $income = $this->createLedgerAccount($school, ['type' => 'income']);
        $entry = $this->postBalancedJournalEntry($school, $cash, $income);

        $this->setSchool($school->id);

        $this->expectException(QueryException::class);

        DB::connection('pgsql')->update(
            "update journal_entries set description = 'tampered' where id = ?",
            [$entry->id],
        );
    }

    #[Test]
    public function journal_entries_cannot_be_deleted_by_the_runtime_role_even_for_its_own_school(): void
    {
        $school = $this->createSchool();
        $cash = $this->createLedgerAccount($school, ['type' => 'asset']);
        $income = $this->createLedgerAccount($school, ['type' => 'income']);
        $entry = $this->postBalancedJournalEntry($school, $cash, $income);

        $this->setSchool($school->id);

        $this->expectException(QueryException::class);

        DB::connection('pgsql')->delete('delete from journal_entries where id = ?', [$entry->id]);
    }

    /**
     * Split into two separate test methods, each getting its own
     * DatabaseTransactions-wrapped outer transaction (Tests\TestCase) --
     * a single test method attempting BOTH the UPDATE and the DELETE
     * inside the same transaction would find the DELETE failing with
     * PostgreSQL's generic "current transaction is aborted" (25P02)
     * instead of a fresh "permission denied" check, because the first
     * failed statement already poisons the rest of that transaction.
     */
    #[Test]
    public function journal_lines_cannot_be_updated_by_the_runtime_role(): void
    {
        $school = $this->createSchool();
        $cash = $this->createLedgerAccount($school, ['type' => 'asset']);
        $income = $this->createLedgerAccount($school, ['type' => 'income']);
        $entry = $this->postBalancedJournalEntry($school, $cash, $income);
        $lineId = app(TenantContext::class)->withSchool($school, fn () => $entry->lines()->first()->id);

        $this->setSchool($school->id);

        try {
            DB::connection('pgsql')->update("update journal_lines set debit_amount = '999.00' where id = ?", [$lineId]);
            $this->fail('Expected a permission-denied QueryException for journal_lines UPDATE.');
        } catch (QueryException $e) {
            $this->assertStringContainsStringIgnoringCase('permission denied', $e->getMessage());
        }
    }

    #[Test]
    public function journal_lines_cannot_be_deleted_by_the_runtime_role(): void
    {
        $school = $this->createSchool();
        $cash = $this->createLedgerAccount($school, ['type' => 'asset']);
        $income = $this->createLedgerAccount($school, ['type' => 'income']);
        $entry = $this->postBalancedJournalEntry($school, $cash, $income);
        $lineId = app(TenantContext::class)->withSchool($school, fn () => $entry->lines()->first()->id);

        $this->setSchool($school->id);

        try {
            DB::connection('pgsql')->delete('delete from journal_lines where id = ?', [$lineId]);
            $this->fail('Expected a permission-denied QueryException for journal_lines DELETE.');
        } catch (QueryException $e) {
            $this->assertStringContainsStringIgnoringCase('permission denied', $e->getMessage());
        }
    }

    #[Test]
    public function the_balanced_entry_constraint_trigger_enforces_under_the_real_runtime_role(): void
    {
        $school = $this->createSchool();
        $cash = $this->createLedgerAccount($school, ['type' => 'asset']);
        $income = $this->createLedgerAccount($school, ['type' => 'income']);
        // E21.3A: a raw entry needs its financial period to exist (the
        // application creates periods; the trigger only assigns them).
        app(TenantContext::class)->withSchool($school, fn () => FinancialPeriod::ensureContaining($school->id, $school->timezone ?: 'UTC', now()));

        $this->setSchool($school->id);

        DB::connection('pgsql')->insert(
            'insert into journal_entries (id, school_id, currency, description, posted_at, created_at) '.
            "values (gen_random_uuid(), ?, 'INR', 'raw unbalanced', now(), now())",
            [$school->id],
        );
        $entryId = DB::connection('pgsql')->selectOne(
            "select id from journal_entries where school_id = ? and description = 'raw unbalanced'",
            [$school->id],
        )->id;

        DB::connection('pgsql')->insert(
            'insert into journal_lines (id, school_id, journal_entry_id, ledger_account_id, currency, debit_amount, created_at) '.
            "values (gen_random_uuid(), ?, ?, ?, 'INR', 100.00, now())",
            [$school->id, $entryId, $cash->id],
        );
        DB::connection('pgsql')->insert(
            'insert into journal_lines (id, school_id, journal_entry_id, ledger_account_id, currency, credit_amount, created_at) '.
            "values (gen_random_uuid(), ?, ?, ?, 'INR', 80.00, now())",
            [$school->id, $entryId, $income->id],
        );

        $this->expectException(QueryException::class);
        $this->forceConstraintCheck();
    }

    #[Test]
    #[DataProvider('financeTables')]
    public function the_table_has_no_mutable_balance_column(string $table): void
    {
        $columns = DB::connection('pgsql_admin')->select(
            'select column_name from information_schema.columns where table_name = ? '.
            "and column_name in ('balance', 'current_balance', 'running_balance', 'account_balance')",
            [$table],
        );

        $this->assertCount(0, $columns, "{$table} must not carry a canonical mutable balance column (ADR 0030).");
    }

    #[Test]
    public function the_table_has_no_polymorphic_financial_subject_columns(): void
    {
        foreach (['journal_entries', 'journal_lines'] as $table) {
            $columns = DB::connection('pgsql_admin')->select(
                'select column_name from information_schema.columns where table_name = ? '.
                "and column_name in ('subject_type', 'subject_id', 'payable_type', 'payable_id', 'owner_type', 'owner_id')",
                [$table],
            );

            $this->assertCount(0, $columns, "{$table} must not carry a polymorphic financial-subject column (ADR 0030 / FINANCE.md).");
        }
    }

    #[Test]
    public function the_runtime_role_is_not_a_superuser_and_does_not_bypass_rls(): void
    {
        $row = DB::connection('pgsql')->selectOne(
            'select rolsuper, rolbypassrls from pg_roles where rolname = current_user',
        );

        $this->assertFalse($row->rolsuper, 'runtime role must not be a superuser');
        $this->assertFalse($row->rolbypassrls, 'runtime role must not bypass RLS');
    }
}
