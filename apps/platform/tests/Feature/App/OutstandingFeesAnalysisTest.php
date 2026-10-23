<?php

namespace Tests\Feature\App;

use App\Domain\Finance\Application\Periods\FinancialPeriodService;
use App\Domain\Finance\Application\Periods\FinancialPeriodSummary;
use App\Domain\Payments\Application\Charges\ChargeStateReader;
use App\Domain\Payments\Application\PaymentCorrectionData;
use App\Domain\Payments\Application\PaymentCorrectionService;
use App\Domain\Payments\Domain\ManualPaymentMethod;
use App\Domain\Payments\Infrastructure\PaymentCorrection;
use App\Models\School;
use App\Models\User;
use App\Support\Money\Money;
use App\Support\Tenancy\SchoolTimezone;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Fees\Concerns\CreatesLateFeeFixtures;
use Tests\Feature\Finance\Concerns\CreatesFinancialPeriodFixtures;
use Tests\TestCase;

/**
 * ORG.9B (ADR 0076 §68), 9B-1 and 9B-2: the Outstanding fees page shows,
 * per Student and in filtered totals, billed / concession / paid / balance
 * over uncancelled Charges -- `billed - concession - paid = balance`
 * exactly, and balance is the canonical outstanding -- and the same "as at"
 * the end of a School-local date, counting only what was POSTED by then.
 */
class OutstandingFeesAnalysisTest extends TestCase
{
    use CreatesFinancialPeriodFixtures;
    use CreatesLateFeeFixtures;

    private function viewer(School $school): User
    {
        $user = $this->createUserWithCapabilities($school, ['finance.charges.view', 'finance.payments.view']);
        $this->actingAs($user)->post("/app/schools/{$school->id}/activate");

        return $user;
    }

    /** @return array{rows: array<string, array<string, mixed>>, totals: array<string, string>, props: array<string, mixed>} */
    private function page(string $query = ''): array
    {
        $props = $this->get('/app/finance/outstanding'.($query === '' ? '' : '?'.$query))->assertOk()->viewData('page')['props'];

        return ['rows' => collect($props['rows']['data'])->keyBy('studentId')->all(), 'totals' => $props['totals'], 'props' => $props];
    }

    /** @return array{0: string, 1: string, 2: string, 3: string} billed, concession, paid, balance */
    private function figures(array $row): array
    {
        return [$row['billed'], $row['concession'], $row['paid'], $row['outstanding']];
    }

    private function correct(array $w, string $paymentId, string $kind, array $extra = []): void
    {
        app(PaymentCorrectionService::class)->correct($w['school'], new PaymentCorrectionData(
            paymentId: $paymentId,
            kind: $kind,
            reason: 'Entered against the wrong charge',
            idempotencyKey: (string) Str::uuid(),
            chargeId: $extra['charge'] ?? null,
            toChargeId: $extra['to'] ?? null,
            amount: isset($extra['amount']) ? Money::of($extra['amount'], 'INR') : null,
            accountId: $extra['account'] ?? null,
            method: $extra['method'] ?? null,
            refundedOn: $extra['on'] ?? null,
            reference: $extra['reference'] ?? null,
        ), $w['recorder']);
        $this->inSchool($w['school'], function () {
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
            DB::statement('SET CONSTRAINTS ALL DEFERRED');
        });
    }

    /** The canonical outstanding of a Student (sum of ChargeStateReader over the Student's uncancelled Charges). */
    private function canonicalOutstanding(School $school, string $studentId): string
    {
        return $this->inSchool($school, function () use ($school, $studentId) {
            $ids = DB::table('charges')->where('student_id', $studentId)->whereNull('cancelled_at')->pluck('id')->map(fn ($id) => (string) $id)->all();
            $sum = '0.00';
            foreach (app(ChargeStateReader::class)->forCharges($school, $ids) as $state) {
                $sum = bcadd($sum, $state->outstanding()->amount(), 2);
            }

            return $sum;
        });
    }

