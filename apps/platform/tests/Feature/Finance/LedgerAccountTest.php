<?php

namespace Tests\Feature\Finance;

use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantRls;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesFinanceFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 0G.1 (ADR 0030, docs/modules/FINANCE.md "Account model"):
 * ledger_accounts is reference/configuration data, not a posting --
 * unlike journal_entries/journal_lines it is NOT append-only, so this
 * suite tests its own, different lifecycle rules (rename/deactivate
 * allowed, code uniqueness per School, no delete path).
 */
class LedgerAccountTest extends TestCase
{
    use CreatesFinanceFixtures, CreatesTenancyFixtures;

    /**
     * Every raw `DB::connection('pgsql')->insert(...)` test below MUST
     * call this first. RLS is FORCE-enabled on ledger_accounts (ADR
     * 0021/0022) -- with no `app.current_school_id` session GUC set at
     * all, the WITH CHECK clause evaluates `school_id = NULL`, which
     * is never true, so EVERY insert is rejected regardless of any
     * OTHER constraint (type/status/currency). A test asserting only
     * `expectException(QueryException::class)` without first setting
     * context would still "pass" even if the CHECK constraint it
     * claims to prove were completely broken -- it would just be
     * observing the RLS rejection instead. This was a real gap fixed
     * during the 0G.1 closure review (section 13/27): the pre-existing
     * type/status/currency tests below did not set context and were
     * unknowingly passing for the wrong reason.
     */
    private function setSchool(string $schoolId): void
    {
        DB::connection('pgsql')->select('select set_config(?, ?, false)', [TenantRls::SESSION_VAR, $schoolId]);
    }

    #[Test]
    public function account_codes_are_case_insensitively_unique_within_a_school(): void
    {
        $school = $this->createSchool();
        $this->createLedgerAccount($school, ['code' => 'CASH']);

        $this->expectException(QueryException::class);

        $this->createLedgerAccount($school, ['code' => 'cash']);
    }

    /**
     * Closure review section 13: the previous test above only proves
     * the ELOQUENT path is safe (NormalizesCode uppercases before
     * either insert reaches the database, so it never actually tests
     * the database's own case-insensitivity). This test bypasses the
     * model mutator entirely with a raw lowercase insert to prove the
     * expression unique index (`ledger_accounts_school_id_code_ci_unique`
     * on `(school_id, upper(code))`) is what actually enforces this,
     * not application discipline.
     */
    #[Test]
    public function raw_sql_proves_case_insensitive_code_uniqueness_is_database_enforced(): void
    {
        $school = $this->createSchool();
        $this->createLedgerAccount($school, ['code' => 'CASH']);

        $this->setSchool($school->id);

        $this->expectException(QueryException::class);

        DB::connection('pgsql')->insert(
            'insert into ledger_accounts (id, school_id, code, name, type, currency, is_system, status, created_at, updated_at) '.
            "values (gen_random_uuid(), ?, 'cash', 'Raw Lowercase Cash', 'asset', 'INR', false, 'active', now(), now())",
            [$school->id],
        );
    }

    #[Test]
    public function raw_sql_case_variant_codes_in_different_schools_are_allowed(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $this->createLedgerAccount($schoolA, ['code' => 'CASH']);

        $this->setSchool($schoolB->id);

        DB::connection('pgsql')->insert(
            'insert into ledger_accounts (id, school_id, code, name, type, currency, is_system, status, created_at, updated_at) '.
            "values (gen_random_uuid(), ?, 'cash', 'Raw Lowercase Cash', 'asset', 'INR', false, 'active', now(), now())",
            [$schoolB->id],
        );

        // Queried via the SAME 'pgsql' connection/session the insert
        // above used (not 'pgsql_admin', a separate physical
        // connection that cannot see this test's still-uncommitted
        // transaction under READ COMMITTED) -- and RLS still permits
        // it since the session's school context is already School B.
        $count = DB::connection('pgsql')->selectOne(
            'select count(*) as c from ledger_accounts where school_id = ?',
            [$schoolB->id],
        )->c;
        $this->assertSame(1, (int) $count);
    }

