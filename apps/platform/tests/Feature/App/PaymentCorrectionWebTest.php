<?php

namespace Tests\Feature\App;

use App\Domain\Fees\Infrastructure\Charge;
use App\Domain\Payments\Application\Charges\ChargeStateReader;
use App\Domain\Payments\Infrastructure\PaymentCorrection;
use App\Domain\Payments\Infrastructure\PaymentCorrectionRequest;
use App\Http\Middleware\RequireSchoolContext;
use App\Models\School;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Auth\Mfa\Concerns\CreatesMfaFixtures;
use Tests\Feature\Fees\Concerns\CreatesReceiptFixtures;
use Tests\TestCase;

/**
 * UAT-C3 (UAT-FIN-05/24/27) + ORG.8C (ADR 0076 §61): the payment page's
 * correction forms REQUEST a reallocation, reversal or refund
 * (`finance.payment_corrections.request`, no code); nothing changes until
 * someone else approves it (`.approve` + a fresh authentication code), which
 * records the correction at once. The direct correction route is gone. A
 * refusal is a form error, never a 500; another School's Payment or request
 * is not found.
 */
class PaymentCorrectionWebTest extends TestCase
{
    use CreatesMfaFixtures, CreatesReceiptFixtures;

    /** @var array<string, list<string>> */
    private array $codes = [];

    private function enter(User $user, School $school): User
    {
        $this->actingAs($user)->withSession([RequireSchoolContext::SESSION_KEY => $school->id]);

        return $user;
    }

    private function withMfa(User $user): User
    {
        $this->enrollActiveMfaFactor($user);
        $this->codes[$user->id] = $this->issueRecoveryCodes($user, 6);

        return $user;
    }

    /** The seeded accountant shape: views and requests, never approves. */
    private function requester(School $school): User
    {
        return $this->createUserWithCapabilities($school, ['finance.payments.view', 'finance.payment_corrections.view', 'finance.payment_corrections.request']);
    }

    /** The seeded School Admin shape: requests and approves. */
    private function approver(School $school): User
    {
        return $this->withMfa($this->createUserWithCapabilities($school, ['finance.payments.view', 'finance.payment_corrections.view', 'finance.payment_corrections.request', 'finance.payment_corrections.approve']));
    }

    private function code(User $user): string
    {
        return array_shift($this->codes[$user->id]);
    }

    private function owed(array $w, Charge $charge): string
    {
        return app(ChargeStateReader::class)->forCharges($w['school'], [$charge->id])[$charge->id]->outstanding()->amount();
    }

    /** Fire the deferred balance check, as a real commit would. */
    private function commitChecks(array $w): void
    {
        $this->inSchool($w['school'], function () {
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
            DB::statement('SET CONSTRAINTS ALL DEFERRED');
        });
    }

    private function submitRequest(string $paymentId, array $data)
    {
        // correction_count: the page's view of the Payment (0 = never corrected).
        return $this->post("/app/finance/payments/{$paymentId}/correction-requests", ['idempotency_key' => (string) Str::uuid(), 'reason' => 'Entered against the wrong charge', 'correction_count' => 0, ...$data]);
    }

    private function decide(string $requestId, string $decision, array $data = [])
    {
        return $this->post("/app/finance/payment-correction-requests/{$requestId}/{$decision}", $data);
    }

    private function onlyRequest(array $w): PaymentCorrectionRequest
    {
        return $this->inSchool($w['school'], fn () => PaymentCorrectionRequest::query()->sole());
    }

    private function corrections(array $w): int
    {
        return $this->inSchool($w['school'], fn () => PaymentCorrection::query()->count());
    }

    #[Test]
    public function the_direct_correction_route_is_gone(): void
    {
        $this->assertFalse(Route::has('app.finance.payments.corrections.store'));
        $w = $this->concessionWorld();
        $paymentId = $this->pay($w, '500.00')->paymentId;
        $this->enter($this->approver($w['school']), $w['school']);

        $this->post("/app/finance/payments/{$paymentId}/corrections", ['kind' => 'reversal', 'confirm' => '1', 'reason' => 'Recorded twice by mistake'])->assertNotFound();
        $this->assertSame(0, $this->corrections($w));
    }

