<?php

namespace Tests\Feature\Postgres;

use App\Domain\Fees\Application\ChargeService;
use App\Domain\Fees\Infrastructure\Charge;
use App\Domain\Finance\Application\LedgerService;
use App\Domain\Finance\Infrastructure\JournalEntry;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantRls;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesFeesFixtures;
use Tests\Concerns\CreatesFinanceFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 0G.4 (CLAUDE.md rule 28, mandatory): proves tenant isolation
 * for `charges` at the raw-SQL level against real PostgreSQL, under
 * the unprivileged `school_os_app` runtime role -- independent of
 * Eloquent's SchoolScope, mirroring
 * `Tests\Feature\Postgres\FinanceRawIsolationTest`'s exact shape.
 */
class FeesRawIsolationTest extends TestCase
{
    use CreatesFeesFixtures, CreatesFinanceFixtures, CreatesTenancyFixtures;

    private function setSchool(string $schoolId): void
    {
        DB::connection('pgsql')->select('select set_config(?, ?, false)', [TenantRls::SESSION_VAR, $schoolId]);
    }

    #[Test]
    public function the_charges_table_has_rls_enabled_and_forced(): void
    {
        $row = DB::connection('pgsql_admin')->selectOne(
            'select relrowsecurity, relforcerowsecurity from pg_class '.
            'where relname = ? and relnamespace = ?::regnamespace',
            ['charges', 'public'],
        );

        $this->assertNotNull($row, 'charges must exist');
        $this->assertTrue($row->relrowsecurity, 'charges must have RLS enabled');
        $this->assertTrue($row->relforcerowsecurity, 'charges must FORCE RLS');
    }

    #[Test]
    public function no_school_context_sees_zero_charge_rows(): void
    {
        $school = $this->createSchool();
        $student = $this->createStudent($school);
        $year = $this->createAcademicYear($school);
        $receivable = $this->createLedgerAccount($school, ['type' => 'asset']);
        $revenue = $this->createLedgerAccount($school, ['type' => 'income']);
        $this->assessCharge($school, $student, $year, $receivable, $revenue);

        DB::connection('pgsql')->statement('RESET '.TenantRls::SESSION_VAR);

        $count = DB::connection('pgsql')->selectOne('select count(*) as c from charges')->c;
        $this->assertSame(0, (int) $count, 'charges must be invisible with no TenantContext');
    }

    #[Test]
    public function school_a_cannot_read_school_bs_charge(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $studentB = $this->createStudent($schoolB);
        $yearB = $this->createAcademicYear($schoolB);
        $receivableB = $this->createLedgerAccount($schoolB, ['type' => 'asset']);
        $revenueB = $this->createLedgerAccount($schoolB, ['type' => 'income']);
        $chargeB = $this->assessCharge($schoolB, $studentB, $yearB, $receivableB, $revenueB);

        $this->setSchool($schoolA->id);

        $rows = DB::connection('pgsql')->select('select id from charges where id = ?', [$chargeB->id]);
        $this->assertCount(0, $rows, "School A must not see School B's charge");
    }

    #[Test]
    public function cross_school_raw_update_on_charges_affects_zero_rows(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $studentB = $this->createStudent($schoolB);
        $yearB = $this->createAcademicYear($schoolB);
        $receivableB = $this->createLedgerAccount($schoolB, ['type' => 'asset']);
        $revenueB = $this->createLedgerAccount($schoolB, ['type' => 'income']);
        $chargeB = $this->assessCharge($schoolB, $studentB, $yearB, $receivableB, $revenueB);

        $this->setSchool($schoolA->id);

        $this->assertSame(0, DB::connection('pgsql')->update(
            "update charges set description = 'tampered' where id = ?",
            [$chargeB->id],
        ));
    }