    #[Test]
    public function the_same_code_may_be_reused_by_a_different_school(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();

        $accountA = $this->createLedgerAccount($schoolA, ['code' => 'CASH']);
        $accountB = $this->createLedgerAccount($schoolB, ['code' => 'CASH']);

        $this->assertSame('CASH', $accountA->code);
        $this->assertSame('CASH', $accountB->code);
        $this->assertNotSame($accountA->school_id, $accountB->school_id);
    }

    #[Test]
    public function an_invalid_account_type_is_rejected_by_the_database(): void
    {
        $school = $this->createSchool();
        $this->setSchool($school->id);

        $this->expectException(QueryException::class);

        DB::connection('pgsql')->insert(
            'insert into ledger_accounts (id, school_id, code, name, type, currency, is_system, status, created_at, updated_at) '.
            "values (gen_random_uuid(), ?, 'BAD', 'Bad Account', 'not_a_real_type', 'INR', false, 'active', now(), now())",
            [$school->id],
        );
    }

    #[Test]
    public function an_invalid_status_is_rejected_by_the_database(): void
    {
        $school = $this->createSchool();
        $this->setSchool($school->id);

        $this->expectException(QueryException::class);

        DB::connection('pgsql')->insert(
            'insert into ledger_accounts (id, school_id, code, name, type, currency, is_system, status, created_at, updated_at) '.
            "values (gen_random_uuid(), ?, 'BAD', 'Bad Account', 'asset', 'INR', false, 'not_a_real_status', now(), now())",
            [$school->id],
        );
    }

    #[Test]
    public function a_lowercase_currency_code_is_rejected_by_the_database(): void
    {
        $school = $this->createSchool();
        $this->setSchool($school->id);

        $this->expectException(QueryException::class);

        DB::connection('pgsql')->insert(
            'insert into ledger_accounts (id, school_id, code, name, type, currency, is_system, status, created_at, updated_at) '.
            "values (gen_random_uuid(), ?, 'BAD', 'Bad Account', 'asset', 'inr', false, 'active', now(), now())",
            [$school->id],
        );
    }

    /**
     * Closure review section 7-9: FINANCE.md's own text ("today: INR
     * only" / "even though INR is the only currency in scope today")
     * is Phase 0G is INR-only globally, not merely
     * single-currency-per-School -- `ledger_accounts_currency_inr_only_check`
     * enforces `currency = 'INR'` directly, so even a well-formed
     * ISO-4217-shaped code for a real other currency is rejected.
     */
    #[Test]
    public function a_non_inr_currency_is_rejected_even_when_well_formed(): void
    {
        $school = $this->createSchool();
        $this->setSchool($school->id);

        $this->expectException(QueryException::class);

        DB::connection('pgsql')->insert(
            'insert into ledger_accounts (id, school_id, code, name, type, currency, is_system, status, created_at, updated_at) '.
            "values (gen_random_uuid(), ?, 'BAD', 'Bad Account', 'asset', 'USD', false, 'active', now(), now())",
            [$school->id],
        );
    }

    #[Test]
    public function an_account_can_be_renamed_and_deactivated(): void
    {
        $school = $this->createSchool();
        $account = $this->createLedgerAccount($school, ['name' => 'Cash', 'status' => 'active']);

        app(TenantContext::class)->withSchool($school, function () use ($account) {
            $account->update(['name' => 'Cash on Hand', 'status' => 'inactive']);
            $account->refresh();
        });

        $this->assertSame('Cash on Hand', $account->name);
        $this->assertFalse($account->isActive());
    }

    #[Test]
    public function ledger_accounts_have_no_mutable_balance_column(): void
    {
        $columns = DB::connection('pgsql_admin')->select(
            "select column_name from information_schema.columns where table_name = 'ledger_accounts' ".
            "and column_name in ('balance', 'current_balance', 'running_balance', 'account_balance')"
        );

        $this->assertCount(0, $columns, 'ledger_accounts must not carry a canonical mutable balance column (ADR 0030).');
    }
}