    #[Test]
    public function a_requested_reallocation_changes_nothing_until_another_person_approves_it_with_a_fresh_code(): void
    {
        $w = $this->concessionWorld();
        $b = $this->assessCharge($w['school'], $w['student'], $w['year'], $w['receivable'], $w['revenue'], '800.00', description: 'Transport');
        $paymentId = $this->pay($w, '500.00')->paymentId;
        $requester = $this->enter($this->requester($w['school']), $w['school']);

        $this->get("/app/finance/payments/{$paymentId}")->assertOk()->assertInertia(fn ($page) => $page
            ->component('App/Finance/Payments/Show')
            ->where('corrections.canReallocate', true)
            ->where('corrections.canReverseOrRefund', true)
            // Every open charge of the Student; the page leaves out the one chosen as source.
            ->where('corrections.destinations', fn ($d) => collect($d)->pluck('chargeId')->sort()->values()->all() === collect([$w['charge']->id, $b->id])->sort()->values()->all())
            ->where('corrections.requests', [])
            ->has('correctionKey'));

        // No code is needed to request: nothing moves.
        $this->submitRequest($paymentId, ['kind' => 'reallocation', 'charge_id' => $w['charge']->id, 'to_charge_id' => $b->id, 'amount' => '500.00'])
            ->assertRedirect("/app/finance/payments/{$paymentId}")->assertSessionHas('finance.payment_correction_outcome', 'requested');
        $request = $this->onlyRequest($w);
        $this->assertSame([PaymentCorrectionRequest::PENDING, $requester->id], [$request->status, $request->requested_by_user_id]);
        $this->assertSame(0, $this->corrections($w));
        $this->assertSame('500.00', $this->owed($w, $w['charge']));
        $this->get("/app/finance/payments/{$paymentId}")->assertOk()->assertInertia(fn ($page) => $page
            ->where('correctedOutcome', 'requested')
            ->where('corrections.requests.0.status', 'pending')
            ->where('corrections.requests.0.canDecide', false)
            ->where('corrections.requests.0.canWithdraw', true));

        $approver = $this->enter($this->approver($w['school']), $w['school']);
        $this->get("/app/finance/payments/{$paymentId}")->assertOk()->assertInertia(fn ($page) => $page
            ->where('corrections.requests.0.canDecide', true)->where('corrections.requests.0.canWithdraw', false));
        $this->decide($request->id, 'approve')->assertSessionHasErrors(['mfa_code' => 'Enter a current authentication code.']);
        $this->assertSame(0, $this->corrections($w));

        $this->decide($request->id, 'approve', ['mfa_code' => $this->code($approver)])
            ->assertRedirect("/app/finance/payments/{$paymentId}")->assertSessionHas('finance.payment_correction_outcome', 'approved');
        $this->commitChecks($w);

        $this->assertSame('1000.00', $this->owed($w, $w['charge']));
        $this->assertSame('300.00', $this->owed($w, $b));
        $this->get("/app/finance/payments/{$paymentId}")->assertOk()->assertInertia(fn ($page) => $page
            ->where('correctedOutcome', 'approved')
            ->where('corrections.history.0.kind', 'reallocation')
            ->where('corrections.history.0.reason', 'Entered against the wrong charge')
            ->where('corrections.history.0.byName', $approver->name)
            ->where('corrections.requests.0.status', 'approved')
            ->where('corrections.requests.0.decidedByName', $approver->name)
            ->where('corrections.standing.0.chargeId', $b->id)
            ->where('payment.allocations.0.chargeId', $w['charge']->id));
    }

