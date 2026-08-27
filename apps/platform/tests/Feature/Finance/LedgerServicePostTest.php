<?php

namespace Tests\Feature\Finance;

use App\Domain\Finance\Application\Exceptions\InvalidJournalCurrencyException;
use App\Domain\Finance\Application\Exceptions\InvalidJournalEntryException;
use App\Domain\Finance\Application\Exceptions\LedgerAccountNotFoundException;
use App\Domain\Finance\Application\Exceptions\UnbalancedJournalEntryException;
use App\Domain\Finance\Application\JournalEntryResult;
use App\Domain\Finance\Application\JournalLineData;
use App\Domain\Finance\Application\LedgerService;
use App\Domain\Finance\Application\PostJournalEntryData;
use App\Domain\Finance\Domain\JournalSide;
use App\Domain\Finance\Infrastructure\JournalEntry;
use App\Domain\Finance\Infrastructure\JournalLine;
use App\Models\DomainEventOutbox;
use App\Models\School;
use App\Models\SchoolAuditEvent;
use App\Support\Money\Money;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use RuntimeException;
use Symfony\Component\Uid\UuidV7;
use Tests\Concerns\CreatesFinanceFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 0G.2: proves App\Domain\Finance\Application\LedgerService::post()
 * -- the production posting path -- against real PostgreSQL, under the
 * real `school_os_app` runtime role (phpunit.xml's default `pgsql`
 * connection), with the normal TenantContext lifecycle every test in
 * this repository already uses (Tests\TestCase's DatabaseTransactions +
 * this trait's TenantContext::withSchool() helpers) -- this IS the
 * "TenantContext active through commit" proof section 17/61 of the
 * 0G.2 brief asks for: LedgerService::post() itself does the
 * withSchool()->DB::transaction() nesting, and every assertion below
 * only succeeds if that nesting kept RLS-scoped writes/triggers
 * working correctly the whole way through.
 *
 * `post()` returns `JournalEntryResult` (0G.2 closure correction), not
 * the raw `JournalEntry` model -- tests that need to inspect the
 * persisted lines/model directly fetch them explicitly via
 * `linesFor()`/a direct query, exactly like a real caller receiving
 * only the safe result would have to.
 */
class LedgerServicePostTest extends TestCase
{
    use CreatesFinanceFixtures, CreatesTenancyFixtures;

    private function service(): LedgerService
    {
        return app(LedgerService::class);
    }

    private function postJournal(School $school, array $lines, string $currency = 'INR', string $description = 'Test posting'): JournalEntryResult
    {
        return $this->service()->post($school, new PostJournalEntryData($currency, $description, $lines));
    }

    /** @return Collection<int, JournalLine> */
    private function linesFor(School $school, string $journalEntryId)
    {
        return app(TenantContext::class)->withSchool(
            $school,
            fn () => JournalLine::query()->where('journal_entry_id', $journalEntryId)->orderBy('id')->get(),
        );
    }

    #[Test]
    public function a_balanced_two_line_post_succeeds(): void
    {
        $school = $this->createSchool();
        $cash = $this->createLedgerAccount($school, ['type' => 'asset']);
        $income = $this->createLedgerAccount($school, ['type' => 'income']);

        $result = $this->postJournal($school, [
            new JournalLineData($cash->id, JournalSide::Debit, Money::of('100.00', 'INR')),
            new JournalLineData($income->id, JournalSide::Credit, Money::of('100.00', 'INR')),
        ]);

        $this->assertNotEmpty($result->journalEntryId);
        $this->assertSame(2, $result->lineCount);
        $this->assertCount(2, $this->linesFor($school, $result->journalEntryId));
    }

    #[Test]
    public function a_balanced_multi_line_post_with_multiple_debits_and_credits_succeeds(): void
    {
        $school = $this->createSchool();
        $cash = $this->createLedgerAccount($school, ['type' => 'asset']);
        $bank = $this->createLedgerAccount($school, ['type' => 'asset']);
        $income = $this->createLedgerAccount($school, ['type' => 'income']);
        $refunds = $this->createLedgerAccount($school, ['type' => 'liability']);

        $result = $this->postJournal($school, [
            new JournalLineData($cash->id, JournalSide::Debit, Money::of('60.00', 'INR')),
            new JournalLineData($bank->id, JournalSide::Debit, Money::of('40.00', 'INR')),
            new JournalLineData($income->id, JournalSide::Credit, Money::of('70.00', 'INR')),
            new JournalLineData($refunds->id, JournalSide::Credit, Money::of('30.00', 'INR')),
        ]);

        $this->assertSame(4, $result->lineCount);
        $this->assertCount(4, $this->linesFor($school, $result->journalEntryId));
    }

    #[Test]
    public function the_same_account_may_appear_on_multiple_lines(): void
    {
        $school = $this->createSchool();
        $cash = $this->createLedgerAccount($school, ['type' => 'asset']);
        $income = $this->createLedgerAccount($school, ['type' => 'income']);

        $result = $this->postJournal($school, [
            new JournalLineData($cash->id, JournalSide::Debit, Money::of('50.00', 'INR')),
            new JournalLineData($cash->id, JournalSide::Debit, Money::of('50.00', 'INR')),
            new JournalLineData($income->id, JournalSide::Credit, Money::of('100.00', 'INR')),
        ]);

        $this->assertCount(3, $this->linesFor($school, $result->journalEntryId));
    }

    /**
     * The classic float-unsafe combination -- 0.10 + 0.20 != 0.30 under
     * binary floating point. Proves the stored NUMERIC values are
     * exact, and that LedgerService's own Money-based balance check
     * (bcmath) accepted the combination as genuinely balanced.
     */
    #[Test]
    public function exact_decimal_amounts_are_preserved_through_posting(): void
    {
        $school = $this->createSchool();
        $cash = $this->createLedgerAccount($school, ['type' => 'asset']);
        $income = $this->createLedgerAccount($school, ['type' => 'income']);

        $result = $this->postJournal($school, [
            new JournalLineData($cash->id, JournalSide::Debit, Money::of('0.10', 'INR')),
            new JournalLineData($cash->id, JournalSide::Debit, Money::of('0.20', 'INR')),
            new JournalLineData($income->id, JournalSide::Credit, Money::of('0.30', 'INR')),
        ]);

        $lines = $this->linesFor($school, $result->journalEntryId);
        $this->assertSame('0.10', $lines[0]->debit_amount);
        $this->assertSame('0.20', $lines[1]->debit_amount);
        $this->assertSame('0.30', $lines[2]->credit_amount);
    }

    #[Test]
    public function fewer_than_two_lines_is_rejected(): void
    {
        $school = $this->createSchool();
        $cash = $this->createLedgerAccount($school, ['type' => 'asset']);

        $this->expectException(InvalidJournalEntryException::class);

        $this->postJournal($school, [
            new JournalLineData($cash->id, JournalSide::Debit, Money::of('10.00', 'INR')),
        ]);
    }

    #[Test]
    public function a_zero_amount_line_is_rejected(): void
    {
        $school = $this->createSchool();
        $cash = $this->createLedgerAccount($school, ['type' => 'asset']);
        $income = $this->createLedgerAccount($school, ['type' => 'income']);

        $this->expectException(InvalidJournalEntryException::class);

        $this->postJournal($school, [
            new JournalLineData($cash->id, JournalSide::Debit, Money::of('0.00', 'INR')),
            new JournalLineData($income->id, JournalSide::Credit, Money::of('0.00', 'INR')),
        ]);
    }

    #[Test]
    public function a_negative_amount_line_is_rejected(): void
    {
        $school = $this->createSchool();
        $cash = $this->createLedgerAccount($school, ['type' => 'asset']);
        $income = $this->createLedgerAccount($school, ['type' => 'income']);

        $this->expectException(InvalidJournalEntryException::class);

        $this->postJournal($school, [
            new JournalLineData($cash->id, JournalSide::Debit, Money::of('-10.00', 'INR')),
            new JournalLineData($income->id, JournalSide::Credit, Money::of('10.00', 'INR')),
        ]);
    }

    #[Test]
    public function an_amount_with_more_than_two_decimal_places_is_rejected(): void
    {
        $school = $this->createSchool();
        $cash = $this->createLedgerAccount($school, ['type' => 'asset']);
        $income = $this->createLedgerAccount($school, ['type' => 'income']);

        $this->expectException(InvalidJournalEntryException::class);

        $this->postJournal($school, [
            new JournalLineData($cash->id, JournalSide::Debit, Money::of('10.005', 'INR')),
            new JournalLineData($income->id, JournalSide::Credit, Money::of('10.005', 'INR')),
        ]);
    }

    #[Test]
    public function an_amount_exceeding_the_numeric_14_2_integer_bound_is_rejected(): void
    {
        $school = $this->createSchool();
        $cash = $this->createLedgerAccount($school, ['type' => 'asset']);
        $income = $this->createLedgerAccount($school, ['type' => 'income']);

        $this->expectException(InvalidJournalEntryException::class);

        $this->postJournal($school, [
            new JournalLineData($cash->id, JournalSide::Debit, Money::of('1234567890123.00', 'INR')),
            new JournalLineData($income->id, JournalSide::Credit, Money::of('1234567890123.00', 'INR')),
        ]);
    }

    #[Test]
    public function an_unsupported_currency_is_rejected(): void
    {
        $school = $this->createSchool();
        $cash = $this->createLedgerAccount($school, ['type' => 'asset']);
        $income = $this->createLedgerAccount($school, ['type' => 'income']);

        $this->expectException(InvalidJournalCurrencyException::class);

        $this->postJournal($school, [
            new JournalLineData($cash->id, JournalSide::Debit, Money::of('10.00', 'USD')),
            new JournalLineData($income->id, JournalSide::Credit, Money::of('10.00', 'USD')),
        ], currency: 'USD');
    }

    #[Test]
    public function a_line_currency_that_does_not_match_the_entrys_currency_is_rejected(): void
    {
        $school = $this->createSchool();
        $cash = $this->createLedgerAccount($school, ['type' => 'asset']);
        $income = $this->createLedgerAccount($school, ['type' => 'income']);

        $this->expectException(InvalidJournalCurrencyException::class);

        $this->postJournal($school, [
            new JournalLineData($cash->id, JournalSide::Debit, Money::of('10.00', 'USD')),
            new JournalLineData($income->id, JournalSide::Credit, Money::of('10.00', 'INR')),
        ]);
    }

    #[Test]
    public function an_unbalanced_post_is_rejected_with_the_domain_exception(): void
    {
        $school = $this->createSchool();
        $cash = $this->createLedgerAccount($school, ['type' => 'asset']);
        $income = $this->createLedgerAccount($school, ['type' => 'income']);

        $this->expectException(UnbalancedJournalEntryException::class);

        $this->postJournal($school, [
            new JournalLineData($cash->id, JournalSide::Debit, Money::of('100.00', 'INR')),
            new JournalLineData($income->id, JournalSide::Credit, Money::of('80.00', 'INR')),
        ]);
    }

    #[Test]
    public function a_missing_ledger_account_is_rejected(): void
    {
        $school = $this->createSchool();
        $income = $this->createLedgerAccount($school, ['type' => 'income']);

        $this->expectException(LedgerAccountNotFoundException::class);

        $this->postJournal($school, [
            new JournalLineData((string) new UuidV7, JournalSide::Debit, Money::of('10.00', 'INR')),
            new JournalLineData($income->id, JournalSide::Credit, Money::of('10.00', 'INR')),
        ]);
    }

    /**
     * A cross-School account must fail EXACTLY like a nonexistent one
     * -- same exception class, same message shape -- so a caller
     * cannot distinguish "this id doesn't exist" from "this id exists
     * in a different School" (no oracle).
     */
    #[Test]
    public function a_cross_school_ledger_account_is_rejected_identically_to_a_missing_one(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $incomeA = $this->createLedgerAccount($schoolA, ['type' => 'income']);
        $cashB = $this->createLedgerAccount($schoolB, ['type' => 'asset']);

        try {
            $this->postJournal($schoolA, [
                new JournalLineData($cashB->id, JournalSide::Debit, Money::of('10.00', 'INR')),
                new JournalLineData($incomeA->id, JournalSide::Credit, Money::of('10.00', 'INR')),
            ]);
            $this->fail('Expected LedgerAccountNotFoundException.');
        } catch (LedgerAccountNotFoundException $e) {
            $this->assertStringContainsString($cashB->id, $e->getMessage());
        }
    }

    #[Test]
    public function an_over_length_description_is_rejected(): void
    {
        $school = $this->createSchool();
        $cash = $this->createLedgerAccount($school, ['type' => 'asset']);
        $income = $this->createLedgerAccount($school, ['type' => 'income']);

        $this->expectException(InvalidJournalEntryException::class);

        $this->postJournal($school, [
            new JournalLineData($cash->id, JournalSide::Debit, Money::of('10.00', 'INR')),
            new JournalLineData($income->id, JournalSide::Credit, Money::of('10.00', 'INR')),
        ], description: str_repeat('x', 256));
    }

    /**
     * Closure review section 10-15: `post()` must never expose
     * `posting_txid` (internal PostgreSQL transaction-identity
     * metadata) through its public result. Proven two ways: (1)
     * `JournalEntryResult` has no `posting_txid` property at all,
     * structurally (reflection); (2) separately, querying the actual
     * persisted row directly (bypassing the service's result
     * entirely, exactly like 0G.1's own hardening tests) confirms the
     * database-computed value is still correct -- this is NOT
     * reachable through the result, only through a deliberate,
     * separate, direct query, which is exactly the point.
     */
    #[Test]
    public function posting_txid_is_structurally_absent_from_the_result_but_still_database_correct(): void
    {
        $reflection = new ReflectionClass(JournalEntryResult::class);
        $propertyNames = array_map(fn ($p) => $p->getName(), $reflection->getProperties());
        $this->assertNotContains('posting_txid', $propertyNames);
        $this->assertNotContains('postingTxid', $propertyNames);

        $school = $this->createSchool();
        $cash = $this->createLedgerAccount($school, ['type' => 'asset']);
        $income = $this->createLedgerAccount($school, ['type' => 'income']);

        $result = $this->postJournal($school, [
            new JournalLineData($cash->id, JournalSide::Debit, Money::of('10.00', 'INR')),
            new JournalLineData($income->id, JournalSide::Credit, Money::of('10.00', 'INR')),
        ]);

        // Deliberately a SEPARATE, direct query -- not something
        // reachable from $result itself.
        $entry = app(TenantContext::class)->withSchool($school, fn () => JournalEntry::query()->findOrFail($result->journalEntryId));
        $this->assertNotEmpty($entry->posting_txid);
    }

    #[Test]
    public function post_returns_a_typed_result_never_the_raw_eloquent_model(): void
    {
        $school = $this->createSchool();
        $cash = $this->createLedgerAccount($school, ['type' => 'asset']);
        $income = $this->createLedgerAccount($school, ['type' => 'income']);

        $result = $this->postJournal($school, [
            new JournalLineData($cash->id, JournalSide::Debit, Money::of('10.00', 'INR')),
            new JournalLineData($income->id, JournalSide::Credit, Money::of('10.00', 'INR')),
        ]);

        $this->assertInstanceOf(JournalEntryResult::class, $result);
        $this->assertNotInstanceOf(JournalEntry::class, $result);
    }

    #[Test]
    public function the_result_exposes_the_expected_safe_business_fields(): void
    {
        $school = $this->createSchool();
        $cash = $this->createLedgerAccount($school, ['type' => 'asset']);
        $income = $this->createLedgerAccount($school, ['type' => 'income']);

        $result = $this->postJournal($school, [
            new JournalLineData($cash->id, JournalSide::Debit, Money::of('10.00', 'INR')),
            new JournalLineData($income->id, JournalSide::Credit, Money::of('10.00', 'INR')),
        ], description: 'A safe result field test');

        $this->assertSame($school->id, $result->schoolId);
        $this->assertSame('INR', $result->currency);
        $this->assertSame('A safe result field test', $result->description);
        $this->assertNotNull($result->postedAt);
        $this->assertNull($result->reversalOfJournalEntryId, 'A fresh post() result must not reference any reversal.');
        $this->assertSame(2, $result->lineCount);
    }

    #[Test]
    public function every_row_belongs_to_the_posting_school_and_is_inr(): void
    {
        $school = $this->createSchool();
        $cash = $this->createLedgerAccount($school, ['type' => 'asset']);
        $income = $this->createLedgerAccount($school, ['type' => 'income']);

        $result = $this->postJournal($school, [
            new JournalLineData($cash->id, JournalSide::Debit, Money::of('10.00', 'INR')),
            new JournalLineData($income->id, JournalSide::Credit, Money::of('10.00', 'INR')),
        ]);

        $this->assertSame($school->id, $result->schoolId);
        $this->assertSame('INR', $result->currency);

        foreach ($this->linesFor($school, $result->journalEntryId) as $line) {
            $this->assertSame($school->id, $line->school_id);
            $this->assertSame('INR', $line->currency);
        }
    }

    /**
     * Reconfirms exact success cardinality (closure review section 9):
     * a successful post() produces exactly 1 JournalEntry, N
     * JournalLines, 1 post audit event, 1 post outbox event -- no
     * more, no fewer.
     */
    #[Test]
    public function posting_is_transactional_and_produces_a_durable_outbox_event(): void
    {
        $school = $this->createSchool();
        $cash = $this->createLedgerAccount($school, ['type' => 'asset']);
        $income = $this->createLedgerAccount($school, ['type' => 'income']);

        $result = $this->postJournal($school, [
            new JournalLineData($cash->id, JournalSide::Debit, Money::of('10.00', 'INR')),
            new JournalLineData($income->id, JournalSide::Credit, Money::of('10.00', 'INR')),
        ]);

        $counts = app(TenantContext::class)->withSchool($school, fn () => [
            JournalEntry::query()->count(),
            JournalLine::query()->count(),
        ]);
        $this->assertSame([1, 2], $counts, 'Exactly one entry and N (=2) lines must exist.');

        $event = app(TenantContext::class)->withSchool(
            $school,
            fn () => DomainEventOutbox::query()->where('school_id', $school->id)->where('event_type', 'journal_entry.posted.v1')->get(),
        );

        $this->assertCount(1, $event, 'Exactly one outbox event must exist for a successful post.');
        $this->assertSame($result->journalEntryId, $event[0]->payload['journalEntryId']);
        $this->assertSame('pending', $event[0]->status);

        $auditCount = app(TenantContext::class)->withSchool(
            $school,
            fn () => SchoolAuditEvent::query()->where('school_id', $school->id)->where('event_type', 'journal_entry.posted')->count(),
        );
        $this->assertSame(1, $auditCount, 'Exactly one audit event must exist for a successful post.');
    }

    /**
     * Mirrors AcademicYearLifecycleTest::a_rolled_back_activation_leaves_neither_the_state_change_nor_the_event()'s
     * established pattern: wrapping the service call in an OUTER
     * transaction that fails afterward forces Laravel's savepoint
     * nesting to roll back everything the service did. This proves
     * TRANSACTION PARTICIPATION; the stronger claim -- that a failure
     * INSIDE one specific write step rolls back the others -- is
     * proven separately and directly by
     * LedgerServiceAtomicityTest's real failure-injection tests.
     */
    #[Test]
    public function a_rolled_back_posting_leaves_no_entry_no_lines_and_no_event(): void
    {
        $school = $this->createSchool();
        $cash = $this->createLedgerAccount($school, ['type' => 'asset']);
        $income = $this->createLedgerAccount($school, ['type' => 'income']);

        try {
            DB::transaction(function () use ($school, $cash, $income): void {
                $this->postJournal($school, [
                    new JournalLineData($cash->id, JournalSide::Debit, Money::of('10.00', 'INR')),
                    new JournalLineData($income->id, JournalSide::Credit, Money::of('10.00', 'INR')),
                ]);
                throw new RuntimeException('Simulated failure after posting.');
            });
            $this->fail('Expected the RuntimeException to propagate.');
        } catch (RuntimeException) {
            // expected
        }

        $counts = app(TenantContext::class)->withSchool($school, fn () => [
            JournalEntry::query()->count(),
            JournalLine::query()->count(),
            DomainEventOutbox::query()->where('school_id', $school->id)->where('event_type', 'journal_entry.posted.v1')->count(),
            SchoolAuditEvent::query()->where('school_id', $school->id)->where('event_type', 'journal_entry.posted')->count(),
        ]);

        $this->assertSame([0, 0, 0, 0], $counts, 'A rolled-back posting must leave no entry, no lines, no outbox event, and no audit event.');
    }

    #[Test]
    public function invalid_input_creates_nothing(): void
    {
        $school = $this->createSchool();
        $cash = $this->createLedgerAccount($school, ['type' => 'asset']);

        try {
            $this->postJournal($school, [
                new JournalLineData($cash->id, JournalSide::Debit, Money::of('10.00', 'INR')),
            ]);
        } catch (InvalidJournalEntryException) {
            // expected
        }

        $counts = app(TenantContext::class)->withSchool($school, fn () => [
            JournalEntry::query()->count(),
            JournalLine::query()->count(),
        ]);
        $this->assertSame([0, 0], $counts);
    }

    #[Test]
    public function a_missing_account_creates_nothing(): void
    {
        $school = $this->createSchool();
        $income = $this->createLedgerAccount($school, ['type' => 'income']);

        try {
            $this->postJournal($school, [
                new JournalLineData((string) new UuidV7, JournalSide::Debit, Money::of('10.00', 'INR')),
                new JournalLineData($income->id, JournalSide::Credit, Money::of('10.00', 'INR')),
            ]);
        } catch (LedgerAccountNotFoundException) {
            // expected
        }

        $counts = app(TenantContext::class)->withSchool($school, fn () => [
            JournalEntry::query()->count(),
            JournalLine::query()->count(),
        ]);
        $this->assertSame([0, 0], $counts);
    }

    /**
     * Account resolution must be exactly ONE query against
     * ledger_accounts, regardless of line count (section 38/62 --
     * avoid one lookup per line). Proven directly by counting how many
     * logged queries reference `ledger_accounts` for a 2-line vs. a
     * 100-line post -- both must be exactly 1, never N.
     */
    #[Test]
    public function account_resolution_is_exactly_one_query_regardless_of_line_count(): void
    {
        $school = $this->createSchool();
        $cash = $this->createLedgerAccount($school, ['type' => 'asset']);
        $income = $this->createLedgerAccount($school, ['type' => 'income']);

        $buildLines = function (int $pairCount) use ($cash, $income) {
            $lines = [];
            for ($i = 0; $i < $pairCount; $i++) {
                $lines[] = new JournalLineData($cash->id, JournalSide::Debit, Money::of('1.00', 'INR'));
                $lines[] = new JournalLineData($income->id, JournalSide::Credit, Money::of('1.00', 'INR'));
            }

            return $lines;
        };

        $accountQueryCountFor = function (int $pairCount) use ($school, $buildLines) {
            DB::connection('pgsql')->flushQueryLog();
            DB::connection('pgsql')->enableQueryLog();
            $this->postJournal($school, $buildLines($pairCount));
            $log = DB::connection('pgsql')->getQueryLog();
            DB::connection('pgsql')->disableQueryLog();

            return count(array_filter($log, fn ($q) => str_contains($q['query'], 'ledger_accounts')));
        };

        $this->assertSame(1, $accountQueryCountFor(1), '2 lines (1 pair) must resolve accounts in exactly one query.');
        $this->assertSame(1, $accountQueryCountFor(10), '20 lines (10 pairs) must resolve accounts in exactly one query.');
        $this->assertSame(1, $accountQueryCountFor(50), '100 lines (50 pairs) must resolve accounts in exactly one query.');
    }
}
