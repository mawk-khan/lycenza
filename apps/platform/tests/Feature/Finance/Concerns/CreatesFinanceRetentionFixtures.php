<?php

namespace Tests\Feature\Finance\Concerns;

use App\Domain\Fees\Application\ChargeService;
use App\Domain\Finance\Application\LedgerBalanceReader;
use App\Domain\Finance\Application\LedgerService;
use App\Domain\Payments\Application\Charges\ChargeStateReader;
use App\Domain\Payments\Infrastructure\PaymentReceiptCounter;
use App\Domain\Payroll\Application\PayrollPostingService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * E21.3A2 (E21-D8): a School whose FY 2015-16 closed on 2016-04-05 -- more
 * than 8 years ago by the DATABASE's real clock, so the database floor
 * genuinely passes -- with every kind of Finance retention unit:
 *
 * FY 2015-16 (eligible):
 * - c1 1000.00, paid 300 + 700 (two Payments, two receipts): settled;
 * - c2 500.00, cancelled: settled;
 * - c4 400.00, a 400.00 concession: settled without a Payment;
 * - c3 800.00, 50.00 paid: OPEN, retained;
 * - c6 600.00, paid in FY 2016-17: crosses into a younger period;
 * - manual entry m1 and its reversal (same year): a standalone unit;
 * - manual entry m2, reversed in FY 2016-17: crosses;
 * - a payroll posting: retained (D9 evidence references it).
 *
 * FY 2016-17 (posted 2016-06-20): c6's payment, m2's reversal, c7 900.00
 * (paid in full: settled, but its year is young).
 * With `$closeSecondYear` it is closed TODAY (a young close, like a
 * historical year closed at cutover); otherwise it stays open. A posting
 * today lands in the current open year.
 */
trait CreatesFinanceRetentionFixtures
{
    use CreatesFinancialPeriodFixtures;

    protected function d8World(bool $closeSecondYear = true): array
    {
        $this->travelTo(Carbon::parse('2015-06-15 06:00:00', 'UTC'));
        $w = $this->concessionWorld();
        $w['closer'] = $this->createUserWithCapabilities($w['school'], ['finance.ledger.view', 'finance.periods.manage']);
        $assess = fn (string $amount) => $this->assessCharge($w['school'], $w['student'], $w['year'], $w['receivable'], $w['revenue'], $amount);
        $w['c1'] = $w['charge'];
        $w['c2'] = $assess('500.00');
        $w['c3'] = $assess('800.00');
        $w['c4'] = $assess('400.00');
        $w['c6'] = $assess('600.00');
        $w['p1'] = $this->pay($w, '300.00', null, $w['c1'])->paymentId;
        $this->pay($w, '50.00', null, $w['c3']);
        $this->concessions()->approve($w['school'], $this->requestTargeted($w, '400.00', charge: $w['c4'])->id, $w['checker']);
        $w['m1'] = $this->postBalancedJournalEntry($w['school'], $w['settlement'], $w['revenue'], '75.00')->id;
        $w['m1r'] = app(LedgerService::class)->reverseById($w['school'], $w['m1'])->journalEntryId;
        $w['m2'] = $this->postBalancedJournalEntry($w['school'], $w['settlement'], $w['revenue'], '20.00')->id;
        $w['run'] = $this->approvedPayrollRun($w['school'], '2015-08-01');

        $this->travelTo(Carbon::parse('2015-09-30 06:00:00', 'UTC'));
        $w['payroll_entry'] = app(PayrollPostingService::class)->post($this->inSchool($w['school'], fn () => $w['run']->fresh()), $this->createUser())->journal_entry_id;

        $this->travelTo(Carbon::parse('2015-11-10 06:00:00', 'UTC'));
        $w['p2'] = $this->pay($w, '700.00', null, $w['c1'])->paymentId;
        app(ChargeService::class)->cancel($w['school'], $w['c2']->id, $w['actor'], 'billed twice');

        $this->travelTo(Carbon::parse('2016-04-05 06:00:00', 'UTC'));
        $w['fy1516'] = $this->periodByKey($w['school'], '2015-16');
        $this->closePeriod($w, $w['fy1516']);

        $this->travelTo(Carbon::parse('2016-06-20 06:00:00', 'UTC'));
        $this->pay($w, '600.00', null, $w['c6']);
        $w['m2r'] = app(LedgerService::class)->reverseById($w['school'], $w['m2'])->journalEntryId;
        $w['c7'] = $assess('900.00');
        $this->pay($w, '900.00', null, $w['c7']);
        $w['fy1617'] = $this->periodByKey($w['school'], '2016-17');

        $this->travelBack();
        if ($closeSecondYear) {
            $this->closePeriod($w, $w['fy1617']);
        }
        $w['today_entry'] = $this->postBalancedJournalEntry($w['school'], $w['settlement'], $w['revenue'], '5.00')->id;
        $w['fy1516'] = $this->periodByKey($w['school'], '2015-16');
        $w['fy1617'] = $this->periodByKey($w['school'], '2016-17');

        return $w;
    }

    protected function enableFinanceRetention(int $years = 8): void
    {
        config(['retention.finance_enabled' => true, 'retention.finance_years' => $years]);
    }

    /**
     * Every Finance reading that must survive an expiry exactly: account
     * balances (payroll accounts included), each charge's outstanding (an
     * expired charge reads as 0.00, what it owed), each Student's dues and
     * every receipt series' next number.
     *
     * @return array<string, string>
     */
    protected function golden(array $w, array $chargeIds): array
    {
        $school = $w['school'];
        $readings = [];
        foreach (app(LedgerBalanceReader::class)->balances($school) as $key => $balance) {
            $readings["account:{$key}"] = $balance['debit'].'|'.$balance['credit'];
        }
        $states = app(ChargeStateReader::class)->forCharges($school, $chargeIds);
        foreach ($chargeIds as $id) {
            $readings["charge:{$id}"] = isset($states[$id]) ? $states[$id]->outstanding()->amount() : '0.00';
        }
        $all = app(ChargeStateReader::class)->forCharges($school, array_map(fn ($l) => $l->chargeId, app(ChargeService::class)->statementLinesForStudent($school, $w['student']->id)));
        $dues = '0.00';
        foreach ($all as $state) {
            $dues = bcadd($dues, $state->outstanding()->amount(), 2);
        }
        $readings['student_dues'] = $dues;
        foreach ($this->inSchool($school, fn () => PaymentReceiptCounter::query()->orderBy('series_key')->get()) as $counter) {
            $readings["receipt_series:{$counter->series_key}"] = (string) $counter->next_value;
        }
        ksort($readings);

        return $readings;
    }

    protected function rowExists(array $w, string $table, string $id): bool
    {
        return $this->inSchool($w['school'], fn () => DB::table($table)->where('id', $id)->exists());
    }
}