    #[Test]
    public function a_double_submitted_request_replays_and_a_bad_request_is_a_form_error(): void
    {
        $w = $this->concessionWorld();
        $b = $this->assessCharge($w['school'], $w['student'], $w['year'], $w['receivable'], $w['revenue'], '100.00', description: 'Books');
        $paymentId = $this->pay($w, '500.00')->paymentId;
        $this->enter($this->requester($w['school']), $w['school']);

        $this->submitRequest($paymentId, ['kind' => 'reallocation', 'charge_id' => $w['charge']->id, 'to_charge_id' => $w['charge']->id, 'amount' => '50.00'])->assertSessionHasErrors('to_charge_id');
        $this->submitRequest($paymentId, ['kind' => 'reallocation', 'charge_id' => $w['charge']->id, 'to_charge_id' => $b->id, 'amount' => '1e2'])->assertSessionHasErrors('amount');
        $this->submitRequest($paymentId, ['kind' => 'reallocation', 'charge_id' => $w['charge']->id, 'to_charge_id' => $b->id, 'amount' => '50.00', 'reason' => 'no'])->assertSessionHasErrors('reason');
        $this->submitRequest($paymentId, ['kind' => 'reversal'])->assertSessionHasErrors('confirm');
        $this->submitRequest($paymentId, ['kind' => 'sideways'])->assertSessionHasErrors('kind');

        $key = (string) Str::uuid();
        $form = ['kind' => 'reallocation', 'charge_id' => $w['charge']->id, 'to_charge_id' => $b->id, 'amount' => '50.00', 'idempotency_key' => $key];
        $this->submitRequest($paymentId, $form)->assertSessionHas('finance.payment_correction_outcome', 'requested');
        $this->submitRequest($paymentId, $form)->assertSessionHas('finance.payment_correction_outcome', 'duplicate_replay');
        $this->submitRequest($paymentId, [...$form, 'amount' => '60.00'])->assertSessionHasErrors('idempotency_key');

        $this->assertSame(1, $this->inSchool($w['school'], fn () => PaymentCorrectionRequest::query()->count()));
        $this->assertSame(0, $this->corrections($w));
    }

    #[Test]
    public function an_approval_the_executor_refuses_is_a_form_error_and_the_request_stays_pending_until_rejected(): void
    {
        $w = $this->concessionWorld();
        $b = $this->assessCharge($w['school'], $w['student'], $w['year'], $w['receivable'], $w['revenue'], '100.00', description: 'Books');
        $paymentId = $this->pay($w, '500.00')->paymentId;
        $this->enter($this->requester($w['school']), $w['school']);
        $this->submitRequest($paymentId, ['kind' => 'reallocation', 'charge_id' => $w['charge']->id, 'to_charge_id' => $b->id, 'amount' => '200.00']);
        $request = $this->onlyRequest($w);

        $approver = $this->enter($this->approver($w['school']), $w['school']);
        $this->decide($request->id, 'approve', ['mfa_code' => $this->code($approver)])
            ->assertSessionHasErrors(['correction_request' => 'That charge does not have enough outstanding to take this amount.']);
        $this->assertSame(PaymentCorrectionRequest::PENDING, $this->inSchool($w['school'], fn () => $request->refresh()->status), 'never approved without its correction');
        $this->assertSame(0, $this->corrections($w));

        $this->decide($request->id, 'reject')->assertSessionHas('finance.payment_correction_outcome', 'rejected');
        $this->assertSame([PaymentCorrectionRequest::REJECTED, $approver->id], $this->inSchool($w['school'], fn () => [$request->refresh()->status, $request->decided_by_user_id]));
        $this->decide($request->id, 'approve', ['mfa_code' => $this->code($approver)])->assertSessionHasErrors(['correction_request' => 'This correction request is already rejected.']);
        $this->assertSame(0, $this->corrections($w));
    }

    #[Test]
    public function nobody_decides_their_own_request_and_only_the_requester_withdraws(): void
    {
        $w = $this->concessionWorld();
        $paymentId = $this->pay($w, '500.00')->paymentId;
        $admin = $this->enter($this->approver($w['school']), $w['school']);
        $this->submitRequest($paymentId, ['kind' => 'reversal', 'confirm' => '1', 'reason' => 'Recorded twice by mistake']);
        $request = $this->onlyRequest($w);

        $this->get("/app/finance/payments/{$paymentId}")->assertOk()->assertInertia(fn ($page) => $page
            ->where('corrections.requests.0.canDecide', false)->where('corrections.requests.0.canWithdraw', true));
        $this->decide($request->id, 'approve', ['mfa_code' => $this->code($admin)])
            ->assertSessionHasErrors(['correction_request' => 'You requested this correction; another person must decide it.']);
        $this->decide($request->id, 'reject')->assertSessionHasErrors('correction_request');

        $other = $this->enter($this->approver($w['school']), $w['school']);
        $this->decide($request->id, 'withdraw')->assertSessionHasErrors(['correction_request' => 'Only the person who requested this correction can withdraw it.']);

        $this->enter($admin, $w['school']);
        $this->decide($request->id, 'withdraw')->assertSessionHas('finance.payment_correction_outcome', 'withdrawn');
        $this->enter($other, $w['school']);
        $this->decide($request->id, 'approve', ['mfa_code' => $this->code($other)])->assertSessionHasErrors(['correction_request' => 'This correction request is already withdrawn.']);

        $this->assertSame(0, $this->corrections($w));
        $this->assertSame('500.00', $this->owed($w, $w['charge']));
    }

