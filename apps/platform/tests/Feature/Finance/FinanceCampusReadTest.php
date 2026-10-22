<?php

namespace Tests\Feature\Finance;

use App\Domain\Fees\Application\ChargeService;
use App\Domain\Payments\Application\PaymentCorrectionData;
use App\Domain\Payments\Application\PaymentCorrectionService;
use App\Domain\Payments\Infrastructure\PaymentCorrection;
use App\Domain\Students\Application\StudentEnrollmentService;
use App\Domain\Students\Infrastructure\StudentEnrollment;
use App\Models\Campus;
use App\Models\School;
use App\Models\User;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Database\Factories\SchoolFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Fees\Concerns\CreatesReceiptFixtures;
use Tests\TestCase;

/**
 * ORG.7G-1 (ADR 0076 §50): Campus-scoped Finance READS.
 *
 * World: Campus A (the world's Campus) and B; one Charge on A, one on B, a
 * mover charged on A before an A->B transfer and on B after, a leaver with an
 * open A Charge, and the world's own unplaced Student owing a NULL-Campus
 * (whole-School) Charge. Payments: PA (A only), PB (B only), PAB (the mover's
 * A and B Charges) and PN (A plus the NULL Charge).
 *
 * A Campus reader sees Charges by their STORED Campus and a Payment only when
 * every Charge it touches has the same stored Campus in scope -- PAB and PN
 * are whole-School only, even for a reader holding A and B -- while each
 * in-scope Charge still shows its full balance.
 */
class FinanceCampusReadTest extends TestCase
{
    use CreatesReceiptFixtures;

    /** @var array<string, mixed> */
    private array $w;

    private School $school;

    private Campus $a;

    private Campus $b;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-06-01T06:00:00Z'));
        $w = $this->concessionWorld(); // its own unplaced Student owes 1000.00: a NULL-Campus Charge
        $this->school = $w['school'];
        $this->a = $w['campus'];
        $this->b = $this->createCampus($this->school, ['name' => 'South']);
        $sectionA = $this->createSection($w['year'], $this->a, $w['grade'], ['code' => 'SA', 'status' => 'active']);
        $sectionB = $this->createSection($w['year'], $this->b, $w['grade'], ['code' => 'SB', 'status' => 'active']);

        $place = function (string $name, $section) use ($w) {
            $student = $this->createStudent($w['school'], ['first_name' => $name, 'last_name' => 'Pupil']);

            return [$student, $this->createStudentEnrollment($student, $section, ['starts_on' => '2026-06-01', 'status' => 'active'])];
        };
        [$w['sA']] = $place('Asha', $sectionA);
        [$w['sB']] = $place('Bilal', $sectionB);
        [$w['sMove'], $moveA] = $place('Mira', $sectionA);
        [$w['sLeaver']] = $place('Leo', $sectionA);

        $this->travelTo(CarbonImmutable::parse('2026-07-01T06:00:00Z'));
        $charge = fn ($s, string $amount) => $this->assessCharge($w['school'], $s, $w['year'], $w['receivable'], $w['revenue'], $amount);
        $w['cA'] = $charge($w['sA'], '100.00');
        $w['cB'] = $charge($w['sB'], '200.00');
        $w['cMoveA'] = $charge($w['sMove'], '300.00');
        $w['cLeaver'] = $charge($w['sLeaver'], '50.00');

        $this->travelTo(CarbonImmutable::parse('2026-08-01T06:00:00Z'));
        $this->inSchool($w['school'], fn () => app(StudentEnrollmentService::class)->transferPlacement(StudentEnrollment::query()->findOrFail($moveA->id), $sectionB, '91', '2026-08-01'));
        $this->travelTo(CarbonImmutable::parse('2026-09-01T06:00:00Z'));
        $w['cMoveB'] = $charge($w['sMove'], '400.00');
        $this->inSchool($w['school'], fn () => DB::table('students')->where('id', $w['sLeaver']->id)->update(['status' => 'inactive']));

