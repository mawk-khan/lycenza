<?php

namespace Tests\Feature\App;

use App\Domain\Finance\Application\LedgerService;
use App\Domain\Finance\Application\Periods\FinancialPeriodService;
use App\Domain\Finance\Application\Periods\FinancialPeriodSummary;
use App\Domain\Finance\Infrastructure\LedgerAccount;
use App\Models\School;
use App\Models\SchoolAuditEvent;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Finance\Concerns\CreatesFinancialPeriodFixtures;
use Tests\TestCase;

/**
 * ORG.9B (ADR 0076 §68), 9B-3: a ledger account's statement -- opening
 * balance before the range, every POSTED line in (posted_at, entry, line)
 * order with a running balance computed over the whole range, closing
 * balance -- for School-local posting dates, read-only, finance.ledger.view.
 */
class LedgerAccountStatementTest extends TestCase
{
    use CreatesFinancialPeriodFixtures;

    /** @return array{school: School, cash: LedgerAccount, bank: LedgerAccount, income: LedgerAccount, viewer: User} */
    private function world(): array
    {
        $school = $this->createSchool(['timezone' => 'Asia/Kolkata']);
        $w = [
            'school' => $school,
            'cash' => $this->createLedgerAccount($school, ['code' => 'CASH', 'type' => 'asset']),
            'bank' => $this->createLedgerAccount($school, ['code' => 'BANK', 'type' => 'asset']),
            'income' => $this->createLedgerAccount($school, ['code' => 'FEES', 'type' => 'income']),
        ];
        $w['viewer'] = $this->createUserWithCapabilities($school, ['finance.ledger.view']);

        return $w;
    }

    private function at(string $utc): void
    {
        $this->travelTo(CarbonImmutable::parse($utc));
    }

    /** @return array<string, mixed> */
    private function statement(array $w, LedgerAccount $account, string $query = ''): array
    {
        $this->actingAs($w['viewer'])->post("/app/schools/{$w['school']->id}/activate");

        return $this->get("/app/finance/ledger-accounts/{$account->id}/ledger".($query === '' ? '' : '?'.$query))->assertOk()->viewData('page')['props'];
    }

    /** @return list<array{0: string, 1: string, 2: string}> debit, credit, running balance */
    private function lines(array $props): array
    {
        return array_map(fn ($l) => [$l['debit'], $l['credit'], $l['balance']], $props['lines']['data']);
    }

    #[Test]
    public function no_activity_is_a_zero_statement_not_an_error(): void
    {
        $w = $this->world();
        $this->at('2026-07-10T06:00:00Z');
        $props = $this->statement($w, $w['cash'], 'from=2026-07-01&to=2026-07-10');

        $this->assertSame(['0.00', '0.00', '0.00', '0.00'], [$props['opening'], $props['totalDebit'], $props['totalCredit'], $props['closing']]);
        $this->assertSame([], $props['lines']['data']);
        $this->assertSame('debit', $props['account']['normalSide']);
    }

