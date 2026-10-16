<?php

namespace Tests\Feature\App;

use App\Domain\Fees\Infrastructure\Charge;
use App\Domain\Payments\Application\Charges\ChargeStateReader;
use App\Domain\Payments\Infrastructure\PaymentCorrection;
use App\Http\Middleware\RequireSchoolContext;
use App\Models\School;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Auth\Mfa\Concerns\CreatesMfaFixtures;
use Tests\Feature\Fees\Concerns\CreatesReceiptFixtures;
use Tests\TestCase;

/**
 * UAT-C3 (UAT-FIN-05/24/27): the payment page's correction forms. Each kind
 * needs its own capabilities and a fresh authentication code; a refusal is a
 * form error, never a 500; another School's Payment is not found.
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

    private function accountant(School $school): User
    {
        return $this->withMfa($this->createUserWithCapabilities($school, ['finance.payments.view', 'finance.payments.record', 'finance.charges.view', 'finance.charges.manage']));
    }

    private function admin(School $school): User
    {
        return $this->withMfa($this->createUserWithCapabilities($school, ['finance.payments.view', 'finance.payments.record', 'finance.charges.manage', 'finance.ledger.reverse']));
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

    private function submitCorrection(string $paymentId, array $data)
    {
        return $this->post("/app/finance/payments/{$paymentId}/corrections", ['idempotency_key' => (string) Str::uuid(), 'reason' => 'Entered against the wrong charge', ...$data]);
    }

    #[Test]
    public function an_accountant_reallocates_from_the_payment_page_with_a_fresh_code(): void
    {
        $w = $this->concessionWorld();
        $b = $this->assessCharge($w['school'], $w['student'], $w['year'], $w['receivable'], $w['revenue'], '800.00', description: 'Transport');
        $paymentId = $this->pay($w, '500.00')->paymentId;
        $user = $this->enter($this->accountant($w['school']), $w['school']);

        $this->get("/app/finance/payments/{$paymentId}")->assertOk()->assertInertia(fn ($page) => $page
            ->component('App/Finance/Payments/Show')
            ->where('corrections.canReallocate', true)
            ->where('corrections.canReverseOrRefund', false)
            // Every open charge of the Student; the page leaves out the one chosen as source.
            ->where('corrections.destinations', fn ($d) => collect($d)->pluck('chargeId')->sort()->values()->all() === collect([$w['charge']->id, $b->id])->sort()->values()->all())
            ->where('corrections.refundAccounts', [])
            ->has('correctionKey'));

        $move = ['kind' => 'reallocation', 'charge_id' => $w['charge']->id, 'to_charge_id' => $b->id, 'amount' => '500.00'];
        $this->submitCorrection($paymentId, $move)->assertSessionHasErrors(['mfa_code' => 'Enter a current authentication code.']);
        $this->submitCorrection($paymentId, [...$move, 'mfa_code' => $this->code($user)])->assertRedirect("/app/finance/payments/{$paymentId}");
        $this->commitChecks($w);

        $this->assertSame('1000.00', $this->owed($w, $w['charge']));
        $this->assertSame('300.00', $this->owed($w, $b));
        $this->get("/app/finance/payments/{$paymentId}")->assertOk()->assertInertia(fn ($page) => $page
            ->where('correctedOutcome', 'recorded')
            ->where('corrections.history.0.kind', 'reallocation')
            ->where('corrections.history.0.reason', 'Entered against the wrong charge')
            ->where('corrections.standing.0.chargeId', $b->id)
            ->where('payment.allocations.0.chargeId', $w['charge']->id));
    }

    #[Test]
    public function a_double_submitted_form_replays_and_a_refusal_is_a_form_error(): void
    {
        $w = $this->concessionWorld();
        $b = $this->assessCharge($w['school'], $w['student'], $w['year'], $w['receivable'], $w['revenue'], '100.00', description: 'Books');
        $paymentId = $this->pay($w, '500.00')->paymentId;
        $user = $this->enter($this->accountant($w['school']), $w['school']);

        $this->submitCorrection($paymentId, ['kind' => 'reallocation', 'charge_id' => $w['charge']->id, 'to_charge_id' => $b->id, 'amount' => '200.00', 'mfa_code' => $this->code($user)])
            ->assertSessionHasErrors(['amount' => 'That charge does not have enough outstanding to take this amount.']);
        $this->submitCorrection($paymentId, ['kind' => 'reallocation', 'charge_id' => $w['charge']->id, 'to_charge_id' => $w['charge']->id, 'amount' => '50.00', 'mfa_code' => $this->code($user)])
            ->assertSessionHasErrors('to_charge_id');
        $this->submitCorrection($paymentId, ['kind' => 'reallocation', 'charge_id' => $w['charge']->id, 'to_charge_id' => $b->id, 'amount' => '1e2', 'mfa_code' => $this->code($user)])
            ->assertSessionHasErrors('amount');
        $this->submitCorrection($paymentId, ['kind' => 'reallocation', 'charge_id' => $w['charge']->id, 'to_charge_id' => $b->id, 'amount' => '50.00', 'reason' => 'no', 'mfa_code' => $this->code($user)])
            ->assertSessionHasErrors('reason');

        $key = (string) Str::uuid();
        $form = ['kind' => 'reallocation', 'charge_id' => $w['charge']->id, 'to_charge_id' => $b->id, 'amount' => '50.00', 'idempotency_key' => $key];
        $this->submitCorrection($paymentId, [...$form, 'mfa_code' => $this->code($user)])->assertSessionHas('finance.payment_correction_outcome', 'recorded');
        $this->submitCorrection($paymentId, [...$form, 'mfa_code' => $this->code($user)])->assertSessionHas('finance.payment_correction_outcome', 'duplicate_replay');
        $this->commitChecks($w);

        $this->assertSame(1, $this->inSchool($w['school'], fn () => PaymentCorrection::query()->count()));
    }

    #[Test]
    public function reversal_and_refund_need_ledger_reverse_and_an_accountant_is_refused(): void
    {
        $w = $this->concessionWorld();
        $paymentId = $this->pay($w, '500.00')->paymentId;
        $user = $this->enter($this->accountant($w['school']), $w['school']);

        $this->submitCorrection($paymentId, ['kind' => 'reversal', 'confirm' => '1', 'mfa_code' => $this->code($user)])->assertForbidden();
        $this->submitCorrection($paymentId, ['kind' => 'refund', 'charge_id' => $w['charge']->id, 'amount' => '100.00', 'account_id' => $w['settlement']->id,
            'method' => 'cash', 'refunded_on' => $this->today($w), 'mfa_code' => $this->code($user)])->assertForbidden();

        $viewer = $this->enter($this->withMfa($this->createUserWithCapabilities($w['school'], ['finance.payments.view'])), $w['school']);
        $this->get("/app/finance/payments/{$paymentId}")->assertOk()->assertInertia(fn ($page) => $page
            ->where('corrections.canReallocate', false)->where('corrections.canReverseOrRefund', false)->where('corrections.destinations', []));
        $this->submitCorrection($paymentId, ['kind' => 'reallocation', 'charge_id' => $w['charge']->id, 'to_charge_id' => $w['charge']->id, 'amount' => '1.00', 'mfa_code' => $this->code($viewer)])
            ->assertForbidden();

        $this->assertSame(0, $this->inSchool($w['school'], fn () => PaymentCorrection::query()->count()));
    }

    #[Test]
    public function an_admin_reverses_with_confirmation_and_the_receipt_is_kept_and_marked(): void
    {
        $w = $this->concessionWorld();
        $paymentId = $this->pay($w, '500.00')->paymentId;
        $admin = $this->enter($this->admin($w['school']), $w['school']);

        $this->submitCorrection($paymentId, ['kind' => 'reversal', 'mfa_code' => $this->code($admin)])->assertSessionHasErrors('confirm');
        $this->submitCorrection($paymentId, ['kind' => 'reversal', 'confirm' => '1', 'reason' => 'Recorded twice by mistake', 'mfa_code' => $this->code($admin)])
            ->assertRedirect("/app/finance/payments/{$paymentId}");
        $this->commitChecks($w);

        $this->assertSame('1000.00', $this->owed($w, $w['charge']));
        $this->get("/app/finance/payments/{$paymentId}")->assertOk()->assertInertia(fn ($page) => $page
            ->where('corrections.reversed.reason', 'Recorded twice by mistake')
            ->where('corrections.canReallocate', false)
            ->where('corrections.canReverseOrRefund', false)
            ->where('corrections.canRecordReplacement', true)
            ->where('corrections.studentIds', [$w['student']->id])
            ->where('corrections.standing', []));
        $this->get("/app/finance/payments/{$paymentId}/receipt")->assertOk()->assertInertia(fn ($page) => $page
            ->where('receipt.receiptNumber', $this->receiptOf($w, $paymentId)->receipt_number)
            ->where('receipt.corrections.0.kind', 'reversal')
            ->where('receipt.lines.0.amount', '500.00'));

        $this->submitCorrection($paymentId, ['kind' => 'reversal', 'confirm' => '1', 'mfa_code' => $this->code($admin)])
            ->assertSessionHasErrors(['kind' => 'This payment has been reversed. It cannot be corrected further.']);
    }

    #[Test]
    public function an_admin_refunds_part_of_a_payment_and_bad_refund_input_is_a_form_error(): void
    {
        $w = $this->concessionWorld();
        $paymentId = $this->pay($w, '500.00')->paymentId;
        $admin = $this->enter($this->admin($w['school']), $w['school']);
        $refund = ['kind' => 'refund', 'charge_id' => $w['charge']->id, 'amount' => '150.00', 'account_id' => $w['settlement']->id, 'method' => 'bank_transfer', 'refunded_on' => $this->today($w), 'reference' => 'NEFT-1'];

        $this->submitCorrection($paymentId, [...$refund, 'refunded_on' => '2099-01-01', 'mfa_code' => $this->code($admin)])->assertSessionHasErrors('refunded_on');
        $this->submitCorrection($paymentId, [...$refund, 'account_id' => $w['revenue']->id, 'mfa_code' => $this->code($admin)])->assertSessionHasErrors('account_id');
        $this->submitCorrection($paymentId, [...$refund, 'amount' => '600.00', 'mfa_code' => $this->code($admin)])->assertSessionHasErrors('amount');
        $this->submitCorrection($paymentId, [...$refund, 'mfa_code' => $this->code($admin)])->assertRedirect("/app/finance/payments/{$paymentId}");
        $this->commitChecks($w);

        $this->assertSame('650.00', $this->owed($w, $w['charge']));
        $this->get("/app/finance/payments/{$paymentId}")->assertOk()->assertInertia(fn ($page) => $page
            ->where('corrections.history.0.kind', 'refund')
            ->where('corrections.history.0.refundReference', 'NEFT-1')
            ->where('corrections.standing.0.amount', '350.00'));
    }

    #[Test]
    public function a_used_code_cannot_authorize_a_second_correction(): void
    {
        $w = $this->concessionWorld();
        $b = $this->assessCharge($w['school'], $w['student'], $w['year'], $w['receivable'], $w['revenue'], '800.00', description: 'Transport');
        $paymentId = $this->pay($w, '500.00')->paymentId;
        $user = $this->enter($this->accountant($w['school']), $w['school']);
        $code = $this->code($user);
        $move = ['kind' => 'reallocation', 'charge_id' => $w['charge']->id, 'to_charge_id' => $b->id, 'amount' => '100.00'];

        $this->submitCorrection($paymentId, [...$move, 'mfa_code' => $code])->assertSessionHas('finance.payment_correction_outcome', 'recorded');
        $this->submitCorrection($paymentId, [...$move, 'mfa_code' => $code])->assertSessionHasErrors('mfa_code');
        $this->submitCorrection($paymentId, [...$move, 'mfa_code' => '000000'])->assertSessionHasErrors('mfa_code');
        $this->commitChecks($w);

        $this->assertSame(1, $this->inSchool($w['school'], fn () => PaymentCorrection::query()->count()));
        $this->assertSame('600.00', $this->owed($w, $w['charge']));
    }

    #[Test]
    public function another_schools_payment_is_not_found_and_a_user_without_a_factor_is_told_to_enroll(): void
    {
        $w = $this->concessionWorld();
        $other = $this->concessionWorld();
        $otherPaymentId = $this->pay($other, '500.00')->paymentId;
        $admin = $this->enter($this->admin($w['school']), $w['school']);

        $this->submitCorrection($otherPaymentId, ['kind' => 'reversal', 'confirm' => '1', 'mfa_code' => $this->code($admin)])->assertNotFound();
        $this->submitCorrection('not-a-uuid', ['kind' => 'reversal', 'confirm' => '1'])->assertNotFound();

        $paymentId = $this->pay($w, '100.00')->paymentId;
        $this->enter($this->createUserWithCapabilities($w['school'], ['finance.payments.view', 'finance.ledger.reverse']), $w['school']);
        $this->submitCorrection($paymentId, ['kind' => 'reversal', 'confirm' => '1', 'mfa_code' => '123456'])
            ->assertSessionHasErrors(['mfa_code' => 'This action requires multi-factor authentication. Enroll a factor under Account security first.']);
        $this->assertSame(0, $this->inSchool($other['school'], fn () => PaymentCorrection::query()->count()));
        $this->assertSame(0, $this->inSchool($w['school'], fn () => PaymentCorrection::query()->count()));
    }
}