        $pay = fn (array $lines, string $amount) => $this->recordManualPayment($w['school'], $w['recorder'], $w['settlement']->id, $lines, $amount)->paymentId;
        $w['PA'] = $pay([[$w['cA'], '40.00']], '40.00');
        $w['PB'] = $pay([[$w['cB'], '50.00']], '50.00');
        $w['PAB'] = $pay([[$w['cMoveA'], '10.00'], [$w['cMoveB'], '10.00']], '20.00');
        $w['PN'] = $pay([[$w['cA'], '5.00'], [$w['charge'], '5.00']], '10.00');

        $this->w = $w;
    }

    /** @param  list<?string>  $campusIds  null = whole School */
    private function staff(string $role, array $campusIds): User
    {
        $user = $this->createUser();
        $membership = $this->createMembership($user, $this->school);
        foreach ($campusIds as $campusId) {
            $this->assignSchoolRole($membership, $role, $campusId);
        }

        return $user;
    }

    private function as(User $user): self
    {
        $this->actingAs($user)->post("/app/schools/{$this->school->id}/activate");

        return $this;
    }

    /** @return array<string, string> outstanding by Student id */
    private function outstanding(User $user, string $query = ''): array
    {
        $props = $this->as($user)->get('/app/finance/outstanding'.($query === '' ? '' : '?'.$query))->assertOk()->viewData('page')['props'];

        return collect($props['rows']['data'])->pluck('outstanding', 'studentId')->all();
    }

    /** @return list<string> */
    private function collections(User $user): array
    {
        $props = $this->as($user)->get('/app/finance/payments?from=2026-01-01&to=2026-12-31')->assertOk()->viewData('page')['props'];

        return collect($props['payments']['data'])->pluck('id')->sort()->values()->all();
    }

    private function ids(string ...$keys): array
    {
        $ids = array_map(fn (string $k) => is_object($this->w[$k]) ? (string) $this->w[$k]->id : (string) $this->w[$k], $keys);
        sort($ids);

        return $ids;
    }

    #[Test]
    public function outstanding_fees_follow_the_stored_charge_campus_with_full_balances(): void
    {
        $w = $this->w;
        $this->travelTo(CarbonImmutable::parse('2026-10-01T06:00:00Z'));

        // cA: 100 - PA 40 - PN 5 (a hidden mixed Payment still reduces it, G1-D7).
        $this->assertEquals([$w['sA']->id => '55.00', $w['sMove']->id => '290.00', $w['sLeaver']->id => '50.00'], $this->outstanding($this->staff('cashier', [$this->a->id])), 'A: its own Charges, the mover\'s old A debt and the leaver -- never B, never the NULL Charge');
        $this->assertEquals([$w['sB']->id => '150.00', $w['sMove']->id => '390.00'], $this->outstanding($this->staff('cashier', [$this->b->id])));
        $this->assertCount(5, $this->outstanding($this->staff('cashier', [null])), 'whole School: everyone, the NULL-Campus debtor included');

        $cashierA = $this->staff('cashier', [$this->a->id]);
        $this->assertSame([], $this->outstanding($cashierA, 'campus_id='.$this->b->id), 'a request Campus filter only narrows -- never widens');
        $this->assertSame([], $this->outstanding($cashierA, 'campus_id=unavailable'));
        $this->assertEquals([$w['sLeaver']->id => '50.00'], $this->outstanding($cashierA, 'standing=leavers'));
    }

    #[Test]
    public function charges_are_read_by_stored_campus_and_hidden_payments_show_as_an_amount_only(): void
    {
        $w = $this->w;
        $cashierA = $this->staff('cashier', [$this->a->id]);
        $listed = collect($this->as($cashierA)->get('/app/finance/charges')->assertOk()->viewData('page')['props']['charges']['data'])->pluck('id')->sort()->values()->all();
        $this->assertSame($this->ids('cA', 'cLeaver', 'cMoveA'), array_map('strval', $listed));

        foreach (['cB', 'cMoveB', 'charge'] as $hidden) {
            $this->as($cashierA)->get("/app/finance/charges/{$w[$hidden]->id}")->assertNotFound();
        }
        $this->as($cashierA)->get('/app/finance/charges/'.fake()->uuid())->assertNotFound();

        $this->as($cashierA)->get("/app/finance/charges/{$w['cA']->id}")->assertOk()->assertInertia(fn (AssertableInertia $p) => $p
            ->where('payments', [['paymentId' => $w['PA'], 'amount' => '40.00']])
            ->where('otherPaymentsAmount', '5.00')
            ->where('charge.journalEntryId', null)
            ->where('canViewStatement', false));
    }

    #[Test]
    public function a_payment_is_campus_visible_only_when_every_charge_it_touches_shares_one_campus_in_scope(): void
    {
        $w = $this->w;
        $this->assertSame($this->ids('PA'), $this->collections($this->staff('cashier', [$this->a->id])));
        $this->assertSame($this->ids('PB'), $this->collections($this->staff('cashier', [$this->b->id])));
        $both = $this->staff('cashier', [$this->a->id, $this->b->id]);
        $this->assertSame($this->ids('PA', 'PB'), $this->collections($both), 'PAB and PN are whole-School only, even for A+B');
        $this->assertSame($this->ids('PA', 'PAB', 'PB', 'PN'), $this->collections($this->staff('cashier', [null])));

        $totals = $this->as($both)->get('/app/finance/payments?from=2026-01-01&to=2026-12-31')->viewData('page')['props']['totals'];
        $this->assertSame('90.00', $totals[0]['gross'], 'Campus totals add up the visible Payments only, each once');

        foreach (['PAB', 'PN'] as $mixed) {
            $this->as($both)->get("/app/finance/payments/{$w[$mixed]}")->assertNotFound();
            $this->as($both)->get("/app/finance/payments/{$w[$mixed]}/receipt")->assertNotFound();
        }
        $cashierA = $this->staff('cashier', [$this->a->id]);
        $this->as($cashierA)->get("/app/finance/payments/{$w['PB']}")->assertNotFound();
        $this->as($cashierA)->get("/app/finance/payments/{$w['PB']}/receipt")->assertNotFound();
        $this->as($cashierA)->get("/app/finance/payments/{$w['PA']}")->assertOk()->assertInertia(fn (AssertableInertia $p) => $p
            ->where('payment.id', $w['PA'])->where('payment.journalEntryId', null)
            ->where('corrections.canReallocate', false)->where('corrections.canReverseOrRefund', false));
        $this->as($cashierA)->get("/app/finance/payments/{$w['PA']}/receipt")->assertOk();
    }

    #[Test]
    public function the_api_reads_follow_the_same_rules(): void
    {
        $w = $this->w;
        $cashierA = $this->staff('cashier', [$this->a->id]);
        $api = $this->withHeader('Authorization', 'Bearer '.$cashierA->createToken('test')->plainTextToken);
        $base = "/api/v1/schools/{$this->school->id}";

        $this->assertSame([$w['PA']], collect($api->getJson("{$base}/payments")->assertOk()->json('data'))->pluck('id')->all());
        $api->getJson("{$base}/payments/{$w['PN']}")->assertNotFound();
        $api->getJson("{$base}/payments/{$w['PAB']}/receipt")->assertNotFound();
        $api->getJson("{$base}/charges/{$w['cB']->id}")->assertNotFound();
        $this->assertNull($api->getJson("{$base}/charges/{$w['cA']->id}")->assertOk()->json('data.journalEntryId'));
        $api->getJson("{$base}/students/{$w['sA']->id}/fee-statement")->assertForbidden();
    }

    #[Test]
    public function the_debtor_lookup_finds_open_in_scope_debt_including_leavers_and_returns_identity_only(): void
    {
        $w = $this->w;
        $search = fn (User $u, string $q = '') => collect($this->as($u)->getJson('/app/finance/debtors/search?q='.$q)->assertOk()->json('data'));

        $a = $search($this->staff('cashier', [$this->a->id]));
        $this->assertEqualsCanonicalizing([$w['sA']->id, $w['sMove']->id, $w['sLeaver']->id], $a->pluck('id')->all(), 'the leaver and the moved Student stay findable by their A debt');
        $this->assertSame(['displayName', 'id', 'studentNumber'], collect(array_keys($a->first()))->sort()->values()->all());
        $this->assertEqualsCanonicalizing([$w['sB']->id, $w['sMove']->id], $search($this->staff('cashier', [$this->b->id]))->pluck('id')->all());
        $this->assertSame([$w['sLeaver']->id], $search($this->staff('cashier', [$this->a->id]), 'Leo')->pluck('id')->all());
        $this->assertSame([], $search($this->staff('cashier', [$this->b->id]), 'Leo')->all(), 'B never finds the A leaver');
    }

    #[Test]
    public function principal_at_campus_and_bounded_side_effect_roles_get_no_finance_and_no_campus_finance_write_exists(): void
    {
        $w = $this->w;
        foreach (['principal', 'librarian', 'canteen_operator'] as $role) {
            $user = $this->staff($role, [$this->a->id]);
            foreach (['/app/finance/outstanding', '/app/finance/charges', '/app/finance/payments', '/app/finance/debtors/search'] as $page) {
                $this->as($user)->get($page)->assertForbidden();
            }
        }

        $cashierA = $this->staff('cashier', [$this->a->id]);
        $this->as($cashierA)->get('/app/finance')->assertForbidden();
        $this->as($cashierA)->get('/app/finance/fee-statements')->assertForbidden();
        $this->as($cashierA)->get('/app/finance/fee-runs')->assertForbidden();
        // ORG.7G-2 opened payment ENTRY only (FinanceCampusPaymentEntryTest); corrections stay whole-School.
        // ORG.8C: a correction is only requested (the direct route is gone), and a cashier requests nothing.
        foreach (['reversal', 'reallocation', 'refund'] as $kind) {
            $this->as($cashierA)->post("/app/finance/payments/{$w['PA']}/correction-requests", ['kind' => $kind])->assertForbidden();
            $this->as($cashierA)->post("/app/finance/payments/{$w['PA']}/corrections", ['kind' => $kind])->assertNotFound();
        }
        $this->as($cashierA)->get('/app/finance/payment-correction-requests')->assertForbidden();
        $this->as($cashierA)->post('/app/finance/charges', [])->assertForbidden();
    }

    #[Test]
    public function an_inactive_campus_or_a_disabled_fees_module_confers_nothing(): void
    {
        $cashierA = $this->staff('cashier', [$this->a->id]);
        $this->as($cashierA)->get('/app/finance/outstanding')->assertOk();

        $this->inSchool($this->school, fn () => DB::table('campuses')->where('id', $this->a->id)->update(['status' => 'inactive']));
        $this->as($cashierA)->get('/app/finance/outstanding')->assertForbidden();
        $this->as($cashierA)->get('/app/finance/payments')->assertForbidden();
        $this->assertCount(5, $this->outstanding($this->staff('cashier', [null])), 'whole-School Finance keeps every record; Charges keep their Campus');

        $this->inSchool($this->school, fn () => DB::table('campuses')->where('id', $this->a->id)->update(['status' => 'active']));
        $this->as($cashierA)->get('/app/finance/outstanding')->assertOk();
        SchoolFactory::setOptionalModules($this->school, false, ['fees']);
        $this->as($cashierA)->get('/app/finance/outstanding')->assertForbidden();
    }

    #[Test]
    public function a_campus_finance_reader_lands_on_the_campus_aware_entries_not_the_hub(): void
    {
        $cashierA = $this->staff('cashier', [$this->a->id]);
        $this->as($cashierA)->get('/app/finance/outstanding')->assertOk()->assertInertia(fn (AssertableInertia $p) => $p
            ->where('scope', 'campus')
            ->where('campuses', [['id' => $this->a->id, 'name' => $this->a->name]]));
        $keys = collect($this->as($cashierA)->get('/app/finance/outstanding')->viewData('page')['props']['navigation']['groups'])
            ->flatMap(fn (array $g) => array_column($g['items'], 'key'))->all();
        $this->assertContains('outstanding-fees', $keys);
        $this->assertContains('payments-received', $keys);
        $this->assertNotContains('finance', $keys, 'the whole-School Finance hub is not offered to a Campus reader');
    }

    #[Test]
    public function a_reallocation_onto_another_campus_makes_the_payment_whole_school_only(): void
    {
        $w = $this->w;
        $pm = $this->recordManualPayment($w['school'], $w['recorder'], $w['settlement']->id, [[$w['cMoveA'], '30.00']], '30.00')->paymentId;
        $cashierA = $this->staff('cashier', [$this->a->id]);
        $this->as($cashierA)->get("/app/finance/payments/{$pm}")->assertOk();

        $corrector = $this->createUserWithCapabilities($this->school, ['finance.payments.record', 'finance.charges.manage']);
        app(PaymentCorrectionService::class)->correct($this->school, new PaymentCorrectionData(
            paymentId: $pm, kind: PaymentCorrection::REALLOCATION, reason: 'Belongs to the later term',
            idempotencyKey: (string) Str::uuid(), chargeId: $w['cMoveA']->id, toChargeId: $w['cMoveB']->id, amount: Money::of('10.00', 'INR'),
        ), $corrector);

        $this->as($cashierA)->get("/app/finance/payments/{$pm}")->assertNotFound();
        $this->as($cashierA)->get("/app/finance/payments/{$pm}/receipt")->assertNotFound();
        $this->assertNotContains($pm, $this->collections($cashierA));
        $this->assertContains($pm, $this->collections($this->staff('cashier', [null])), 'whole-School Finance still sees it');
    }

    #[Test]
    public function settled_and_cancelled_debt_never_makes_a_debtor_and_the_api_charge_list_is_scoped(): void
    {
        $w = $this->w;
        $section = $this->createSection($w['year'], $this->a, $w['grade'], ['code' => 'SA2', 'status' => 'active']);
        $paid = $this->createStudent($this->school, ['first_name' => 'Paidup']);
        $this->createStudentEnrollment($paid, $section, ['starts_on' => '2026-06-01', 'status' => 'active']);
        $cancelled = $this->createStudent($this->school, ['first_name' => 'Voided']);
        $this->createStudentEnrollment($cancelled, $section, ['starts_on' => '2026-06-01', 'status' => 'active']);
        $paidCharge = $this->assessCharge($this->school, $paid, $w['year'], $w['receivable'], $w['revenue'], '30.00');
        $this->recordManualPayment($this->school, $w['recorder'], $w['settlement']->id, [[$paidCharge, '30.00']], '30.00');
        $voided = $this->assessCharge($this->school, $cancelled, $w['year'], $w['receivable'], $w['revenue'], '30.00');
        app(ChargeService::class)->cancel($this->school, $voided->id, null, 'recorded in error');

        $cashierA = $this->staff('cashier', [$this->a->id]);
        $found = collect($this->as($cashierA)->getJson('/app/finance/debtors/search')->assertOk()->json('data'))->pluck('id')->all();
        $this->assertNotContains($paid->id, $found, 'fully settled: not a debtor');
        $this->assertNotContains($cancelled->id, $found, 'cancelled: not a debtor');

        $api = $this->withHeader('Authorization', 'Bearer '.$cashierA->createToken('test')->plainTextToken);
        $listed = collect($api->getJson("/api/v1/schools/{$this->school->id}/charges?per_page=100")->assertOk()->json('data'))->pluck('id')->sort()->values()->all();
        $this->assertSame(collect([$w['cA']->id, $w['cMoveA']->id, $w['cLeaver']->id, $paidCharge->id])->sort()->values()->all(), $listed);
    }
}