    #[Test]
    public function opening_running_and_closing_balances_follow_posting_order(): void
    {
        $w = $this->world();
        $this->at('2026-06-20T06:00:00Z');
        $this->postBalancedJournalEntry($w['school'], $w['cash'], $w['income'], '500.00'); // before the range
        $this->at('2026-07-02T06:00:00Z');
        $this->postBalancedJournalEntry($w['school'], $w['cash'], $w['income'], '100.00');
        $this->postBalancedJournalEntry($w['school'], $w['bank'], $w['cash'], '30.00'); // same instant: entry id breaks the tie
        $this->at('2026-07-05T06:00:00Z');
        $refund = $this->postBalancedJournalEntry($w['school'], $w['cash'], $w['income'], '20.00');
        app(LedgerService::class)->reverseById($w['school'], $refund->id, null, 'Posted in error');
        $this->at('2026-07-20T06:00:00Z');
        $this->postBalancedJournalEntry($w['school'], $w['cash'], $w['income'], '999.00'); // after the range

        $props = $this->statement($w, $w['cash'], 'from=2026-07-01&to=2026-07-10');
        $this->assertSame('500.00', $props['opening']);
        $this->assertSame([
            ['100.00', '0.00', '600.00'],
            ['0.00', '30.00', '570.00'],
            ['20.00', '0.00', '590.00'],
            ['0.00', '20.00', '570.00'],
        ], $this->lines($props));
        $this->assertSame(['120.00', '50.00', '570.00'], [$props['totalDebit'], $props['totalCredit'], $props['closing']]);
        $this->assertSame([false, false, false, true], array_column($props['lines']['data'], 'isReversal'));

        $income = $this->statement($w, $w['income'], 'from=2026-07-01&to=2026-07-10');
        $this->assertSame(['-500.00', '-600.00', 'credit'], [$income['opening'], $income['closing'], $income['account']['normalSide']], 'Net debit: an income account carries a negative (credit) balance.');
    }

    #[Test]
    public function the_range_is_bounded_by_school_local_midnights(): void
    {
        $w = $this->world();
        $this->at('2026-07-01T18:29:59Z'); // 23:59:59 IST on 07-01
        $this->postBalancedJournalEntry($w['school'], $w['cash'], $w['income'], '10.00');
        $this->at('2026-07-01T18:30:00Z'); // 00:00:00 IST on 07-02
        $this->postBalancedJournalEntry($w['school'], $w['cash'], $w['income'], '20.00');
        $this->at('2026-07-03T06:00:00Z');

        $july1 = $this->statement($w, $w['cash'], 'from=2026-07-01&to=2026-07-01');
        $this->assertSame([['10.00', '0.00', '10.00']], $this->lines($july1));
        $july2 = $this->statement($w, $w['cash'], 'from=2026-07-02&to=2026-07-02');
        $this->assertSame(['10.00', '30.00'], [$july2['opening'], $july2['closing']]);
        $this->assertSame([['20.00', '0.00', '30.00']], $this->lines($july2));
    }

    #[Test]
    public function every_page_carries_the_balance_brought_forward(): void
    {
        $w = $this->world();
        $this->at('2026-07-02T06:00:00Z');
        for ($i = 1; $i <= 55; $i++) {
            $this->postBalancedJournalEntry($w['school'], $w['cash'], $w['income'], '1.00');
        }

        $first = $this->statement($w, $w['cash'], 'from=2026-07-01&to=2026-07-02');
        $this->assertCount(50, $first['lines']['data']);
        $this->assertSame('50.00', $first['lines']['data'][49]['balance']);
        $second = $this->statement($w, $w['cash'], 'from=2026-07-01&to=2026-07-02&page=2');
        $this->assertSame(['51.00', '52.00', '53.00', '54.00', '55.00'], array_column($second['lines']['data'], 'balance'));
        $this->assertSame(['55.00', '55.00'], [$second['totalDebit'], $second['closing']], 'Range totals, never one page.');
    }

    #[Test]
    public function the_opening_balance_is_the_same_before_and_after_a_period_close(): void
    {
        $w = $this->twoYearWorld();
        $this->travelTo(CarbonImmutable::parse('2026-06-01T06:00:00Z'));
        $viewer = $this->createUserWithCapabilities($w['school'], ['finance.ledger.view']);
        $read = function () use ($w, $viewer) {
            $this->actingAs($viewer)->post("/app/schools/{$w['school']->id}/activate");
            $props = $this->get("/app/finance/ledger-accounts/{$w['receivable']->id}/ledger?from=2026-04-01&to=2026-05-31")->assertOk()->viewData('page')['props'];

            return [$props['opening'], $props['closing'], $this->lines($props)];
        };

        $before = $read();
        $this->closePeriod($w, $w['fy2526']);
        $this->assertSame($before, $read(), 'The closed year\'s baseline gives exactly the opening its detail gave.');
        $this->assertSame('1650.00', $before[0], 'Receivable on 2026-04-01: 2300 billed - 300 concessions - 350 paid.');
    }