    #[Test]
    public function capabilities_gate_every_step(): void
    {
        $w = $this->concessionWorld();
        $paymentId = $this->pay($w, '500.00')->paymentId;

        // A Payments viewer -- and the old correction capabilities -- request nothing and decide nothing.
        foreach ([['finance.payments.view'], ['finance.payments.view', 'finance.payments.record', 'finance.charges.manage', 'finance.ledger.reverse']] as $capabilities) {
            $this->enter($this->withMfa($this->createUserWithCapabilities($w['school'], $capabilities)), $w['school']);
            $this->get("/app/finance/payments/{$paymentId}")->assertOk()->assertInertia(fn ($page) => $page
                ->where('corrections.canReallocate', false)->where('corrections.canReverseOrRefund', false)
                ->where('corrections.destinations', [])->where('corrections.requests', null));
            $this->submitRequest($paymentId, ['kind' => 'reversal', 'confirm' => '1'])->assertForbidden();
            $this->get('/app/finance/payment-correction-requests')->assertForbidden();
        }

        $requester = $this->enter($this->requester($w['school']), $w['school']);
        $this->submitRequest($paymentId, ['kind' => 'reversal', 'confirm' => '1', 'reason' => 'Recorded twice by mistake']);
        $request = $this->onlyRequest($w);

        // A requester (the accountant shape) never decides anyone's request.
        $this->enter($this->requester($w['school']), $w['school']);
        $this->decide($request->id, 'approve', ['mfa_code' => '123456'])->assertForbidden();
        $this->decide($request->id, 'reject')->assertForbidden();

        // An approver without request authority cannot withdraw (it is not theirs anyway).
        $this->enter($this->withMfa($this->createUserWithCapabilities($w['school'], ['finance.payments.view', 'finance.payment_corrections.approve'])), $w['school']);
        $this->decide($request->id, 'withdraw')->assertForbidden();

        $this->assertSame([PaymentCorrectionRequest::PENDING, $requester->id], $this->inSchool($w['school'], fn () => [$request->refresh()->status, $request->requested_by_user_id]));
        $this->assertSame(0, $this->corrections($w));
    }

    #[Test]
    public function an_approved_reversal_keeps_and_marks_the_receipt(): void
    {
        $w = $this->concessionWorld();
        $paymentId = $this->pay($w, '500.00')->paymentId;
        $this->enter($this->requester($w['school']), $w['school']);
        $this->submitRequest($paymentId, ['kind' => 'reversal', 'confirm' => '1', 'reason' => 'Recorded twice by mistake'])->assertRedirect("/app/finance/payments/{$paymentId}");
        $request = $this->onlyRequest($w);

        $approver = $this->enter($this->approver($w['school']), $w['school']);
        $this->decide($request->id, 'approve', ['mfa_code' => $this->code($approver)])->assertSessionHas('finance.payment_correction_outcome', 'approved');
        $this->commitChecks($w);

        $this->assertSame('1000.00', $this->owed($w, $w['charge']));
        $this->get("/app/finance/payments/{$paymentId}")->assertOk()->assertInertia(fn ($page) => $page
            ->where('corrections.reversed.reason', 'Recorded twice by mistake')
            ->where('corrections.canReallocate', false)
            ->where('corrections.canReverseOrRefund', false)
            ->where('corrections.studentIds', [$w['student']->id])
            ->where('corrections.standing', []));
        $this->get("/app/finance/payments/{$paymentId}/receipt")->assertOk()->assertInertia(fn ($page) => $page
            ->where('receipt.receiptNumber', $this->receiptOf($w, $paymentId)->receipt_number)
            ->where('receipt.corrections.0.kind', 'reversal')
            ->where('receipt.lines.0.amount', '500.00'));

        $this->submitRequest($paymentId, ['kind' => 'reversal', 'confirm' => '1'])
            ->assertSessionHasErrors(['kind' => 'This payment has been reversed. It cannot be corrected further.']);
    }

