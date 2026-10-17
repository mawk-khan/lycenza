<?php

namespace Tests\Feature\Payments;

use App\Domain\Payments\Application\PaymentCorrectionData;
use App\Domain\Payments\Application\PaymentCorrectionService;
use App\Domain\Payments\Domain\ManualPaymentMethod;
use App\Domain\Payments\Infrastructure\PaymentCorrection;
use App\Models\School;
use App\Models\User;
use App\Support\Authorization\CapabilityResolver;
use App\Support\Money\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Fees\Concerns\CreatesReceiptFixtures;
use Tests\TestCase;

/**
 * LC-4 (M4): Finance -> Payments received. Receipts recorded on a School
 * day (default the School's today), named by receipt number, Student and
 * method, with gross totals over every matching receipt. Corrections show
 * as a status; a receipt never leaves the day it was recorded.
 */
class DailyCollectionsTest extends TestCase
{
    use CreatesReceiptFixtures;

    private const UUID = '/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i';

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function cashier(School $school): User
    {
        $user = $this->createUser();
        $this->assignSchoolRole($this->createMembership($user, $school), 'cashier');
        $this->actingAs($user)->post("/app/schools/{$school->id}/activate");

        return $user;
    }

    /** @return array<string, mixed> */
    private function page(string $query = ''): array
    {
        return $this->get('/app/finance/payments'.($query === '' ? '' : '?'.$query))->assertOk()->viewData('page')['props'];
    }

    private function correct(array $w, string $paymentId, string $kind, array $extra = []): void
    {
        app(PaymentCorrectionService::class)->correct($w['school'], new PaymentCorrectionData(
            paymentId: $paymentId,
            kind: $kind,
            reason: 'Correction for the collections test',
            idempotencyKey: (string) Str::uuid(),
            chargeId: $extra['charge'] ?? null,
            toChargeId: $extra['to'] ?? null,
            amount: isset($extra['amount']) ? Money::of($extra['amount'], 'INR') : null,
            accountId: $extra['account'] ?? null,
            method: $extra['method'] ?? null,
            refundedOn: $extra['on'] ?? null,
        ), $w['recorder']);
        $this->inSchool($w['school'], function () {
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
            DB::statement('SET CONSTRAINTS ALL DEFERRED');
        });
    }

    #[Test]
    public function a_cashier_sees_todays_receipts_by_number_student_and_method_with_exact_totals(): void
    {
        $w = $this->concessionWorld();
        $other = $this->createStudent($w['school'], ['first_name' => 'Diya', 'last_name' => 'Iyer', 'student_number' => 'LDS-0002']);
        $otherCharge = $this->assessCharge($w['school'], $other, $w['year'], $w['receivable'], $w['revenue'], '400.00');
        $cash = $this->pay($w, '250.00');
        $cheque = $this->recordManualPayment($w['school'], $w['recorder'], $w['settlement']->id, [[$otherCharge, '400.00']], '400.00', method: ManualPaymentMethod::Cheque, reference: 'CHQ-SECRET-1');
        $this->cashier($w['school']);

        $props = $this->page();
        $this->assertSame($this->today($w), $props['filters']['from']);
        $this->assertSame([['currency' => 'INR', 'receipts' => 2, 'gross' => '650.00', 'retained' => '650.00']], $props['totals']);
        $rows = collect($props['payments']['data'])->keyBy('id');
        $this->assertSame('Cash', $rows[$cash->paymentId]['methodLabel']);
        $this->assertSame('Cheque', $rows[$cheque->paymentId]['methodLabel']);
        $this->assertSame(['Diya Iyer (LDS-0002)'], $rows[$cheque->paymentId]['students']);
        $this->assertSame($this->receiptOf($w, $cash->paymentId)->receipt_number, $rows[$cash->paymentId]['receiptNumber']);
        $this->assertSame('received', $rows[$cash->paymentId]['status']);

        // People read names; the manual reference is never listed (rule 91).
        $visible = json_encode($rows->map(fn ($r) => collect($r)->except('id'))->values()->all());
        $this->assertDoesNotMatchRegularExpression(self::UUID, $visible);
        $this->assertStringNotContainsString('CHQ-SECRET-1', json_encode($props));

        // Receipt, Student and method filters; totals follow the filter.
        $receipt = $this->receiptOf($w, $cheque->paymentId)->receipt_number;
        $this->assertSame([$cheque->paymentId], array_column($this->page('receipt='.urlencode(substr($receipt, -4)))['payments']['data'], 'id'));
        $this->assertSame([$cheque->paymentId], array_column($this->page('student=Diya')['payments']['data'], 'id'));
        $this->assertSame([$cheque->paymentId], array_column($this->page('student=LDS-0002')['payments']['data'], 'id'));
        $byMethod = $this->page('method=cash');
        $this->assertSame([$cash->paymentId], array_column($byMethod['payments']['data'], 'id'));
        $this->assertSame('250.00', $byMethod['totals'][0]['gross']);
        $this->assertSame([], $this->page('student=Nobody-Here')['payments']['data']);
    }