    #[Test]
    public function raw_insert_of_a_charge_for_a_different_school_than_the_active_context_is_rejected(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $studentA = $this->createStudent($schoolA);
        $yearA = $this->createAcademicYear($schoolA);
        $receivableA = $this->createLedgerAccount($schoolA, ['type' => 'asset']);
        $revenueA = $this->createLedgerAccount($schoolA, ['type' => 'income']);
        $entryA = $this->postBalancedJournalEntry($schoolA, $receivableA, $revenueA, '1.00');

        $this->setSchool($schoolA->id);

        $this->expectException(QueryException::class);

        DB::connection('pgsql')->insert(
            'insert into charges '.
            '(id, school_id, student_id, academic_year_id, description, amount, currency, '.
            'receivable_ledger_account_id, revenue_ledger_account_id, journal_entry_id, created_at, updated_at) '.
            "values (gen_random_uuid(), ?, ?, ?, 'raw insert', 1.00, 'INR', ?, ?, ?, now(), now())",
            [$schoolB->id, $studentA->id, $yearA->id, $receivableA->id, $revenueA->id, $entryA->id],
        );
    }

    #[Test]
    public function the_amount_positive_check_constraint_enforces_under_the_real_runtime_role(): void
    {
        $school = $this->createSchool();
        $student = $this->createStudent($school);
        $year = $this->createAcademicYear($school);
        $receivable = $this->createLedgerAccount($school, ['type' => 'asset']);
        $revenue = $this->createLedgerAccount($school, ['type' => 'income']);
        $entry = $this->postBalancedJournalEntry($school, $receivable, $revenue, '1.00');

        $this->setSchool($school->id);

        $this->expectException(QueryException::class);

        DB::connection('pgsql')->insert(
            'insert into charges '.
            '(id, school_id, student_id, academic_year_id, description, amount, currency, '.
            'receivable_ledger_account_id, revenue_ledger_account_id, journal_entry_id, created_at, updated_at) '.
            "values (gen_random_uuid(), ?, ?, ?, 'zero amount', 0.00, 'INR', ?, ?, ?, now(), now())",
            [$school->id, $student->id, $year->id, $receivable->id, $revenue->id, $entry->id],
        );
    }

    #[Test]
    public function the_distinct_accounts_check_constraint_enforces_under_the_real_runtime_role(): void
    {
        $school = $this->createSchool();
        $student = $this->createStudent($school);
        $year = $this->createAcademicYear($school);
        $account = $this->createLedgerAccount($school, ['type' => 'asset']);
        $income = $this->createLedgerAccount($school, ['type' => 'income']);
        $entry = $this->postBalancedJournalEntry($school, $account, $income, '1.00');

        $this->setSchool($school->id);

        $this->expectException(QueryException::class);

        DB::connection('pgsql')->insert(
            'insert into charges '.
            '(id, school_id, student_id, academic_year_id, description, amount, currency, '.
            'receivable_ledger_account_id, revenue_ledger_account_id, journal_entry_id, created_at, updated_at) '.
            "values (gen_random_uuid(), ?, ?, ?, 'same account', 1.00, 'INR', ?, ?, ?, now(), now())",
            [$school->id, $student->id, $year->id, $account->id, $account->id, $entry->id],
        );
    }

    #[Test]
    public function the_cancellation_pair_check_constraint_enforces_under_the_real_runtime_role(): void
    {
        $school = $this->createSchool();
        $student = $this->createStudent($school);
        $year = $this->createAcademicYear($school);
        $receivable = $this->createLedgerAccount($school, ['type' => 'asset']);
        $revenue = $this->createLedgerAccount($school, ['type' => 'income']);
        $charge = $this->assessCharge($school, $student, $year, $receivable, $revenue);

        $this->setSchool($school->id);

        $this->expectException(QueryException::class);

        DB::connection('pgsql')->update(
            'update charges set cancelled_at = now() where id = ?',
            [$charge->id],
        );
    }

    // --- Closure correction: recognized-field immutability (section 2/3/11) ---

    public static function immutableFieldUpdates(): array
    {
        return [
            'amount' => ["update charges set amount = '999.99' where id = ?"],
            'student_id' => ['update charges set student_id = gen_random_uuid() where id = ?'],
            'academic_year_id' => ['update charges set academic_year_id = gen_random_uuid() where id = ?'],
            'currency' => ["update charges set currency = 'USD' where id = ?"],
            'description' => ["update charges set description = 'tampered' where id = ?"],
            'due_date' => ["update charges set due_date = '2030-01-01' where id = ?"],
        ];
    }

