<?php

namespace Tests\Feature\Finance;

use App\Domain\Payments\Infrastructure\Payment;
use App\Domain\Students\Application\StudentEnrollmentService;
use App\Domain\Students\Infrastructure\StudentEnrollment;
use App\Models\Campus;
use App\Models\MembershipRoleAssignment;
use App\Models\School;
use App\Models\SchoolAuditEvent;
use App\Models\User;
use App\Support\Authorization\CapabilityResolver;
use Carbon\CarbonImmutable;
use Database\Factories\SchoolFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ProvidesSensitiveActionMfa;
use Tests\Feature\Fees\Concerns\CreatesReceiptFixtures;
use Tests\TestCase;

/**
 * ORG.7G-2 (ADR 0076 §51): Campus payment ENTRY (finance.payments.record).
 *
 * A Campus cashier records a payment only when every allocated Charge has ONE
 * non-NULL stored Campus in scope -- a leaver's and a moved Student's old debt
 * included (never the current Campus); A+B is refused even for an A+B
 * cashier, a NULL-Campus Charge always; every refusal is one generic answer
 * and records nothing. The normal School receipt is issued; the payment shows
 * in its one Campus's collections. A replay is returned only while the stored
 * payment is still within the CURRENT scope.
 */
class FinanceCampusPaymentEntryTest extends TestCase
{
    use CreatesReceiptFixtures, ProvidesSensitiveActionMfa;

    /** SR.4: each recording request carries a fresh code. */
    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        $this->applySensitiveActionMfa($method, $uri, $parameters, $content, '#^/app/finance/payments/record$#', null);