    #[Test]
    public function totals_cover_every_matching_receipt_not_just_the_page(): void
    {
        $w = $this->concessionWorld();
        foreach (range(1, 27) as $_) {
            $this->pay($w, '10.00');
        }
        $this->cashier($w['school']);

        $props = $this->page();
        $this->assertCount(25, $props['payments']['data']);
        $this->assertSame(27, $props['payments']['total']);
        $this->assertSame(['currency' => 'INR', 'receipts' => 27, 'gross' => '270.00', 'retained' => '270.00'], $props['totals'][0]);
        $this->assertCount(2, $this->page('page=2')['payments']['data']);

        DB::enableQueryLog();
        $this->page();
        $studentQueries = collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'from "students"'))->count();
        DB::disableQueryLog();
        $this->assertLessThanOrEqual(1, $studentQueries, 'Students are named in one query per page.');
    }

    #[Test]
    public function corrections_show_as_status_and_never_move_a_receipt_off_its_day(): void
    {
        Carbon::setTestNow('2026-10-15 06:00:00');
        $w = $this->concessionWorld();
        $second = $this->assessCharge($w['school'], $w['student'], $w['year'], $w['receivable'], $w['revenue'], '800.00');
        $moved = $this->pay($w, '300.00');
        $reversed = $this->pay($w, '100.00');
        $partly = $this->pay($w, '200.00');
        $full = $this->pay($w, '50.00');

        Carbon::setTestNow('2026-10-17 06:00:00'); // corrections happen later
        $this->correct($w, $moved->paymentId, PaymentCorrection::REALLOCATION, ['charge' => $w['charge']->id, 'to' => $second->id, 'amount' => '100.00']);
        $this->correct($w, $reversed->paymentId, PaymentCorrection::REVERSAL);
        $refund = ['charge' => $w['charge']->id, 'account' => $w['settlement']->id, 'method' => ManualPaymentMethod::Cash, 'on' => '2026-10-17'];
        $this->correct($w, $partly->paymentId, PaymentCorrection::REFUND, [...$refund, 'amount' => '80.00']);
        $this->correct($w, $full->paymentId, PaymentCorrection::REFUND, [...$refund, 'amount' => '50.00']);
        $this->cashier($w['school']);

        $day = $this->page('from=2026-10-15');
        $status = collect($day['payments']['data'])->pluck('status', 'id');
        $this->assertSame('received', $status[$moved->paymentId]);
        $this->assertTrue(collect($day['payments']['data'])->firstWhere('id', $moved->paymentId)['reallocated']);
        $this->assertSame('reversed', $status[$reversed->paymentId]);
        $this->assertSame('partly_refunded', $status[$partly->paymentId]);
        $this->assertSame('refunded', $status[$full->paymentId]);
        // Gross is what was received that day; retained = gross - reversed (100) - refunded (80 + 50).
        $this->assertSame(['currency' => 'INR', 'receipts' => 4, 'gross' => '650.00', 'retained' => '420.00'], $day['totals'][0]);
        // The day the corrections were made has no receipts of its own.
        $this->assertSame([], $this->page('from=2026-10-17')['totals']);
    }

    #[Test]
    public function the_day_is_the_schools_calendar_day_not_utc(): void
    {
        foreach ([['Asia/Kolkata', '2026-10-16 19:00:00', '2026-10-17', '2026-10-16'], ['America/New_York', '2026-10-17 02:00:00', '2026-10-16', '2026-10-17']] as [$zone, $utc, $schoolDay, $utcDay]) {
            Carbon::setTestNow($utc);
            $w = $this->concessionWorld();
            $w['school']->forceFill(['timezone' => $zone])->save();
            $payment = $this->pay($w, '75.00');
            $this->cashier($w['school']);

            $this->assertSame($schoolDay, $this->page()['filters']['from'], "{$zone}: today is the School's today");
            $this->assertSame([$payment->paymentId], array_column($this->page("from={$schoolDay}")['payments']['data'], 'id'), $zone);
            $this->assertSame([], $this->page("from={$utcDay}")['payments']['data'], "{$zone}: never the UTC day");
        }
    }

    #[Test]
    public function only_payment_viewers_see_it_corrections_stay_with_their_holders_and_schools_never_mix(): void
    {
        $w = $this->concessionWorld();
        $mine = $this->pay($w, '120.00');
        $foreign = $this->concessionWorld();
        $theirs = $this->pay($foreign, '999.00');

        $cashier = $this->cashier($w['school']);
        // Receipt numbers are per School: the same number finds only this School's receipt.
        $props = $this->page('receipt='.urlencode($this->receiptOf($foreign, $theirs->paymentId)->receipt_number));
        $this->assertSame([$mine->paymentId], array_column($props['payments']['data'], 'id'));
        $this->assertStringNotContainsString($theirs->paymentId, json_encode($props));
        $this->assertSame('120.00', $props['totals'][0]['gross'], 'Another School never reaches the totals.');
        $this->assertSame([$mine->paymentId], array_column($this->page()['payments']['data'], 'id'));
        $this->get("/app/finance/payments/{$theirs->paymentId}")->assertNotFound();

        // The Cashier corrects nothing: reversal and refund stay with School Admin.
        $this->post("/app/finance/payments/{$mine->paymentId}/corrections", ['kind' => 'reversal', 'reason' => 'Not my job here', 'idempotency_key' => (string) Str::uuid()])
            ->assertForbidden();
        $this->assertFalse(app(CapabilityResolver::class)->canInSchool($cashier, 'finance.ledger.reverse', $w['school']));

        $this->actingAs($this->createUserWithCapabilities($w['school'], ['students.view']))->post("/app/schools/{$w['school']->id}/activate");
        $this->get('/app/finance/payments')->assertForbidden();

        $this->cashier($w['school']);
        $this->get('/app/finance/payments?from=not-a-date')->assertSessionHasErrors('from');
        $this->get('/app/finance/payments?method=bitcoin')->assertSessionHasErrors('method');
        $this->get('/app/finance/payments?from=2026-10-10&to=2026-10-01')->assertSessionHasErrors('to');
    }
}