    #[Test]
    public function an_approved_refund_records_its_terms_and_a_second_request_on_the_same_payment_goes_stale(): void
    {
        $w = $this->concessionWorld();
        $paymentId = $this->pay($w, '500.00')->paymentId;
        $this->enter($this->requester($w['school']), $w['school']);
        $refund = ['kind' => 'refund', 'charge_id' => $w['charge']->id, 'amount' => '150.00', 'account_id' => $w['settlement']->id, 'method' => 'bank_transfer', 'refunded_on' => $this->today($w), 'reference' => 'NEFT-1'];
        $this->submitRequest($paymentId, $refund);
        $this->submitRequest($paymentId, [...$refund, 'amount' => '100.00', 'reference' => 'NEFT-2']);
        [$first, $second] = $this->inSchool($w['school'], fn () => PaymentCorrectionRequest::query()->orderBy('amount', 'desc')->get()->all());

        $approver = $this->enter($this->approver($w['school']), $w['school']);
        $this->decide($first->id, 'approve', ['mfa_code' => $this->code($approver)])->assertSessionHas('finance.payment_correction_outcome', 'approved');
        $this->commitChecks($w);
        $this->assertSame('650.00', $this->owed($w, $w['charge']));

        // The Payment changed after the second request was made: it must be requested again.
        $this->decide($second->id, 'approve', ['mfa_code' => $this->code($approver)])->assertSessionHasErrors('correction_request');
        $this->assertStringContainsString('changed after this correction was requested', session('errors')->first('correction_request'));
        $this->assertSame(PaymentCorrectionRequest::PENDING, $this->inSchool($w['school'], fn () => $second->refresh()->status));
        $this->assertSame(1, $this->corrections($w));

        $this->get("/app/finance/payments/{$paymentId}")->assertOk()->assertInertia(fn ($page) => $page
            ->where('corrections.history.0.kind', 'refund')
            ->where('corrections.history.0.refundReference', 'NEFT-1')
            ->where('corrections.standing.0.amount', '350.00'));

        // A page that still shows the uncorrected Payment cannot request against the corrected one.
        $this->enter($this->requester($w['school']), $w['school']);
        $this->submitRequest($paymentId, [...$refund, 'amount' => '10.00', 'correction_count' => 0])
            ->assertSessionHasErrors(['kind' => "Payment '{$paymentId}' changed after this correction was requested. Request it again."]);
        $this->submitRequest($paymentId, [...$refund, 'amount' => '10.00', 'correction_count' => 1])->assertSessionHas('finance.payment_correction_outcome', 'requested');
        $this->submitRequest($paymentId, [...$refund, 'amount' => '10.00', 'correction_count' => 1, 'charge_id' => (string) Str::uuid()])
            ->assertSessionHasErrors(['charge_id' => 'One of the selected charges or accounts was not found.']);
        $this->submitRequest($paymentId, [...$refund, 'amount' => '10.00', 'correction_count' => 1, 'account_id' => (string) Str::uuid()])
            ->assertSessionHasErrors('charge_id');
        $this->submitRequest($paymentId, [...$refund, 'correction_count' => null])->assertSessionHasErrors('correction_count');
    }