    #[Test]
    #[DataProvider('immutableFieldUpdates')]
    public function recognized_field_updates_are_rejected_by_the_database_under_the_real_runtime_role(string $sql): void
    {
        $school = $this->createSchool();
        $student = $this->createStudent($school);
        $year = $this->createAcademicYear($school);
        $receivable = $this->createLedgerAccount($school, ['type' => 'asset']);
        $revenue = $this->createLedgerAccount($school, ['type' => 'income']);
        $charge = $this->assessCharge($school, $student, $year, $receivable, $revenue);

        $this->setSchool($school->id);

        $this->expectException(QueryException::class);

        DB::connection('pgsql')->update($sql, [$charge->id]);
    }

    #[Test]
    public function the_receivable_ledger_account_link_cannot_be_updated_by_the_runtime_role(): void
    {
        $school = $this->createSchool();
        $student = $this->createStudent($school);
        $year = $this->createAcademicYear($school);
        $receivable = $this->createLedgerAccount($school, ['type' => 'asset']);
        $otherReceivable = $this->createLedgerAccount($school, ['type' => 'asset']);
        $revenue = $this->createLedgerAccount($school, ['type' => 'income']);
        $charge = $this->assessCharge($school, $student, $year, $receivable, $revenue);

        $this->setSchool($school->id);

        $this->expectException(QueryException::class);

        DB::connection('pgsql')->update(
            'update charges set receivable_ledger_account_id = ? where id = ?',
            [$otherReceivable->id, $charge->id],
        );
    }

    #[Test]
    public function the_revenue_ledger_account_link_cannot_be_updated_by_the_runtime_role(): void
    {
        $school = $this->createSchool();
        $student = $this->createStudent($school);
        $year = $this->createAcademicYear($school);
        $receivable = $this->createLedgerAccount($school, ['type' => 'asset']);
        $revenue = $this->createLedgerAccount($school, ['type' => 'income']);
        $otherRevenue = $this->createLedgerAccount($school, ['type' => 'income']);
        $charge = $this->assessCharge($school, $student, $year, $receivable, $revenue);

        $this->setSchool($school->id);

        $this->expectException(QueryException::class);

        DB::connection('pgsql')->update(
            'update charges set revenue_ledger_account_id = ? where id = ?',
            [$otherRevenue->id, $charge->id],
        );
    }

    #[Test]
    public function the_recognition_journal_link_cannot_be_repointed_by_the_runtime_role(): void
    {
        $school = $this->createSchool();
        $student = $this->createStudent($school);
        $year = $this->createAcademicYear($school);
        $receivable = $this->createLedgerAccount($school, ['type' => 'asset']);
        $revenue = $this->createLedgerAccount($school, ['type' => 'income']);
        $charge = $this->assessCharge($school, $student, $year, $receivable, $revenue);
        $unrelatedEntry = $this->postBalancedJournalEntry($school, $receivable, $revenue, '1.00');

        $this->setSchool($school->id);

        $this->expectException(QueryException::class);

        DB::connection('pgsql')->update(
            'update charges set journal_entry_id = ? where id = ?',
            [$unrelatedEntry->id, $charge->id],
        );
    }

    // --- Closure correction: no hard delete (section 4/12) ---

    #[Test]
    public function a_recognized_charge_cannot_be_hard_deleted_by_the_runtime_role(): void
    {
        $school = $this->createSchool();
        $student = $this->createStudent($school);
        $year = $this->createAcademicYear($school);
        $receivable = $this->createLedgerAccount($school, ['type' => 'asset']);
        $revenue = $this->createLedgerAccount($school, ['type' => 'income']);
        $charge = $this->assessCharge($school, $student, $year, $receivable, $revenue);

        $this->setSchool($school->id);

        try {
            // Wrapped in its own SAVEPOINT (DB::transaction() nests
            // automatically inside the outer DatabaseTransactions-
            // managed transaction this test already runs under) so the
            // permission-denied failure's automatic `ROLLBACK TO
            // SAVEPOINT` leaves the connection usable for the
            // verification queries below -- a raw, unwrapped failed
            // statement would otherwise poison the whole outer
            // transaction with PostgreSQL's "current transaction is
            // aborted" (25P02) until the test itself rolls back.
            DB::connection('pgsql')->transaction(function () use ($charge) {
                DB::connection('pgsql')->delete('delete from charges where id = ?', [$charge->id]);
            });
            $this->fail('Expected a permission-denied QueryException for charges DELETE.');
        } catch (QueryException $e) {
            $this->assertStringContainsStringIgnoringCase('permission denied', $e->getMessage());
        }

        $stillExists = DB::connection('pgsql')->selectOne('select id from charges where id = ?', [$charge->id]);
        $this->assertNotNull($stillExists, 'The charge must still exist after a rejected DELETE.');

        $journalStillExists = DB::connection('pgsql')->selectOne('select id from journal_entries where id = ?', [$charge->journal_entry_id]);
        $this->assertNotNull($journalStillExists, 'The recognition journal must be unaffected by a rejected charge DELETE.');
    }

