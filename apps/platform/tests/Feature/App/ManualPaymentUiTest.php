<?php

namespace Tests\Feature\App;

use App\Domain\Payments\Infrastructure\Payment;
use App\Models\School;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesFeesFixtures;
use Tests\Concerns\CreatesFinanceFixtures;
use Tests\Concerns\CreatesPaymentsFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\Concerns\ProvidesSensitiveActionMfa;
use Tests\Feature\Platform\Groups\GroupTestHelpers;
use Tests\TestCase;

/**
 * Phase 0O.11A (ADR 0031 implementation amendment): the browser surface for
 * recording an offline payment -- the authorization matrix over the real
 * role catalog (allow AND deny), Group/platform authority never reaching
 * it, validation, duplicate submission, the suspended-School refusal, the
 * limiter, and the safe provenance read views.
 */
class ManualPaymentUiTest extends TestCase
{
    use CreatesFeesFixtures, CreatesFinanceFixtures, CreatesPaymentsFixtures, CreatesTenancyFixtures, GroupTestHelpers, ProvidesSensitiveActionMfa;

    /** SR.4 (ADR 0071 §26.7): recording a payment -- each request gets a fresh code or current MFA assurance. */
    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        $this->applySensitiveActionMfa($method, $uri, $parameters, $content, '#^/app/finance/payments/record$#', null);