    #[Test]
    public function a_used_code_cannot_approve_a_second_request(): void
    {
        $w = $this->concessionWorld();
        $b = $this->assessCharge($w['school'], $w['student'], $w['year'], $w['receivable'], $w['revenue'], '800.00', description: 'Transport');
        $paymentId = $this->pay($w, '500.00')->paymentId;
        $other = $this->pay($w, '100.00', charge: $b)->paymentId;
        $this->enter($this->requester($w['school']), $w['school']);
        $this->submitRequest($paymentId, ['kind' => 'reallocation', 'charge_id' => $w['charge']->id, 'to_charge_id' => $b->id, 'amount' => '100.00']);
        $this->submitRequest($other, ['kind' => 'reversal', 'confirm' => '1']);
        [$move, $reverse] = $this->inSchool($w['school'], fn () => [
            PaymentCorrectionRequest::query()->where('payment_id', $paymentId)->sole(),
            PaymentCorrectionRequest::query()->where('payment_id', $other)->sole(),
        ]);

        $approver = $this->enter($this->approver($w['school']), $w['school']);
        $code = $this->code($approver);
        $this->decide($move->id, 'approve', ['mfa_code' => $code])->assertSessionHas('finance.payment_correction_outcome', 'approved');
        $this->decide($reverse->id, 'approve', ['mfa_code' => $code])->assertSessionHasErrors('mfa_code');
        $this->decide($reverse->id, 'approve', ['mfa_code' => '000000'])->assertSessionHasErrors('mfa_code');
        $this->commitChecks($w);

        $this->assertSame(1, $this->corrections($w));
        $this->assertSame(PaymentCorrectionRequest::PENDING, $this->inSchool($w['school'], fn () => $reverse->refresh()->status));
    }

    #[Test]
    public function the_queue_lists_requests_minimally_and_another_schools_payment_or_request_is_not_found(): void
    {
        $w = $this->concessionWorld();
        $other = $this->concessionWorld();
        $paymentId = $this->pay($w, '500.00')->paymentId;
        $otherPaymentId = $this->pay($other, '500.00')->paymentId;
        $this->enter($this->requester($other['school']), $other['school']);
        $this->submitRequest($otherPaymentId, ['kind' => 'reversal', 'confirm' => '1']);
        $otherRequest = $this->onlyRequest($other);

        $this->enter($this->requester($w['school']), $w['school']);
        $this->submitRequest($paymentId, ['kind' => 'reversal', 'confirm' => '1', 'reason' => 'Recorded twice by mistake']);
        $this->submitRequest($otherPaymentId, ['kind' => 'reversal', 'confirm' => '1'])->assertNotFound();
        $this->submitRequest('not-a-uuid', ['kind' => 'reversal', 'confirm' => '1'])->assertNotFound();

        $approver = $this->enter($this->approver($w['school']), $w['school']);
        $this->get('/app/finance/payment-correction-requests')->assertOk()->assertInertia(fn ($page) => $page
            ->component('App/Finance/PaymentCorrectionRequests/Index')
            ->where('filters.status', 'pending')
            ->has('requests.data', 1)
            ->where('requests.data.0.paymentId', $paymentId)
            ->where('requests.data.0.kind', 'reversal')
            ->where('requests.data.0.paymentAmount', '500.00')
            ->where('requests.data.0.canDecide', true)
            ->missing('requests.data.0.studentId')
            ->missing('requests.data.0.studentName'));
        $this->get('/app/finance/payment-correction-requests?status=approved')->assertOk()->assertInertia(fn ($page) => $page->has('requests.data', 0));

        $this->decide($otherRequest->id, 'approve', ['mfa_code' => $this->code($approver)])->assertNotFound();
        $this->decide($otherRequest->id, 'reject')->assertNotFound();
        $this->decide('not-a-uuid', 'reject')->assertNotFound();
        $this->assertSame(PaymentCorrectionRequest::PENDING, $this->inSchool($other['school'], fn () => $otherRequest->refresh()->status));
        $this->assertSame(0, $this->corrections($other));
    }

    #[Test]
    public function an_approver_without_a_factor_is_told_to_enroll(): void
    {
        $w = $this->concessionWorld();
        $paymentId = $this->pay($w, '100.00')->paymentId;
        $this->enter($this->requester($w['school']), $w['school']);
        $this->submitRequest($paymentId, ['kind' => 'reversal', 'confirm' => '1']);

        $this->enter($this->createUserWithCapabilities($w['school'], ['finance.payments.view', 'finance.payment_corrections.approve']), $w['school']);
        $this->decide($this->onlyRequest($w)->id, 'approve', ['mfa_code' => '123456'])
            ->assertSessionHasErrors(['mfa_code' => 'This action requires multi-factor authentication. Enroll a factor under Account security first.']);
        $this->assertSame(0, $this->corrections($w));
    }
}