    #[Test]
    public function billed_concession_paid_and_balance_compose_exactly_and_balance_is_the_canonical_outstanding(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-07-01T06:00:00Z'));
        $w = $this->concessionWorld(); // its Student owes a 1000.00 charge
        $this->concessions()->approve($w['school'], $this->requestTargeted($w, '200.00')->id, $w['checker']);
        $this->pay($w, '300.00');
        $paidUp = $this->createStudent($w['school'], ['first_name' => 'Paid', 'last_name' => 'Up']);
        $paidUpCharge = $this->assessCharge($w['school'], $paidUp, $w['year'], $w['receivable'], $w['revenue'], '400.00');
        $this->pay($w, '400.00', null, $paidUpCharge);
        $cancelled = $this->assessCharge($w['school'], $w['student'], $w['year'], $w['receivable'], $w['revenue'], '150.00');
        $this->cancelChargeAs($w['school'], $cancelled->id);

        $this->viewer($w['school']);
        $owing = $this->page();
        $this->assertSame(['1000.00', '200.00', '300.00', '500.00'], $this->figures($owing['rows'][$w['student']->id]), 'The cancelled 150.00 charge is no part of billed.');
        $this->assertSame($this->canonicalOutstanding($w['school'], $w['student']->id), $owing['rows'][$w['student']->id]['outstanding']);
        $this->assertArrayNotHasKey($paidUp->id, $owing['rows'], 'The default view still lists only Students who owe.');

        $all = $this->page('status=all');
        $this->assertSame(['400.00', '0.00', '400.00', '0.00'], $this->figures($all['rows'][$paidUp->id]), 'status=all also lists a paid-up Student, balance 0.');
        $this->assertSame(['billed' => '1400.00', 'concession' => '200.00', 'paid' => '700.00', 'outstanding' => '500.00', 'overdue' => '0.00', 'currency' => 'INR'], $all['totals']);
        $this->get('/app/finance/outstanding?status=everything')->assertSessionHasErrors('status');
    }

    #[Test]
    public function corrections_refunds_and_concession_changes_are_never_counted_twice(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-07-01T06:00:00Z'));
        $w = $this->concessionWorld();
        $second = $this->assessCharge($w['school'], $w['student'], $w['year'], $w['receivable'], $w['revenue'], '500.00');
        $reversed = $this->pay($w, '600.00')->paymentId;
        $this->correct($w, $reversed, PaymentCorrection::REVERSAL, ['account' => $w['settlement']->id]);
        $refunded = $this->pay($w, '300.00')->paymentId;
        $this->correct($w, $refunded, PaymentCorrection::REFUND, ['charge' => $w['charge']->id, 'amount' => '100.00', 'account' => $w['settlement']->id, 'method' => ManualPaymentMethod::Cash, 'on' => '2026-07-01']);
        $moved = $this->pay($w, '50.00')->paymentId;
        $this->correct($w, $moved, PaymentCorrection::REALLOCATION, ['charge' => $w['charge']->id, 'to' => $second->id, 'amount' => '50.00']);

        // An approved targeted concession posts one adjustment; cancelling that
        // adjustment (its own reversal) is the only way it leaves the Charge.
        $concession = $this->requestTargeted($w, '120.00');
        $this->concessions()->approve($w['school'], $concession->id, $w['checker']);

        $this->viewer($w['school']);
        // charge 1000 + second 500; paid: reversal 0 + refund leaves 200 + the 50 moved to `second`
        $this->assertSame(['1500.00', '120.00', '250.00', '1130.00'], $this->figures($this->page()['rows'][$w['student']->id]));

        $adjustment = $this->inSchool($w['school'], fn () => DB::table('fee_adjustments')->where('fee_concession_id', $concession->id)->value('id'));
        $this->concessions()->cancelAdjustment($w['school'], (string) $adjustment, $w['checker']);
        $row = $this->page()['rows'][$w['student']->id];
        $this->assertSame(['1500.00', '0.00', '250.00', '1250.00'], $this->figures($row), 'A cancelled adjustment is out of concession and back in balance.');
        $this->assertSame($this->canonicalOutstanding($w['school'], $w['student']->id), $row['outstanding']);
    }

    #[Test]
    public function a_late_fee_and_a_fee_run_charge_are_ordinary_billed_charges(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-06-12T06:00:00Z'));
        $w = $this->lateFeeWorld();
        $this->executedLateRun($w, $this->activeRule($w, ['max_amount' => '60.00']));

        $this->viewer($w['school']);
        $row = $this->page()['rows'][$w['sourceStudent']];
        $this->assertSame(['5060.00', '0.00', '0.00', '5060.00'], $this->figures($row), 'The fee-run charge (5000.00) and its late fee (60.00).');
    }