        return parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
    }

    private School $school;

    private object $charge;

    private object $second;

    private object $settlement;

    protected function setUp(): void
    {
        parent::setUp();

        $this->school = $this->createSchool();
        $student = $this->createStudent($this->school);
        $year = $this->createAcademicYear($this->school);
        $receivable = $this->createLedgerAccount($this->school, ['type' => 'asset', 'code' => 'FEES-AR']);
        $revenue = $this->createLedgerAccount($this->school, ['type' => 'income']);
        $this->settlement = $this->createLedgerAccount($this->school, ['type' => 'asset', 'code' => 'CASH']);
        $this->charge = $this->assessCharge($this->school, $student, $year, $receivable, $revenue, '1000.00');
        $this->second = $this->assessCharge($this->school, $student, $year, $receivable, $revenue, '500.00');
    }

    private function signInAs(User $user): void
    {
        $this->actingAs($user)->post("/app/schools/{$this->school->id}/activate");
    }

    private function recorder(): User
    {
        $user = $this->createPaymentRecorder($this->school);
        $this->signInAs($user);

        return $user;
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'idempotency_key' => (string) Str::uuid(),
            'method' => 'cash',
            'amount' => '600.00',
            'occurred_on' => now($this->school->timezone)->toDateString(),
            'reference' => null,
            'settlement_ledger_account_id' => $this->settlement->id,
            'allocations' => [
                ['charge_id' => $this->charge->id, 'amount' => '400.00'],
                ['charge_id' => $this->second->id, 'amount' => '200.00'],
            ],
        ], $overrides);
    }

    private function paymentCount(): int
    {
        return app(TenantContext::class)->withSchool($this->school, fn () => Payment::query()->count());
    }

    // --- Allowed ------------------------------------------------------------

    #[Test]
    public function a_school_admin_sees_the_recording_form_with_the_closed_catalog_and_asset_accounts_only(): void
    {
        $this->recorder();

        $this->get("/app/finance/payments/record?student_id={$this->charge->student_id}&charge_id={$this->charge->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('App/Finance/Payments/Record')
                ->where('methods', [
                    ['value' => 'cash', 'label' => 'Cash'],
                    ['value' => 'bank_transfer', 'label' => 'Bank transfer'],
                    ['value' => 'cheque', 'label' => 'Cheque'],
                ])
                ->where('settlementAccounts', fn ($accounts) => collect($accounts)->pluck('code')->sort()->values()->all() === ['CASH', 'FEES-AR'])
                ->where('idempotencyKey', fn ($key) => Str::isUuid($key))
                ->where('preselectedChargeId', $this->charge->id)
                ->where('charges.0.outstanding', '1000.00')
                ->where('charges.1.outstanding', '500.00'));
    }

    #[Test]
    public function recording_redirects_to_the_immutable_payment_with_its_manual_provenance(): void
    {
        $recorder = $this->recorder();

        $response = $this->post('/app/finance/payments/record', $this->payload(['method' => 'cheque', 'reference' => 'CHQ 000451']));

        $payment = app(TenantContext::class)->withSchool($this->school, fn () => Payment::query()->with('allocations')->sole());
        $response->assertRedirect("/app/finance/payments/{$payment->id}");
        $this->assertSame('manual', $payment->source);
        $this->assertSame($recorder->id, $payment->recorded_by_user_id);
        $this->assertCount(2, $payment->allocations);

        $this->get("/app/finance/payments/{$payment->id}")->assertInertia(fn ($page) => $page
            ->component('App/Finance/Payments/Show')
            ->where('recordedOutcome', 'recorded')
            ->where('payment.source', 'manual')
            ->where('payment.methodLabel', 'Cheque')
            ->where('payment.manualReference', 'CHQ 000451')
            ->where('payment.recordedByName', $recorder->name)
            ->where('payment.provider', null)
            ->missing('payment.idempotencyKey'));

        // The one-shot outcome notice does not repeat.
        $this->get("/app/finance/payments/{$payment->id}")->assertInertia(fn ($page) => $page->where('recordedOutcome', null));
    }

    #[Test]
    public function a_duplicate_submission_records_once_and_replays_the_same_payment(): void
    {
        $this->recorder();
        $payload = $this->payload();

        $first = $this->post('/app/finance/payments/record', $payload);
        $second = $this->post('/app/finance/payments/record', $payload);

        $this->assertSame(1, $this->paymentCount());
        $second->assertRedirect($first->headers->get('Location'));
        $second->assertSessionHas('finance.manual_payment_outcome', 'duplicate_replay');
    }

    #[Test]
    public function a_reused_form_key_with_different_content_is_refused(): void
    {
        $this->recorder();
        $payload = $this->payload();
        $this->post('/app/finance/payments/record', $payload);

        $this->post('/app/finance/payments/record', [...$payload, 'amount' => '400.00', 'allocations' => [['charge_id' => $this->charge->id, 'amount' => '400.00']]])
            ->assertSessionHasErrors('idempotency_key');

        $this->assertSame(1, $this->paymentCount());
    }

    #[Test]
    public function the_payments_list_and_charge_page_offer_recording_only_with_the_capability(): void
    {
        $this->recorder();
        $this->get('/app/finance/payments')->assertInertia(fn ($page) => $page->where('canRecord', true));
        $this->get("/app/finance/charges/{$this->charge->id}")->assertInertia(fn ($page) => $page->where('canRecordPayment', true));
        $this->get('/app/finance')->assertInertia(fn ($page) => $page->where('can.recordPayments', true));

        $viewer = $this->createUserWithCapabilities($this->school, ['finance.payments.view', 'finance.charges.view']);
        $this->signInAs($viewer);
        $this->get('/app/finance/payments')->assertInertia(fn ($page) => $page->where('canRecord', false));
        $this->get("/app/finance/charges/{$this->charge->id}")->assertInertia(fn ($page) => $page->where('canRecordPayment', false));
    }

    #[Test]
    public function the_payments_list_distinguishes_manual_from_provider_payments(): void
    {
        $recorder = $this->recorder();
        $this->recordSettlement($this->school, $this->settlement->id, [[$this->second, '100.00']], '100.00', provider: 'test-provider');
        $this->recordManualPayment($this->school, $recorder, $this->settlement->id, [[$this->charge, '50.00']], '50.00', reference: 'SECRETISH-REF');

        $this->get('/app/finance/payments')->assertInertia(fn ($page) => $page
            ->where('payments.data', fn ($rows) => collect($rows)->pluck('source')->sort()->values()->all() === ['manual', 'provider'])
            ->where('payments.data', fn ($rows) => ! str_contains(json_encode($rows), 'SECRETISH-REF')));
    }

    #[Test]
    public function the_student_search_is_gated_by_the_recording_capability(): void
    {
        $this->recorder();
        $this->getJson('/app/finance/payments/record/students/search?q=te')->assertOk()->assertJsonStructure(['data']);

        $this->signInAs($this->createUserWithCapabilities($this->school, ['finance.payments.view']));
        $this->getJson('/app/finance/payments/record/students/search?q=te')->assertForbidden();
    }

    // --- Denied -------------------------------------------------------------

    #[Test]
    public function every_school_actor_without_the_capability_is_refused(): void
    {
        $actors = [
            // Seeded roles: principal holds no Finance capability.
            'principal' => fn () => tap($this->createUser(), fn (User $u) => $this->assignSchoolRole($this->createMembership($u, $this->school), 'principal')),
            // The catalog seeds no Teacher/Student/Guardian role: a Guardian
            // account (GuardianAccountActivationService) and ordinary staff
            // are role-less School memberships.
            'role-less member (guardian/teacher/staff)' => fn () => tap($this->createUser(), fn (User $u) => $this->createMembership($u, $this->school)),
            'payments viewer' => fn () => $this->createUserWithCapabilities($this->school, ['finance.payments.view']),
            'charges + ledger manager' => fn () => $this->createUserWithCapabilities($this->school, ['finance.charges.manage', 'finance.ledger.post', 'finance.ledger.reverse']),
        ];

        foreach ($actors as $label => $make) {
            $this->signInAs($make());

            $this->get('/app/finance/payments/record')->assertForbidden();
            $this->post('/app/finance/payments/record', $this->payload())->assertForbidden();
            $this->assertSame(0, $this->paymentCount(), "{$label} must not record a payment.");
        }
    }

    #[Test]
    public function a_user_with_no_school_membership_cannot_reach_the_route(): void
    {
        $outsider = $this->createUser();
        $this->actingAs($outsider);

        // RequireSchoolContext: a mutation without a School is a 409, never
        // a success-looking redirect.
        $this->post('/app/finance/payments/record', $this->payload())->assertStatus(409);
        $this->get('/app/finance/payments/record')->assertRedirect('/app');
        $this->assertSame(0, $this->paymentCount());
    }

    #[Test]
    public function a_recorder_of_another_school_cannot_record_here_even_naming_this_schools_charges(): void
    {
        $other = $this->createSchool();
        $foreignRecorder = $this->createPaymentRecorder($other);
        $foreignAccount = $this->createLedgerAccount($other, ['type' => 'asset']);
        $this->actingAs($foreignRecorder)->post("/app/schools/{$other->id}/activate");

        // Their session School is theirs; this School's charges are not
        // visible there, whatever ids the browser sends.
        $this->post('/app/finance/payments/record', $this->payload(['settlement_ledger_account_id' => $foreignAccount->id]))
            ->assertSessionHasErrors('allocations');
        $this->assertSame(0, $this->paymentCount());
    }

    #[Test]
    public function a_platform_root_without_a_membership_cannot_record_and_elevation_is_refused(): void
    {
        $root = $this->platformAdmin();

        $this->actingAs($root);
        $this->post('/app/finance/payments/record', $this->payload())->assertStatus(409);

        $this->elevate($root, $this->school);
        $this->post('/app/finance/payments/record', $this->payload())->assertForbidden();
        $this->get('/app/finance/payments/record')->assertForbidden();
        $this->assertSame(0, $this->paymentCount());
    }

    #[Test]
    public function a_group_only_user_cannot_record_directly_or_through_group_elevation(): void
    {
        $group = $this->createGroup([$this->school]);
        $admin = $this->groupAdmin($group);

        $this->actingAs($admin);
        $this->post('/app/finance/payments/record', $this->payload())->assertStatus(409);

        $this->elevateViaGroup($admin, $group, $this->school);
        $this->post('/app/finance/payments/record', $this->payload())->assertForbidden();
        $this->assertSame(0, $this->paymentCount());
    }

    #[Test]
    public function a_suspended_school_refuses_recording(): void
    {
        $this->recorder();
        DB::table('schools')->where('id', $this->school->id)->update(['status' => 'suspended']);

        $response = $this->post('/app/finance/payments/record', $this->payload());

        // The session no longer resolves a suspended School (ADR 0047), so
        // the request never reaches the service; the service's own
        // SchoolOperationalGuard check is proven in
        // ManualPaymentRecordingServiceTest and the concurrency test.
        $response->assertStatus(409);
        $this->assertSame(0, $this->paymentCount());
    }

    // --- Validation ----------------------------------------------------------

    #[Test]
    public function the_method_must_be_in_the_closed_catalog(): void
    {
        $this->recorder();

        foreach (['card', 'upi', 'wallet', 'online_gateway', ''] as $method) {
            $this->post('/app/finance/payments/record', $this->payload(['method' => $method]))->assertSessionHasErrors('method');
        }

        $this->assertSame(0, $this->paymentCount());
    }

    #[Test]
    public function invalid_amounts_dates_references_and_accounts_are_refused_with_field_errors(): void
    {
        $this->recorder();
        $income = $this->createLedgerAccount($this->school, ['type' => 'income']);

        $cases = [
            'amount' => ['amount' => '1e3'],
            'allocations.0.amount' => ['allocations' => [['charge_id' => $this->charge->id, 'amount' => '-5.00']]],
            'occurred_on' => ['occurred_on' => now($this->school->timezone)->addDays(2)->toDateString()],
            'reference' => ['reference' => 'acct;4111111111111111'],
            'settlement_ledger_account_id' => ['settlement_ledger_account_id' => $income->id],
            'idempotency_key' => ['idempotency_key' => 'not-a-uuid'],
            'allocations' => ['allocations' => []],
        ];

        foreach ($cases as $field => $overrides) {
            $this->post('/app/finance/payments/record', $this->payload($overrides))->assertSessionHasErrors($field);
        }

        // Allocations that do not add up to the amount received.
        $this->post('/app/finance/payments/record', $this->payload(['amount' => '700.00']))->assertSessionHasErrors('amount');
        // More than a charge has outstanding.
        $this->post('/app/finance/payments/record', $this->payload(['amount' => '1100.00', 'allocations' => [['charge_id' => $this->charge->id, 'amount' => '1100.00']]]))
            ->assertSessionHasErrors('allocations');

        $this->assertSame(0, $this->paymentCount());
    }

    #[Test]
    public function a_school_id_in_the_payload_is_ignored(): void
    {
        $this->recorder();
        $other = $this->createSchool();

        $this->post('/app/finance/payments/record', $this->payload(['school_id' => $other->id]))->assertRedirect();

        $this->assertSame(1, $this->paymentCount());
        $this->assertSame(0, app(TenantContext::class)->withSchool($other, fn () => Payment::query()->count()));
    }

    #[Test]
    public function the_recording_route_is_throttled_per_user(): void
    {
        $this->recorder();

        for ($i = 0; $i < 30; $i++) {
            $this->post('/app/finance/payments/record', ['method' => 'card'])->assertSessionHasErrors();
        }

        $this->post('/app/finance/payments/record', $this->payload())->assertStatus(429);
        $this->assertSame(0, $this->paymentCount());
    }

    #[Test]
    public function a_non_uuid_payment_id_is_a_plain_404(): void
    {
        $this->createUserWithCapabilities($this->school, ['finance.payments.view']);
        $this->signInAs($this->createUserWithCapabilities($this->school, ['finance.payments.view']));

        $this->get('/app/finance/payments/not-a-uuid')->assertNotFound();
    }
}
