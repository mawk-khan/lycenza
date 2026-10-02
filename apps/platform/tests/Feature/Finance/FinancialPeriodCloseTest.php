<?php

namespace Tests\Feature\Finance;

use App\Domain\Fees\Application\ChargeService;
use App\Domain\Finance\Application\Exceptions\FinancialPeriodClosedException;
use App\Domain\Finance\Application\Exceptions\FinancialPeriodCloseRefusedException;
use App\Domain\Finance\Application\Exceptions\FinancialPeriodNotFoundException;
use App\Domain\Finance\Application\Exceptions\FinancialPeriodVerificationFailedException;
use App\Domain\Finance\Application\LedgerService;
use App\Domain\Finance\Application\Periods\FinancialPeriodCloseParticipant;
use App\Domain\Finance\Application\Periods\FinancialPeriodCloseService;
use App\Domain\Finance\Application\Periods\FinancialPeriodSummary;
use App\Domain\Finance\Application\Periods\JournalPeriodIndex;
use App\Domain\Finance\Infrastructure\FinancialPeriod;
use App\Domain\Finance\Infrastructure\JournalEntry;
use App\Domain\Payments\Infrastructure\FinancialPeriodChargeState;
use App\Models\School;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Finance\Concerns\CreatesFinancialPeriodFixtures;
use Tests\TestCase;

/**
 * E21.3A (ADR 0064 §4-§6): closing a financial period carries every
 * balance forward, changes no reading, refuses deterministically, is
 * authorized, audited and permanent, and later postings and corrections
 * land in the open period.
 */
class FinancialPeriodCloseTest extends TestCase
{
    use CreatesFinancialPeriodFixtures;