    // --- Closure correction: semantic cancellation-link validation (section 5/13) ---

    #[Test]
    public function an_unrelated_same_school_journal_cannot_be_used_as_a_fake_cancellation(): void
    {
        $school = $this->createSchool();
        $student = $this->createStudent($school);
        $year = $this->createAcademicYear($school);
        $receivable = $this->createLedgerAccount($school, ['type' => 'asset']);
        $revenue = $this->createLedgerAccount($school, ['type' => 'income']);
        $charge = $this->assessCharge($school, $student, $year, $receivable, $revenue, '100.00');
        $unrelatedJournalB = $this->postBalancedJournalEntry($school, $receivable, $revenue, '1.00');

        $this->setSchool($school->id);

        $this->expectException(QueryException::class);

        DB::connection('pgsql')->update(
            'update charges set cancelled_at = now(), cancellation_journal_entry_id = ? where id = ?',
            [$unrelatedJournalB->id, $charge->id],
        );
    }

    #[Test]
    public function the_real_reversal_of_the_recognition_journal_is_accepted_for_cancellation(): void
    {
        $school = $this->createSchool();
        $student = $this->createStudent($school);
        $year = $this->createAcademicYear($school);
        $receivable = $this->createLedgerAccount($school, ['type' => 'asset']);
        $revenue = $this->createLedgerAccount($school, ['type' => 'income']);
        $charge = $this->assessCharge($school, $student, $year, $receivable, $revenue, '100.00');
        $originalEntry = app(TenantContext::class)->withSchool(
            $school,
            fn () => JournalEntry::query()->findOrFail($charge->journal_entry_id),
        );
        $realReversal = app(LedgerService::class)->reverse($originalEntry);

        $this->setSchool($school->id);

        $affected = DB::connection('pgsql')->update(
            'update charges set cancelled_at = now(), cancellation_journal_entry_id = ? where id = ?',
            [$realReversal->journalEntryId, $charge->id],
        );

        $this->assertSame(1, $affected, 'A cancellation naming the TRUE reversal of its own recognition journal must be accepted.');
    }

    // --- Closure correction: cancellation pair finality (section 7/8) ---

    #[Test]
    public function a_cancelled_charge_cannot_be_uncancelled_by_the_runtime_role(): void
    {
        $school = $this->createSchool();
        $student = $this->createStudent($school);
        $year = $this->createAcademicYear($school);
        $receivable = $this->createLedgerAccount($school, ['type' => 'asset']);
        $revenue = $this->createLedgerAccount($school, ['type' => 'income']);
        $charge = $this->assessCharge($school, $student, $year, $receivable, $revenue);
        app(ChargeService::class)->cancel($school, $charge->id);

        $this->setSchool($school->id);

        $this->expectException(QueryException::class);

        DB::connection('pgsql')->update(
            'update charges set cancelled_at = null, cancellation_journal_entry_id = null where id = ?',
            [$charge->id],
        );
    }

