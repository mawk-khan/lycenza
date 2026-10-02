<?php

namespace Tests\Feature\Finance;

use App\Domain\Finance\Application\Periods\FinanceRetentionReadiness;
use App\Domain\Finance\Application\Periods\FinancialPeriodSummary;
use App\Domain\Finance\Application\Retention\FinanceRetentionEligibility;
use App\Domain\Payments\Application\StudentFeeStatementReadService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Finance\Concerns\CreatesFinanceRetentionFixtures;
use Tests\TestCase;

/**
 * E21.3A2 (E21-D8, ADR 0064 §14-§18): Finance detail expires 8 calendar
 * years after its financial period CLOSED, in settled, dependency-safe
 * units, and expiring it changes no account balance, charge outstanding,
 * Student due, payroll total or receipt series -- exactly.
 */
class FinanceRetentionTest extends TestCase
{
    use CreatesFinanceRetentionFixtures;

    private function charges(array $w): array
    {
        return array_map(fn (string $k) => $w[$k]->id, ['c1', 'c2', 'c3', 'c4', 'c6', 'c7']);
    }

    #[Test]
    public function the_golden_accounting_test_expiry_changes_no_financial_reading_and_the_detail_is_gone(): void
    {
        $w = $this->d8World();
        $this->enableFinanceRetention();
        $before = $this->golden($w, $this->charges($w));
        $c4Adjustment = $this->adjustmentsOf($w, $w['c4']->id)->sole();
        $c4Concession = $c4Adjustment->fee_concession_id;
        $receipts = $this->inSchool($w['school'], fn () => DB::table('payment_receipts')->whereIn('payment_id', [$w['p1'], $w['p2']])->pluck('id')->all());
        $this->assertCount(2, $receipts);

        $this->artisan('platform:finance-retention-prune', ['--school' => $w['school']->id])
            ->expectsOutputToContain('[applied] periods_eligible=1 units_eligible=4 deleted=4 held=0 blocked=2 errors=0 verification_failed=0')
            ->assertSuccessful();

        $this->assertSame($before, $this->golden($w, $this->charges($w)), 'Every account balance, charge outstanding, Student due, payroll total and receipt series is exactly unchanged.');
        $this->assertSame([], $this->verifyBalances($w['school']));

        // Expired: the settled 2015-16 units, whole.
        foreach (['c1', 'c2', 'c4'] as $c) {
            $this->assertFalse($this->rowExists($w, 'charges', $w[$c]->id), "{$c} expired");
        }
        foreach ([$w['p1'], $w['p2']] as $payment) {
            $this->assertFalse($this->rowExists($w, 'payments', $payment));
        }
        foreach ($receipts as $receipt) {
            $this->assertFalse($this->rowExists($w, 'payment_receipts', $receipt));
        }
        $this->assertFalse($this->rowExists($w, 'fee_adjustments', $c4Adjustment->id));
        $this->assertFalse($this->rowExists($w, 'fee_concessions', $c4Concession));
        $this->assertFalse($this->rowExists($w, 'journal_entries', $w['m1']));
        $this->assertFalse($this->rowExists($w, 'journal_entries', $w['m1r']));
        $this->assertSame(0, $this->inSchool($w['school'], fn () => DB::table('financial_period_charge_states')->where('charge_id', $w['c1']->id)->count()));

        // Retained: open, crossing, payroll, baselines, periods, lineage.
        foreach (['c3', 'c6', 'c7'] as $c) {
            $this->assertTrue($this->rowExists($w, 'charges', $w[$c]->id), "{$c} retained");
        }
        foreach (['m2', 'm2r', 'payroll_entry', 'today_entry'] as $e) {
            $this->assertTrue($this->rowExists($w, 'journal_entries', $w[$e]), "{$e} retained");
        }
        $this->assertTrue($this->rowExists($w, 'students', $w['student']->id), 'Finance retention never deletes a Student');
        $this->inSchool($w['school'], function () use ($w) {
            $this->assertSame(3, DB::table('financial_periods')->count());
            $this->assertGreaterThan(0, DB::table('financial_period_account_balances')->where('financial_period_id', $w['fy1516']->id)->count(), 'baselines survive');
            $this->assertSame(4, DB::table('financial_period_expiries')->where('financial_period_id', $w['fy1516']->id)->count(), 'one lineage row per unit');
        });

        $readiness = app(FinanceRetentionReadiness::class)->assess($w['school']);
        $this->assertSame('2015-16', $readiness['expired_through']);
        $this->assertSame('passed', $readiness['verification']);
        $statement = app(StudentFeeStatementReadService::class)->statementFor($w['school'], $w['student']->id, null, $w['recorder']);
        $this->assertSame('2015-16', $statement->detailExpiredThrough);
        $this->assertSame($before['student_dues'], $statement->totals['outstanding']);

        // A second run finds nothing more to expire.
        $this->artisan('platform:finance-retention-prune', ['--school' => $w['school']->id])
            ->expectsOutputToContain('deleted=0 held=0 blocked=2 errors=0')
            ->assertSuccessful();
    }