    #[Test]
    public function closing_carries_every_balance_forward_and_changes_no_reading(): void
    {
        $w = $this->twoYearWorld();
        $before = $this->financeReadings($w);

        $closed = $this->closePeriod($w, $w['fy2526']);

        $this->assertSame('closed', $closed->status);
        $this->assertSame($w['closer']->id, $closed->closedByUserId);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string) $closed->fingerprint);
        $this->assertSame($before, $this->financeReadings($w), 'Closing changes no account balance, charge outstanding or Student statement.');
        $this->assertSame([], $this->verifyBalances($w['school']));
        $this->assertSame(1, $this->auditCount($w['school'], 'financial_period.closed'));
        $this->assertSame('open', $this->periodByKey($w['school'], '2026-27')->status);

        // Account baselines equal the lines of 2025-26 exactly (the first close).
        $expected = $this->inSchool($w['school'], fn () => collect(DB::select(
            'SELECT l.ledger_account_id, sum(coalesce(l.debit_amount, 0))::numeric(20,2) AS d, sum(coalesce(l.credit_amount, 0))::numeric(20,2) AS c
               FROM journal_lines l JOIN journal_entries e ON e.id = l.journal_entry_id
              WHERE e.school_id = ? AND e.financial_period_id = ? GROUP BY 1 ORDER BY 1',
            [$w['school']->id, $closed->id],
        ))->map(fn ($r) => "{$r->ledger_account_id}|{$r->d}|{$r->c}")->all());
        $baseline = $this->inSchool($w['school'], fn () => DB::table('financial_period_account_balances')
            ->where('financial_period_id', $closed->id)->orderBy('ledger_account_id')->get()
            ->map(fn ($r) => "{$r->ledger_account_id}|{$r->debit_total}|{$r->credit_total}")->all());
        $this->assertSame($expected, $baseline);

        // Charge states as of 2025-26: the 2026-27 payment and c4 are not in them.
        $states = $this->inSchool($w['school'], fn () => FinancialPeriodChargeState::query()->where('financial_period_id', $closed->id)->get()->keyBy('charge_id'));
        $this->assertEqualsCanonicalizing([$w['c1']->id, $w['c2']->id, $w['c3']->id], $states->keys()->all());
        $this->assertSame(['1000.00', '300.00', '200.00', false], [$states[$w['c1']->id]->amount, $states[$w['c1']->id]->allocated_total, $states[$w['c1']->id]->live_adjusted_total, $states[$w['c1']->id]->cancelled]);
        $this->assertSame(['800.00', '50.00', '100.00'], [$states[$w['c3']->id]->amount, $states[$w['c3']->id]->allocated_total, $states[$w['c3']->id]->live_adjusted_total]);
        $this->assertSame(['0.00', '0.00'], [$states[$w['c2']->id]->allocated_total, $states[$w['c2']->id]->live_adjusted_total]);
    }

    #[Test]
    public function corrections_after_the_close_post_in_the_open_period_and_still_reconcile(): void
    {
        $w = $this->twoYearWorld();
        $this->closePeriod($w, $w['fy2526']);

        // A charge, an adjustment and a journal entry of the closed year are
        // corrected now: each correction is a new posting in 2026-27, linked
        // to its original; the originals keep their closed period.
        app(ChargeService::class)->cancel($w['school'], $w['c2']->id, $w['actor'], 'billed twice');
        $this->concessions()->cancelAdjustment($w['school'], $w['a3']->id, $w['checker'], 'entered twice');
        $reversal = app(LedgerService::class)->reverseById($w['school'], $w['manual']->id, null, 'wrong account');
        $this->pay($w, '10.00', null, $w['c1']);

        $this->assertSame('2026-27', $this->entryPeriodKey($w['school'], $reversal->journalEntryId));
        $this->assertSame($w['manual']->id, $this->inSchool($w['school'], fn () => JournalEntry::query()->whereKey($reversal->journalEntryId)->value('reversal_of_journal_entry_id')));
        $this->assertSame('2025-26', $this->entryPeriodKey($w['school'], $w['manual']->id));
        $cancellation = $this->inSchool($w['school'], fn () => DB::table('charges')->where('id', $w['c2']->id)->value('cancellation_journal_entry_id'));
        $this->assertSame('2026-27', $this->entryPeriodKey($w['school'], $cancellation));

        $this->assertSame([], $this->verifyBalances($w['school']), 'Baseline + later detail still equals the full history after corrections.');
        $readings = $this->financeReadings($w);
        $this->assertSame('cancelled', $readings['outstanding'][$w['c2']->id]);
        $this->assertSame('750.00', $readings['outstanding'][$w['c3']->id], '800 - 50 paid; the voided 100 adjustment no longer counts.');
        $this->assertSame('390.00', $readings['outstanding'][$w['c1']->id], '1000 - 300 - 100 - 10 paid - 200 adjusted.');
    }

    #[Test]
    public function the_next_close_is_cumulative(): void
    {
        $w = $this->twoYearWorld();
        $this->closePeriod($w, $w['fy2526']);

        // Close 2026-27 too, as of a day after it ended.
        $this->travelTo(Carbon::parse('2027-04-02 06:00:00', 'UTC'));
        $before = $this->financeReadings($w);
        $this->closePeriod($w, $w['fy2627']);
        $this->assertSame($before, $this->financeReadings($w));
        $this->assertSame([], $this->verifyBalances($w['school']));

        $cumulative = $this->inSchool($w['school'], fn () => DB::table('financial_period_account_balances')->where('financial_period_id', $w['fy2627']->id)
            ->selectRaw('sum(debit_total) AS d, sum(credit_total) AS c')->first());
        $all = $this->inSchool($w['school'], fn () => DB::table('journal_lines')->where('school_id', $w['school']->id)
            ->selectRaw('sum(coalesce(debit_amount, 0)) AS d, sum(coalesce(credit_amount, 0)) AS c')->first());
        $this->assertSame([$all->d, $all->c], [$cumulative->d, $cumulative->c], 'The second baseline holds the cumulative totals of both years.');
        $state = $this->inSchool($w['school'], fn () => FinancialPeriodChargeState::query()->where('financial_period_id', $w['fy2627']->id)->where('charge_id', $w['c1']->id)->sole());
        $this->assertSame('400.00', $state->allocated_total);
        $this->travelBack();
    }

    #[Test]
    public function a_posting_into_a_closed_period_is_refused_by_the_service_and_by_the_database(): void
    {
        $w = $this->twoYearWorld();
        $this->closePeriod($w, $w['fy2526']);

        $this->travelTo(Carbon::parse('2026-02-01 06:00:00', 'UTC'));
        try {
            $this->postBalancedJournalEntry($w['school'], $w['settlement'], $w['revenue'], '5.00');
            $this->fail('A posting dated inside a closed period must be refused.');
        } catch (FinancialPeriodClosedException $e) {
            $this->assertSame('FINANCIAL_PERIOD_CLOSED', $e->errorCode());
        }
        $this->travelBack();

        foreach ([
            'by date' => ['posted_at' => '2025-07-01 06:00:00'],
            'by explicit period' => ['posted_at' => now()->toDateTimeString(), 'financial_period_id' => $w['fy2526']->id],
        ] as $case => $columns) {
            try {
                $this->inSchool($w['school'], fn () => DB::transaction(fn () => DB::table('journal_entries')->insert(array_merge([
                    'id' => (string) Str::uuid7(), 'school_id' => $w['school']->id, 'currency' => 'INR', 'description' => 'raw',
                ], $columns))));
                $this->fail("A raw insert into a closed period ({$case}) must be refused.");
            } catch (QueryException $e) {
                $this->assertMatchesRegularExpression('/financial_period_closed|outside its financial period/', $e->getMessage(), $case);
            }
        }
    }

    #[Test]
    public function the_close_refuses_deterministically_and_writes_nothing(): void
    {
        $w = $this->twoYearWorld();

        $this->assertRefused(['period_not_ended', 'earlier_period_open'], fn () => $this->closePeriod($w, $w['fy2627']));
        $this->assertRefused(['confirmation_mismatch'], fn () => $this->closePeriod($w, $w['fy2526'], '2026-27'));

        $this->assertSame(['period_not_ended', 'earlier_period_open'], app(FinancialPeriodCloseService::class)->evaluate($w['school'], $w['fy2627']->id, $w['closer'])->blockers);
        // (Unmapped entries blocking a close: FinancialPeriodBackfillTest, which commits.)

        $this->assertSame(0, $this->inSchool($w['school'], fn () => DB::table('financial_period_account_balances')->count() + DB::table('financial_period_charge_states')->count()));
        $this->assertSame(0, $this->auditCount($w['school'], 'financial_period.closed'));
        $this->assertSame('open', $this->periodByKey($w['school'], '2025-26')->status);

        $this->closePeriod($w, $w['fy2526']);
        $this->assertRefused(['period_already_closed'], fn () => $this->closePeriod($w, $w['fy2526']));

        $other = $this->createSchool();
        $this->expectException(FinancialPeriodNotFoundException::class);
        app(FinancialPeriodCloseService::class)->close($w['school'], $this->inSchool($other, fn () => FinancialPeriod::ensureContaining($other->id, 'UTC', now())), '2026-27', $w['closer']);
    }

    #[Test]
    public function every_earlier_period_must_be_closed_first(): void
    {
        $this->travelTo(Carbon::parse('2024-07-01 06:00:00', 'UTC'));
        $w = $this->concessionWorld();
        $this->travelTo(Carbon::parse('2025-07-01 06:00:00', 'UTC'));
        $this->postBalancedJournalEntry($w['school'], $w['receivable'], $w['revenue'], '10.00');
        $this->travelBack();
        $w['closer'] = $this->createUserWithCapabilities($w['school'], ['finance.ledger.view', 'finance.periods.manage']);

        $this->assertRefused(['earlier_period_open'], fn () => $this->closePeriod($w, $this->periodByKey($w['school'], '2025-26')));
        $this->closePeriod($w, $this->periodByKey($w['school'], '2024-25'));
        $this->closePeriod($w, $this->periodByKey($w['school'], '2025-26'));
        $this->assertSame([], $this->verifyBalances($w['school']));
    }

    #[Test]
    public function closing_needs_the_period_capability_and_viewing_needs_ledger_view(): void
    {
        $w = $this->twoYearWorld();
        $viewer = $this->createUserWithCapabilities($w['school'], ['finance.ledger.view', 'finance.ledger.post', 'finance.ledger.reverse']);

        try {
            app(FinancialPeriodCloseService::class)->close($w['school'], $w['fy2526']->id, '2025-26', $viewer);
            $this->fail('Ledger post/reverse is not period-close authority.');
        } catch (AuthorizationException) {
        }
        try {
            app(FinancialPeriodCloseService::class)->evaluate($w['school'], $w['fy2526']->id, $this->createUserWithCapabilities($w['school'], ['finance.charges.view']));
            $this->fail('Viewing a close needs finance.ledger.view.');
        } catch (AuthorizationException) {
        }
        $this->assertSame('open', $this->periodByKey($w['school'], '2025-26')->status);

        // The School Admin role holds it by default.
        $closed = app(FinancialPeriodCloseService::class)->close($w['school'], $w['fy2526']->id, '2025-26', $w['recorder']);
        $this->assertSame('closed', $closed->status);
    }

    #[Test]
    public function a_failed_dual_read_rolls_the_whole_close_back(): void
    {
        $w = $this->twoYearWorld();
        app()->instance('test.mismatching_participant', new class implements FinancialPeriodCloseParticipant
        {
            public function key(): string
            {
                return 'test';
            }

            public function blockers(School $school, FinancialPeriodSummary $period, JournalPeriodIndex $index): array
            {
                return [];
            }

            public function notices(School $school, FinancialPeriodSummary $period, JournalPeriodIndex $index): array
            {
                return [];
            }

            public function snapshot(School $school, FinancialPeriodSummary $period, JournalPeriodIndex $index): string
            {
                return hash('sha256', 'test');
            }

            public function verify(School $school, JournalPeriodIndex $index): array
            {
                return ['forced:mismatch'];
            }
        });
        app()->tag(['test.mismatching_participant'], FinancialPeriodCloseParticipant::TAG);

        try {
            app(FinancialPeriodCloseService::class)->close($w['school'], $w['fy2526']->id, '2025-26', $w['closer']);
            $this->fail('A dual-read mismatch must refuse the close.');
        } catch (FinancialPeriodVerificationFailedException $e) {
            $this->assertSame(['test:forced:mismatch'], $e->mismatches);
        }

        $this->assertSame('open', $this->periodByKey($w['school'], '2025-26')->status);
        $this->assertSame(0, $this->inSchool($w['school'], fn () => DB::table('financial_period_account_balances')->count() + DB::table('financial_period_charge_states')->count()));
        $this->assertSame(0, $this->auditCount($w['school'], 'financial_period.closed'));
    }

    #[Test]
    public function receipts_keep_their_series_across_the_close(): void
    {
        $w = $this->twoYearWorld();
        $before = $this->inSchool($w['school'], fn () => DB::table('payment_receipts')->orderBy('receipt_number')->pluck('receipt_number')->all());
        $this->closePeriod($w, $w['fy2526']);
        $this->assertSame($before, $this->inSchool($w['school'], fn () => DB::table('payment_receipts')->orderBy('receipt_number')->pluck('receipt_number')->all()));

        // Money received in March 2026, recorded after the close: the receipt
        // follows the payment date (series 2025-26, next number), the ledger
        // posting follows the posting date (open 2026-27).
        $late = $this->pay($w, '5.00', '2026-03-20', $w['c1']);
        $this->assertSame('RCPT/2025-26/000003', $this->receiptOf($w, $late->paymentId)->receipt_number);
        $entry = $this->inSchool($w['school'], fn () => DB::table('payments')->where('id', $late->paymentId)->value('journal_entry_id'));
        $this->assertSame('2026-27', $this->entryPeriodKey($w['school'], $entry));
        $this->assertSame([], $this->verifyBalances($w['school']));
        $this->assertContains('charges.receipt_series_differs_from_period', app(FinancialPeriodCloseService::class)->evaluate($w['school'], $w['fy2627']->id, $w['closer'])->notices);
    }

    #[Test]
    public function another_school_sees_none_of_the_periods_or_baselines(): void
    {
        $w = $this->twoYearWorld();
        $this->closePeriod($w, $w['fy2526']);
        $other = $this->createSchool();

        $this->inSchool($other, function () {
            $this->assertSame(0, FinancialPeriod::query()->count());
            $this->assertSame(0, FinancialPeriodChargeState::query()->count());
            $this->assertSame(0, DB::table('financial_period_account_balances')->count());
        });
    }

    /** @param  list<string>  $reasons */
    private function assertRefused(array $reasons, callable $close): void
    {
        try {
            $close();
            $this->fail('The close must be refused: '.implode(', ', $reasons));
        } catch (FinancialPeriodCloseRefusedException $e) {
            $this->assertSame($reasons, $e->reasons);
        }
    }
}