    #[Test]
    public function a_range_before_the_retained_history_is_refused(): void
    {
        $w = $this->world();
        $this->at('2026-07-10T06:00:00Z');
        $periods = Mockery::mock(FinancialPeriodService::class, [app(TenantContext::class)])->makePartial();
        $periods->shouldReceive('expiryAnchor')->andReturn(new FinancialPeriodSummary('p', '2025-26', '2025-04-01', '2026-03-31', 'closed', null, null, null));
        $this->app->instance(FinancialPeriodService::class, $periods);

        $this->actingAs($w['viewer'])->post("/app/schools/{$w['school']->id}/activate");
        $this->get("/app/finance/ledger-accounts/{$w['cash']->id}/ledger?from=2026-03-31&to=2026-07-01")
            ->assertSessionHasErrors(['from' => 'Ledger detail before 2026-04-01 is no longer retained; start on that date or later.']);
        $this->statement($w, $w['cash'], 'from=2026-04-01&to=2026-07-01');
    }

    #[Test]
    public function the_range_is_validated(): void
    {
        $w = $this->world();
        $this->at('2026-07-10T06:00:00Z');
        $this->actingAs($w['viewer'])->post("/app/schools/{$w['school']->id}/activate");
        $base = "/app/finance/ledger-accounts/{$w['cash']->id}/ledger";

        $this->get("{$base}?from=2026-07-05&to=2026-07-01")->assertSessionHasErrors('from');
        $this->get("{$base}?to=2026-07-11")->assertSessionHasErrors('to');
        $this->get("{$base}?from=01-07-2026")->assertSessionHasErrors('from');
        $defaults = $this->statement($w, $w['cash']);
        $this->assertSame(['from' => '2026-07-01', 'to' => '2026-07-10'], $defaults['filters'], 'Default: this School-local month to today.');
    }

    #[Test]
    public function it_needs_ledger_view_and_never_reads_another_schools_account(): void
    {
        $w = $this->world();
        $other = $this->world();
        $this->at('2026-07-10T06:00:00Z');
        $this->postBalancedJournalEntry($other['school'], $other['cash'], $other['income'], '75.00');

        $this->actingAs($w['viewer'])->post("/app/schools/{$w['school']->id}/activate");
        $this->get("/app/finance/ledger-accounts/{$other['cash']->id}/ledger")->assertNotFound();
        $this->get('/app/finance/ledger-accounts/not-a-uuid/ledger')->assertNotFound();

        $outsider = $this->createUserWithCapabilities($w['school'], ['finance.charges.view']);
        $this->actingAs($outsider)->post("/app/schools/{$w['school']->id}/activate");
        $this->get("/app/finance/ledger-accounts/{$w['cash']->id}/ledger")->assertForbidden();
    }

    #[Test]
    public function each_read_is_audited_once_and_writes_nothing_else(): void
    {
        $w = $this->world();
        $this->at('2026-07-10T06:00:00Z');
        $this->postBalancedJournalEntry($w['school'], $w['cash'], $w['income'], '75.00');
        $count = fn (string $table) => $this->inSchool($w['school'], fn () => DB::table($table)->count());
        [$entries, $lines] = [$count('journal_entries'), $count('journal_lines')];

        $this->statement($w, $w['cash'], 'from=2026-07-01&to=2026-07-10');

        $event = $this->inSchool($w['school'], fn () => SchoolAuditEvent::query()->where('event_type', 'ledger_account.statement_viewed')->sole());
        $this->assertEqualsCanonicalizing(['ledgerAccountId' => $w['cash']->id, 'from' => '2026-07-01', 'to' => '2026-07-10', 'resultCount' => 1], $event->metadata);
        $this->assertSame([$entries, $lines], [$count('journal_entries'), $count('journal_lines')], 'A read posts nothing.');
    }
}