    #[Test]
    public function totals_cover_every_matching_row_not_one_page(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-07-01T06:00:00Z'));
        $w = $this->concessionWorld();
        for ($i = 0; $i < 30; $i++) {
            $student = $this->createStudent($w['school'], ['first_name' => 'Many'.$i, 'last_name' => 'Owing']);
            $this->assessCharge($w['school'], $student, $w['year'], $w['receivable'], $w['revenue'], '10.00');
        }

        $this->viewer($w['school']);
        $first = $this->page();
        $this->assertCount(25, $first['rows']);
        $this->assertSame(31, $first['props']['rows']['total']);
        $this->assertSame(['billed' => '1300.00', 'concession' => '0.00', 'paid' => '0.00', 'outstanding' => '1300.00', 'overdue' => '0.00', 'currency' => 'INR'], $first['totals']);
        $this->assertSame($first['totals'], $this->page('page=2')['totals'], 'The same filtered totals on every page.');
    }

    #[Test]
    public function another_schools_charges_never_reach_the_rows_or_the_totals(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-07-01T06:00:00Z'));
        $w = $this->concessionWorld();
        $other = $this->concessionWorld();

        $this->viewer($w['school']);
        $page = $this->page('status=all');
        $this->assertSame([$w['student']->id], array_keys($page['rows']));
        $this->assertSame('1000.00', $page['totals']['billed']);
        $this->assertArrayNotHasKey($other['student']->id, $this->page('status=all&as_at=2026-07-01')['rows']);
    }

    #[Test]
    public function as_at_counts_only_what_was_posted_by_the_end_of_that_school_day(): void
    {
        // Asia/Kolkata (UTC+05:30). Each step is posted at 06:00 UTC = 11:30 school time.
        $this->travelTo(CarbonImmutable::parse('2026-07-01T06:00:00Z'));
        $w = $this->concessionWorld(); // 1000.00 assessed 07-01
        $this->travelTo(CarbonImmutable::parse('2026-07-02T06:00:00Z'));
        $concession = $this->requestTargeted($w, '200.00');
        $this->concessions()->approve($w['school'], $concession->id, $w['checker']);
        $later = $this->assessCharge($w['school'], $w['student'], $w['year'], $w['receivable'], $w['revenue'], '150.00', dueDate: '2026-07-02');
        $this->travelTo(CarbonImmutable::parse('2026-07-03T06:00:00Z'));
        $payment = $this->pay($w, '300.00', '2026-07-01')->paymentId; // received 07-01, RECORDED 07-03
        $this->travelTo(CarbonImmutable::parse('2026-07-04T06:00:00Z'));
        $this->cancelChargeAs($w['school'], $later->id);
        $this->travelTo(CarbonImmutable::parse('2026-07-05T06:00:00Z'));
        $this->correct($w, $payment, PaymentCorrection::REVERSAL, ['account' => $w['settlement']->id]);
        $this->travelTo(CarbonImmutable::parse('2026-07-10T06:00:00Z'));

        $this->viewer($w['school']);
        $at = fn (string $date) => $this->page('status=all&as_at='.$date)['rows'][$w['student']->id] ?? null;
        $this->assertNull($at('2026-06-30'), 'Nothing was assessed yet.');
        $this->assertSame(['1000.00', '0.00', '0.00', '1000.00'], $this->figures($at('2026-07-01')), 'The payment received 07-01 was not recorded until 07-03.');
        $this->assertSame(['1150.00', '200.00', '0.00', '950.00'], $this->figures($at('2026-07-02')));
        $this->assertSame(['1150.00', '200.00', '300.00', '650.00'], $this->figures($at('2026-07-03')));
        $this->assertSame('0.00', $at('2026-07-02')['overdue'], 'Overdue is judged against the as-at date: due 07-02 is not overdue ON 07-02...');
        $this->assertSame('150.00', $at('2026-07-03')['overdue'], '...but is the day after.');
        $this->assertSame(['1000.00', '200.00', '300.00', '500.00'], $this->figures($at('2026-07-04')), 'The 150.00 charge cancelled 07-04 is out from that day.');
        $this->assertSame(['1000.00', '200.00', '0.00', '800.00'], $this->figures($at('2026-07-05')), 'Reversed 07-05.');

        $today = $this->page('status=all');
        $this->assertSame($this->figures($today['rows'][$w['student']->id]), $this->figures($at('2026-07-10')), 'As at today is exactly the current position.');
        $this->assertSame($today['totals'], $this->page('status=all&as_at=2026-07-10')['totals']);
    }

