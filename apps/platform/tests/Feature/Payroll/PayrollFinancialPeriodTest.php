<?php

namespace Tests\Feature\Payroll;

use App\Domain\Finance\Application\Exceptions\FinancialPeriodClosedException;
use App\Domain\Payroll\Application\PayrollPostingService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Finance\Concerns\CreatesFinancialPeriodFixtures;
use Tests\TestCase;

/**
 * E21.3A (ADR 0064 §9): Payroll has no period of its own; its journal
 * entries carry Finance's financial periods. A payroll posting is in the
 * closed year's carried balances, a later reversal posts in the open year,
 * and nothing can be posted into a closed year.
 */
class PayrollFinancialPeriodTest extends TestCase
{
    use CreatesFinancialPeriodFixtures;

    #[Test]
    public function payroll_postings_carry_finance_periods_across_a_close(): void
    {
        $w = $this->twoYearWorld();
        $run = $this->approvedPayrollRun($w['school'], '2025-09-01');
        $poster = $this->createUser();

        $this->travelTo(Carbon::parse('2025-10-01 06:00:00', 'UTC'));
        $posting = app(PayrollPostingService::class)->post($this->inSchool($w['school'], fn () => $run->fresh()), $poster);
        $this->travelBack();
        $this->assertSame('2025-26', $this->entryPeriodKey($w['school'], $posting->journal_entry_id));

        $before = $this->financeReadings($w);
        $this->closePeriod($w, $w['fy2526']);
        $this->assertSame($before, $this->financeReadings($w), 'Payroll expense and payable balances are unchanged by the close.');

        $expense = $this->inSchool($w['school'], fn () => DB::table('journal_lines')->where('journal_entry_id', $posting->journal_entry_id)->whereNotNull('debit_amount')->value('ledger_account_id'));
        $this->assertSame('50000.00', $this->inSchool($w['school'], fn () => DB::table('financial_period_account_balances')->where('financial_period_id', $w['fy2526']->id)->where('ledger_account_id', $expense)->value('debit_total')));

        $reversal = app(PayrollPostingService::class)->reverse($this->inSchool($w['school'], fn () => $run->fresh()), $poster, 'wrong month');
        $this->assertSame('2026-27', $this->entryPeriodKey($w['school'], $reversal->journal_entry_id), 'The payroll reversal posts in the open year.');
        $this->assertSame([], $this->verifyBalances($w['school']));
    }

    #[Test]
    public function a_payroll_posting_dated_in_a_closed_year_is_refused(): void
    {
        $w = $this->twoYearWorld();
        $this->closePeriod($w, $w['fy2526']);
        $run = $this->approvedPayrollRun($w['school'], '2026-03-01');

        $this->travelTo(Carbon::parse('2026-03-25 06:00:00', 'UTC'));
        try {
            app(PayrollPostingService::class)->post($this->inSchool($w['school'], fn () => $run->fresh()), $this->createUser());
            $this->fail('Nothing posts into a closed financial year.');
        } catch (FinancialPeriodClosedException) {
            $this->assertSame('approved', $this->inSchool($w['school'], fn () => $run->fresh())->status, 'The run stays approved; nothing was posted.');
        } finally {
            $this->travelBack();
        }
    }
}