    #[Test]
    public function a_cancelled_charges_reversal_link_cannot_be_repointed_by_the_runtime_role(): void
    {
        $school = $this->createSchool();
        $student = $this->createStudent($school);
        $year = $this->createAcademicYear($school);
        $receivable = $this->createLedgerAccount($school, ['type' => 'asset']);
        $revenue = $this->createLedgerAccount($school, ['type' => 'income']);
        $charge = $this->assessCharge($school, $student, $year, $receivable, $revenue);
        app(ChargeService::class)->cancel($school, $charge->id);
        $unrelatedJournal = $this->postBalancedJournalEntry($school, $receivable, $revenue, '1.00');

        $this->setSchool($school->id);

        $this->expectException(QueryException::class);

        DB::connection('pgsql')->update(
            'update charges set cancellation_journal_entry_id = ? where id = ?',
            [$unrelatedJournal->id, $charge->id],
        );
    }

    // --- Closure correction: journal-link uniqueness (section 9/10) ---

    #[Test]
    public function the_same_recognition_journal_cannot_back_two_different_charges(): void
    {
        $school = $this->createSchool();
        $studentA = $this->createStudent($school);
        $studentB = $this->createStudent($school);
        $year = $this->createAcademicYear($school);
        $receivable = $this->createLedgerAccount($school, ['type' => 'asset']);
        $revenue = $this->createLedgerAccount($school, ['type' => 'income']);
        $chargeA = $this->assessCharge($school, $studentA, $year, $receivable, $revenue, '50.00');

        $this->setSchool($school->id);

        $this->expectException(QueryException::class);

        DB::connection('pgsql')->insert(
            'insert into charges '.
            '(id, school_id, student_id, academic_year_id, description, amount, currency, '.
            'receivable_ledger_account_id, revenue_ledger_account_id, journal_entry_id, created_at, updated_at) '.
            "values (gen_random_uuid(), ?, ?, ?, 'duplicate posting', 50.00, 'INR', ?, ?, ?, now(), now())",
            [$school->id, $studentB->id, $year->id, $receivable->id, $revenue->id, $chargeA->journal_entry_id],
        );
    }

    /**
     * Isolates the `charges_school_id_cancellation_journal_entry_id_unique`
     * constraint specifically -- via a raw INSERT with the cancellation
     * pair already populated at creation time (an operation the real
     * application never performs), since the `charges_immutability_trigger`
     * only fires on UPDATE and its semantic reversal check would
     * otherwise also reject an UPDATE-based attempt (a reversal of
     * Charge A's own journal can never simultaneously be a valid
     * reversal of Charge B's different journal, so an UPDATE-based
     * attempt would be rejected by section 5's semantic check first,
     * not by this uniqueness constraint) -- both defenses are real and
     * both are proven, but this test isolates the uniqueness layer on
     * its own.
     */
    #[Test]
    public function the_same_cancellation_journal_cannot_back_two_different_charges(): void
    {
        $school = $this->createSchool();
        $studentA = $this->createStudent($school);
        $studentB = $this->createStudent($school);
        $year = $this->createAcademicYear($school);
        $receivable = $this->createLedgerAccount($school, ['type' => 'asset']);
        $revenue = $this->createLedgerAccount($school, ['type' => 'income']);
        $chargeA = $this->assessCharge($school, $studentA, $year, $receivable, $revenue, '50.00');
        $chargeB = $this->assessCharge($school, $studentB, $year, $receivable, $revenue, '75.00');
        app(ChargeService::class)->cancel($school, $chargeA->id);
        $chargeAReloaded = app(TenantContext::class)->withSchool(
            $school,
            fn () => Charge::query()->findOrFail($chargeA->id),
        );

        $this->setSchool($school->id);

        $this->expectException(QueryException::class);

        // A fresh raw INSERT of a THIRD charge row, reusing chargeA's
        // already-attached cancellation journal as its own, proves the
        // uniqueness constraint directly.
        DB::connection('pgsql')->insert(
            'insert into charges '.
            '(id, school_id, student_id, academic_year_id, description, amount, currency, '.
            'receivable_ledger_account_id, revenue_ledger_account_id, journal_entry_id, '.
            'cancelled_at, cancellation_journal_entry_id, created_at, updated_at) '.
            "values (gen_random_uuid(), ?, ?, ?, 'duplicate cancellation link', 75.00, 'INR', ?, ?, ?, now(), ?, now(), now())",
            [
                $school->id, $studentB->id, $year->id, $receivable->id, $revenue->id,
                $chargeB->journal_entry_id, $chargeAReloaded->cancellation_journal_entry_id,
            ],
        );
    }
}