    #[Test]
    public function a_dry_run_uses_the_same_eligibility_and_deletes_nothing(): void
    {
        $w = $this->d8World();
        $this->enableFinanceRetention();
        config(['retention.finance_enabled' => false]);
        $before = $this->golden($w, $this->charges($w));

        $this->artisan('platform:finance-retention-prune', ['--school' => $w['school']->id, '--dry-run' => true])
            ->expectsOutputToContain('[dry-run] periods_eligible=1 units_eligible=4 would_delete=4 held=0 blocked=2 errors=0')
            ->assertSuccessful();

        $this->assertSame($before, $this->golden($w, $this->charges($w)));
        $this->assertTrue($this->rowExists($w, 'charges', $w['c1']->id));
        $this->assertTrue($this->rowExists($w, 'journal_entries', $w['m1']));
        $this->assertSame(0, $this->inSchool($w['school'], fn () => DB::table('financial_period_expiries')->count()));
    }

    #[Test]
    public function finance_retention_is_fail_closed_until_explicitly_enabled_with_a_valid_period(): void
    {
        $w = $this->d8World();

        config(['retention.finance_enabled' => false, 'retention.finance_years' => 8]);
        $this->artisan('platform:finance-retention-prune')->expectsOutputToContain('not enabled')->assertSuccessful();
        config(['retention.finance_enabled' => true, 'retention.finance_years' => null]);
        $this->artisan('platform:finance-retention-prune')->expectsOutputToContain('not enabled')->assertSuccessful();
        config(['retention.finance_years' => 7]);
        $this->artisan('platform:finance-retention-prune')->expectsOutputToContain('at least 8')->assertFailed();
        config(['retention.finance_years' => 'eight']);
        $this->artisan('platform:finance-retention-prune')->assertFailed();

        $this->assertTrue($this->rowExists($w, 'charges', $w['c1']->id), 'nothing was deleted');

        // A longer configured period only delays: 11 years after a 2016-04-05 close is not yet.
        $this->enableFinanceRetention(11);
        $this->artisan('platform:finance-retention-prune', ['--school' => $w['school']->id])->expectsOutputToContain('periods_eligible=0')->assertSuccessful();
        $this->assertTrue($this->rowExists($w, 'charges', $w['c1']->id));
    }

    #[Test]
    public function a_held_school_expires_nothing_and_another_school_is_untouched(): void
    {
        $held = $this->d8World();
        $other = $this->d8World();
        $this->enableFinanceRetention();
        config(['retention.hold_school_ids' => [$held['school']->id]]);
        $heldBefore = $this->golden($held, $this->charges($held));

        $this->artisan('platform:finance-retention-prune')
            ->expectsOutputToContain('units_eligible=8 deleted=4 held=4')
            ->assertSuccessful();

        $this->assertSame($heldBefore, $this->golden($held, $this->charges($held)));
        $this->assertTrue($this->rowExists($held, 'charges', $held['c1']->id), 'the held School keeps everything');
        $this->assertFalse($this->rowExists($other, 'charges', $other['c1']->id), 'the other School expired its own units');
        $this->assertContains('legal_hold', app(FinanceRetentionReadiness::class)->assess($held['school'])['blockers']);
    }