    #[Test]
    public function the_as_at_bound_is_the_start_of_the_next_school_day_in_the_schools_timezone(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-07-05T18:29:59Z')); // 23:59:59 IST on 07-05
        $w = $this->concessionWorld();
        $this->travelTo(CarbonImmutable::parse('2026-07-05T18:30:00Z')); // 00:00:00 IST on 07-06
        $this->pay($w, '100.00');
        $this->travelTo(CarbonImmutable::parse('2026-07-07T06:00:00Z'));

        $this->viewer($w['school']);
        $at = fn (string $date) => $this->figures($this->page('status=all&as_at='.$date)['rows'][$w['student']->id]);
        $this->assertSame(['1000.00', '0.00', '0.00', '1000.00'], $at('2026-07-05'), 'Assessed at 23:59:59 school time: in; paid at 00:00 the next day: out.');
        $this->assertSame(['1000.00', '0.00', '100.00', '900.00'], $at('2026-07-06'));
        $this->assertSame('2026-07-06 18:30:00', SchoolTimezone::startOfNextDayUtc($w['school'], '2026-07-06'));
    }

    #[Test]
    public function a_dst_day_is_bounded_by_the_zones_own_midnights(): void
    {
        $school = $this->createSchool(['timezone' => 'Europe/London']);
        // 2026-03-29 is 23 hours long in London; 2026-10-25 is 25.
        $this->assertSame('2026-03-29 00:00:00', SchoolTimezone::startOfNextDayUtc($school, '2026-03-28'));
        $this->assertSame('2026-03-29 23:00:00', SchoolTimezone::startOfNextDayUtc($school, '2026-03-29'));
        $this->assertSame('2026-10-24 23:00:00', SchoolTimezone::startOfNextDayUtc($school, '2026-10-24'));
        $this->assertSame('2026-10-26 00:00:00', SchoolTimezone::startOfNextDayUtc($school, '2026-10-25'));
        $this->assertSame('2026-10-24 23:00:00', SchoolTimezone::startOfDayUtc($school, '2026-10-25'));
    }

    #[Test]
    public function an_as_at_date_after_today_or_malformed_is_refused(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-07-05T20:00:00Z')); // already 07-06 in the school
        $w = $this->concessionWorld();
        $this->viewer($w['school']);

        $this->page('as_at=2026-07-06'); // the school's today: allowed though UTC is still 07-05
        $this->get('/app/finance/outstanding?as_at=2026-07-07')->assertSessionHasErrors('as_at');
        $this->get('/app/finance/outstanding?as_at=07/01/2026')->assertSessionHasErrors('as_at');
        $this->get('/app/finance/outstanding?as_at=2026-02-30')->assertSessionHasErrors('as_at');
    }

    #[Test]
    public function as_at_reads_across_a_closed_financial_year_from_its_carried_baseline(): void
    {
        $w = $this->twoYearWorld(); // FY 2025-26 facts on 2025-06-15; more on 2026-05-10
        $this->travelTo(CarbonImmutable::parse('2026-06-01T06:00:00Z'));
        $expect = [
            '2025-06-14' => null,
            '2025-12-31' => ['2300.00', '300.00', '350.00', '1650.00'],
            '2026-03-31' => ['2300.00', '300.00', '350.00', '1650.00'],
            '2026-05-31' => ['2700.00', '300.00', '450.00', '1950.00'],
        ];

        $this->viewer($w['school']);
        $read = fn (string $date) => ($row = $this->page('status=all&as_at='.$date)['rows'][$w['student']->id] ?? null) === null ? null : $this->figures($row);
        foreach ($expect as $date => $figures) {
            $this->assertSame($figures, $read($date), "before close, as at {$date}");
        }

        $this->closePeriod($w, $w['fy2526']); // carried baseline now replaces that year's detail in the read
        $this->viewer($w['school']);
        foreach ($expect as $date => $figures) {
            $this->assertSame($figures, $read($date), "after close, as at {$date}");
        }
    }

    #[Test]
    public function an_as_at_date_before_the_retained_history_is_refused_not_guessed(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-07-01T06:00:00Z'));
        $w = $this->concessionWorld();
        $periods = Mockery::mock(FinancialPeriodService::class, [app(TenantContext::class)])->makePartial();
        $periods->shouldReceive('expiryAnchor')->andReturn(new FinancialPeriodSummary('p', '2025-26', '2025-04-01', '2026-03-31', 'closed', null, null, null));
        $this->app->instance(FinancialPeriodService::class, $periods);

        $this->viewer($w['school']);
        $this->get('/app/finance/outstanding?as_at=2026-03-30')->assertSessionHasErrors(['as_at' => 'Figures before 2026-03-31 are no longer retained; choose that date or a later one.']);
        $this->page('as_at=2026-03-31');
    }
}