        return parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
    }

    /** @var array<string, mixed> */
    private array $w;

    private School $school;

    private Campus $a;

    private Campus $b;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-06-01T06:00:00Z'));
        $w = $this->concessionWorld(); // its own unplaced Student owes a NULL-Campus 1000.00
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
        [$w['sMove'], $moveA] = $place('Mira', $sectionA);
        [$w['sLeaver']] = $place('Leo', $sectionA);

        $this->travelTo(CarbonImmutable::parse('2026-07-01T06:00:00Z'));
        $charge = fn ($s, string $amount) => $this->assessCharge($w['school'], $s, $w['year'], $w['receivable'], $w['revenue'], $amount);
        $w['cA'] = $charge($w['sA'], '100.00');
        $w['cMoveA'] = $charge($w['sMove'], '300.00');
        $w['cLeaver'] = $charge($w['sLeaver'], '50.00');

        $this->travelTo(CarbonImmutable::parse('2026-08-01T06:00:00Z'));
        $this->inSchool($w['school'], fn () => app(StudentEnrollmentService::class)->transferPlacement(StudentEnrollment::query()->findOrFail($moveA->id), $sectionB, 'T-91', '2026-08-01')); // never numeric: the factory's process-wide numeric roll sequence can reach any number
        $this->travelTo(CarbonImmutable::parse('2026-09-01T06:00:00Z'));
        $w['cMoveB'] = $charge($w['sMove'], '400.00');
        $this->forceStudentStatusOf($w['school'], $w['sLeaver']->id, 'inactive');
        $this->travelTo(CarbonImmutable::parse('2026-09-15T06:00:00Z'));

        $this->w = $w;
    }

    /** @param  list<?string>  $campusIds  null = whole School */
    private function cashier(array $campusIds): User
    {
        $user = $this->createUser();
        $membership = $this->createMembership($user, $this->school);
        foreach ($campusIds as $campusId) {
            $this->assignSchoolRole($membership, 'cashier', $campusId);
        }

        return $user;
    }

    /** @param  list<array{0: object, 1: string}>  $lines */
    private function record(User $user, array $lines, ?string $key = null): TestResponse
    {
        $this->actingAs($user)->post("/app/schools/{$this->school->id}/activate");
        $total = array_reduce($lines, fn (string $sum, array $l) => bcadd($sum, $l[1], 2), '0.00');

        return $this->post('/app/finance/payments/record', [
            'idempotency_key' => $key ?? (string) Str::uuid(),
            'method' => 'cash',
            'amount' => $total,
            'occurred_on' => '2026-09-15',
            'reference' => null,
            'settlement_ledger_account_id' => $this->w['settlement']->id,
            'allocations' => array_map(fn (array $l) => ['charge_id' => $l[0]->id, 'amount' => $l[1]], $lines),
        ]);
    }

    private function payments(): int
    {
        return $this->inSchool($this->school, fn () => Payment::query()->count());
    }

    private function assertRefusedGenerically(TestResponse $response): void
    {
        $response->assertSessionHasErrors(['allocations' => 'This payment cannot be recorded from your campus. Every charge it pays must belong to one campus you collect for.']);
    }

    #[Test]
    public function a_campus_cashier_collects_a_leavers_and_a_moved_students_historical_debt_and_gets_the_school_receipt(): void
    {
        $w = $this->w;
        $cashierA = $this->cashier([$this->a->id]);

        $response = $this->record($cashierA, [[$w['cLeaver'], '50.00']]);
        $response->assertRedirect();
        $leaverPayment = $this->inSchool($this->school, fn () => Payment::query()->latest('created_at')->with('receipt')->firstOrFail());
        $this->assertNotNull($leaverPayment->receipt, 'the ordinary School receipt is issued');
        $this->assertStringStartsWith('RCPT/', $leaverPayment->receipt->receipt_number);

        $this->record($cashierA, [[$w['cMoveA'], '100.00']])->assertRedirect();
        $this->assertRefusedGenerically($this->record($cashierA, [[$w['cMoveB'], '10.00']]));
        $this->record($this->cashier([$this->b->id]), [[$w['cMoveB'], '10.00']])->assertRedirect();

        // Visible in its one Campus's collections, never the other's.
        $this->actingAs($cashierA)->post("/app/schools/{$this->school->id}/activate");
        $aList = collect($this->get('/app/finance/payments?from=2026-01-01&to=2026-12-31')->viewData('page')['props']['payments']['data'])->pluck('id');
        $this->assertContains($leaverPayment->id, $aList->all());
        $this->assertCount(2, $aList, 'the two A payments, never the B one');

        $event = $this->inSchool($this->school, fn () => SchoolAuditEvent::query()->where('event_type', 'payment.recorded_manually')->where('subject_id', $leaverPayment->id)->sole());
        $this->assertSame(['campus', $this->a->id], [$event->metadata['scope'], $event->metadata['campusId']]);
    }

    #[Test]
    public function mixed_campus_null_campus_and_out_of_scope_payments_are_refused_generically_and_record_nothing(): void
    {
        $w = $this->w;
        $both = $this->cashier([$this->a->id, $this->b->id]);
        $before = $this->payments();

        $this->assertRefusedGenerically($this->record($both, [[$w['cMoveA'], '10.00'], [$w['cMoveB'], '10.00']]));
        $this->assertRefusedGenerically($this->record($both, [[$w['charge'], '10.00']]));
        $this->assertRefusedGenerically($this->record($both, [[$w['cA'], '10.00'], [$w['charge'], '10.00']]));
        $this->assertRefusedGenerically($this->record($this->cashier([$this->b->id]), [[$w['cA'], '10.00']]));
        $this->assertSame($before, $this->payments(), 'no partial payment survives a refusal');

        // A+B actor still records A-only and B-only payments.
        $this->record($both, [[$w['cMoveA'], '10.00']])->assertRedirect();
        $this->record($both, [[$w['cMoveB'], '10.00']])->assertRedirect();

        // Whole-School behaviour unchanged: mixed and NULL-touching payments record.
        $this->record($this->cashier([null]), [[$w['cMoveA'], '10.00'], [$w['cMoveB'], '10.00'], [$w['charge'], '10.00']])->assertRedirect();
        $this->assertSame($before + 3, $this->payments());
    }

    #[Test]
    public function existing_financial_invariants_still_apply_to_a_campus_payment(): void
    {
        $w = $this->w;
        $cashierA = $this->cashier([$this->a->id]);
        $this->record($cashierA, [[$w['cA'], '150.00']])->assertSessionHasErrors('allocations');
        $this->record($cashierA, [[$w['cA'], '60.00'], [$w['cLeaver'], '50.00']])->assertRedirect();
        $this->record($cashierA, [[$w['cA'], '60.00']])->assertSessionHasErrors('allocations');
    }

    #[Test]
    public function a_replay_is_returned_only_while_the_stored_payment_is_still_in_the_current_scope(): void
    {
        $w = $this->w;
        $cashier = $this->cashier([$this->a->id, $this->b->id]);
        $key = (string) Str::uuid();
        $this->record($cashier, [[$w['cMoveA'], '20.00']], $key)->assertRedirect();
        $this->record($cashier, [[$w['cMoveA'], '20.00']], $key)->assertRedirect();
        $this->assertSame(1, $this->inSchool($this->school, fn () => Payment::query()->where('idempotency_key', $key)->count()));

        // The A grant is revoked: the cashier still records for B, but never
        // gets the stored A payment back.
        $this->inSchool($this->school, fn () => MembershipRoleAssignment::query()->where('campus_id', $this->a->id)->whereHas('membership', fn ($q) => $q->where('user_id', $cashier->id))->update(['revoked_at' => now(), 'revocation_reason' => MembershipRoleAssignment::REASON_REVOKED]));
        app(CapabilityResolver::class)->forgetCache($cashier, $this->school); // as the real revocation path does
        $this->assertRefusedGenerically($this->record($cashier, [[$w['cMoveA'], '20.00']], $key));

        // No scope at all: refused before any replay.
        $this->inSchool($this->school, fn () => MembershipRoleAssignment::query()->whereNotNull('campus_id')->whereNull('revoked_at')->whereHas('membership', fn ($q) => $q->where('user_id', $cashier->id))->update(['revoked_at' => now(), 'revocation_reason' => MembershipRoleAssignment::REASON_REVOKED]));
        app(CapabilityResolver::class)->forgetCache($cashier, $this->school);
        $this->record($cashier, [[$w['cMoveA'], '20.00']], $key)->assertForbidden();
    }

    #[Test]
    public function an_inactive_campus_or_a_disabled_fees_module_records_nothing(): void
    {
        $w = $this->w;
        $cashierA = $this->cashier([$this->a->id]);
        $this->inSchool($this->school, fn () => DB::table('campuses')->where('id', $this->a->id)->update(['status' => 'inactive']));
        $this->record($cashierA, [[$w['cA'], '10.00']])->assertForbidden();
        $this->inSchool($this->school, fn () => DB::table('campuses')->where('id', $this->a->id)->update(['status' => 'active']));
        SchoolFactory::setOptionalModules($this->school, false, ['fees']);
        $this->record($cashierA, [[$w['cA'], '10.00']])->assertForbidden();
    }

    #[Test]
    public function the_form_and_search_offer_only_collectable_in_scope_debt(): void
    {
        $w = $this->w;
        $cashierA = $this->cashier([$this->a->id]);
        $this->actingAs($cashierA)->post("/app/schools/{$this->school->id}/activate");

        $found = collect($this->getJson('/app/finance/payments/record/students/search?q=Pupil')->assertOk()->json('data'))->pluck('id')->all();
        $this->assertEqualsCanonicalizing([$w['sA']->id, $w['sMove']->id, $w['sLeaver']->id], $found, 'leavers and moved Students with A debt -- never the NULL debtor');

        $this->get("/app/finance/payments/record?student_id={$w['sMove']->id}")->assertOk()->assertInertia(fn (AssertableInertia $p) => $p
            ->where('campusScoped', true)
            ->where('charges', fn ($charges) => collect($charges)->pluck('id')->all() === [$w['cMoveA']->id]));
        $this->get("/app/finance/payments/record?student_id={$w['student']->id}")->assertOk()->assertInertia(fn (AssertableInertia $p) => $p
            ->where('student', null)->where('charges', []));

        $this->assertSame([], $this->as($this->cashier([$this->b->id]))->getJson('/app/finance/payments/record/students/search?q=Leo')->json('data'));
    }

    #[Test]
    public function unknown_and_other_school_charges_are_refused_generically_and_id_case_is_canonical(): void
    {
        $w = $this->w;
        $cashierA = $this->cashier([$this->a->id]);
        $other = $this->concessionWorld(); // another School's (NULL-Campus) Charge
        $before = $this->payments();

        $this->assertRefusedGenerically($this->record($cashierA, [[(object) ['id' => (string) Str::uuid()], '10.00']]));
        $this->assertRefusedGenerically($this->record($cashierA, [[$other['charge'], '10.00']]));
        $this->assertRefusedGenerically($this->record($cashierA, [[$w['cA'], '10.00'], [$other['charge'], '10.00']]));
        $this->record($cashierA, [[(object) ['id' => 'not-a-uuid'], '10.00']])->assertSessionHasErrors('allocations.0.charge_id');
        $this->assertSame($before, $this->payments());

        // An upper-case id is the same Charge (one canonical lock order).
        $this->record($cashierA, [[(object) ['id' => strtoupper($w['cA']->id)], '10.00']])->assertRedirect();
        $this->assertSame($before + 1, $this->payments());
    }

    #[Test]
    public function a_settled_students_name_is_never_offered_to_a_campus_recorder_but_whole_school_search_is_unchanged(): void
    {
        $w = $this->w;
        $cashierA = $this->cashier([$this->a->id]);
        $this->record($cashierA, [[$w['cLeaver'], '50.00']])->assertRedirect();

        $this->get("/app/finance/payments/record?student_id={$w['sLeaver']->id}")->assertOk()->assertInertia(fn (AssertableInertia $p) => $p
            ->where('student', null)->where('charges', []));

        $nullDebtor = $w['student']->first_name;
        $this->assertSame([], $this->getJson('/app/finance/payments/record/students/search?q='.urlencode($nullDebtor))->assertOk()->json('data'), 'the NULL-Campus debtor is whole-School only');
        $this->assertNotContains($w['sLeaver']->id, collect($this->getJson('/app/finance/payments/record/students/search?q=Leo')->json('data'))->pluck('id')->all(), 'settled debt makes no debtor');

        $whole = collect($this->as($this->cashier([null]))->getJson('/app/finance/payments/record/students/search?q='.urlencode($nullDebtor))->assertOk()->json('data'))->pluck('id')->all();
        $this->assertContains($w['student']->id, $whole);
        $this->assertContains($w['sLeaver']->id, collect($this->getJson('/app/finance/payments/record/students/search?q=Leo')->json('data'))->pluck('id')->all(), 'a whole-School recorder still searches every Student');
    }

    private function as(User $user): self
    {
        $this->actingAs($user)->post("/app/schools/{$this->school->id}/activate");

        return $this;
    }
}