    #[Test]
    public function one_schools_run_never_reaches_another_school(): void
    {
        $a = $this->d8World();
        $b = $this->d8World();
        $this->enableFinanceRetention();
        $bBefore = $this->golden($b, $this->charges($b));

        $this->artisan('platform:finance-retention-prune', ['--school' => $a['school']->id])->assertSuccessful();

        $this->assertFalse($this->rowExists($a, 'charges', $a['c1']->id));
        $this->assertTrue($this->rowExists($b, 'charges', $b['c1']->id));
        $this->assertSame($bBefore, $this->golden($b, $this->charges($b)));
    }

    #[Test]
    public function readiness_reports_every_period_and_a_clean_old_period_is_ready(): void
    {
        $w = $this->d8World();
        $this->enableFinanceRetention();

        $periods = app(FinanceRetentionReadiness::class)->assess($w['school'])['periods'];
        $this->assertSame(['dependency_blocked'], $periods['2015-16'], 'an open charge and payroll evidence stay');
        $this->assertSame(['period_too_young'], $periods['2016-17'], 'closed today: the clock starts at the close, not the year end');
        $this->assertSame(['period_not_closed'], $periods['2026-27']);

        $this->travelTo(Carbon::parse('2015-06-15 06:00:00', 'UTC'));
        $clean = $this->concessionWorld();
        $clean['closer'] = $this->createUserWithCapabilities($clean['school'], ['finance.ledger.view', 'finance.periods.manage']);
        $this->pay($clean, '1000.00');
        $this->travelTo(Carbon::parse('2016-04-05 06:00:00', 'UTC'));
        $this->closePeriod($clean, $this->periodByKey($clean['school'], '2015-16'));
        $this->travelBack();

        $report = app(FinanceRetentionReadiness::class)->assess($clean['school']);
        $this->assertSame(['ready'], $report['periods']['2015-16']);
        $this->assertSame([], $report['blockers']);

        config(['retention.finance_enabled' => false]);
        $this->assertContains('retention_cutover_not_enabled', app(FinanceRetentionReadiness::class)->assess($clean['school'])['blockers']);
    }

    #[Test]
    public function age_eligibility_is_the_close_plus_eight_calendar_years_exactly(): void
    {
        $period = fn (string $closedAt) => new FinancialPeriodSummary('p', '2027-28', '2027-04-01', '2028-03-31', 'closed', $closedAt, null, str_repeat('a', 64));

        $leap = $period('2028-02-29T12:00:00+00:00');
        $this->assertTrue(FinanceRetentionEligibility::isAgeEligible($leap, 8, CarbonImmutable::parse('2036-02-29 12:00:00', 'UTC')), 'the exact boundary is eligible');
        $this->assertFalse(FinanceRetentionEligibility::isAgeEligible($leap, 8, CarbonImmutable::parse('2036-02-29 11:59:59', 'UTC')), 'one second before is not');

        $century = $period('2092-02-29T00:00:00+00:00');
        $this->assertFalse(FinanceRetentionEligibility::isAgeEligible($century, 8, CarbonImmutable::parse('2100-02-28 23:59:59', 'UTC')), '2100 has no 29 February');
        $this->assertTrue(FinanceRetentionEligibility::isAgeEligible($century, 8, CarbonImmutable::parse('2100-03-01 00:00:00', 'UTC')), 'the database floor agrees: 2100-03-01 minus 8 years is 2092-03-01');
        $this->assertSame('2092-03-01 00:00:00', DB::selectOne("SELECT (timestamp '2100-03-01 00:00:00' - interval '8 years')::text AS t")->t);
        $this->assertSame('2092-02-28 23:59:59', DB::selectOne("SELECT (timestamp '2100-02-28 23:59:59' - interval '8 years')::text AS t")->t);

        $open = new FinancialPeriodSummary('p', '2027-28', '2027-04-01', '2028-03-31', 'open', null, null, null);
        $this->assertFalse(FinanceRetentionEligibility::isAgeEligible($open, 8, CarbonImmutable::parse('2099-01-01', 'UTC')), 'an open period never expires');
    }
}
