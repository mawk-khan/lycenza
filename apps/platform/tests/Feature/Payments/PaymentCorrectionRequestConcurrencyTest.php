<?php

namespace Tests\Feature\Payments;

use App\Domain\Payments\Application\PaymentCorrectionData;
use App\Domain\Payments\Application\PaymentCorrectionRequestService;
use App\Domain\Payments\Domain\ManualPaymentMethod;
use App\Domain\Payments\Infrastructure\PaymentCorrection;
use App\Domain\Payments\Infrastructure\PaymentCorrectionRequest;
use App\Models\School;
use App\Models\User;
use App\Support\Money\Money;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ForcesConcurrentOverlap;
use Tests\Feature\Fees\Concerns\CreatesReceiptFixtures;
use Tests\TestCase;

/**
 * ORG.8C (ADR 0076 §61): committed, real PostgreSQL, two OS processes; the
 * contender is observed BLOCKED (on the request row, or on the Payment's
 * correction lock) before the holder commits:
 *
 *   A  two approvals of one request          -> one approval, one correction
 *   B  approve (held) vs reject              -> the rejection finds it decided
 *   C  withdraw (held) vs approve            -> the approval finds it withdrawn, nothing executes
 *   D  approve refund 1 (held) vs approve 2  -> request 2 is stale (same Payment)
 *   E  core reversal (held) vs approve        -> the approval finds the Payment reversed
 *
 * Never: two corrections from one request, or a correction executed against
 * a Payment that changed after it was requested.
 */
class PaymentCorrectionRequestConcurrencyTest extends TestCase
{
    use CreatesReceiptFixtures, ForcesConcurrentOverlap;

    /** @var array<int, string> */
    protected $connectionsToTransact = [];

    private ?School $school = null;

    protected function tearDown(): void
    {
        if ($this->school !== null) {
            $this->purgeSchoolsAsAdmin([$this->school->id]);
        }

        parent::tearDown();
    }

    /** @return array<string, mixed> */
    private function world(): array
    {
        $w = $this->concessionWorld();
        $this->school = $w['school'];
        $w['requester'] = $this->createUserWithCapabilities($w['school'], ['finance.payments.view', 'finance.payment_corrections.view', 'finance.payment_corrections.request']);
        $w['paymentId'] = $this->pay($w, '500.00')->paymentId;

        return $w;
    }

    private function approver(array $w): User
    {
        return $this->createUserWithCapabilities($w['school'], ['finance.payments.view', 'finance.payment_corrections.view', 'finance.payment_corrections.request', 'finance.payment_corrections.approve']);
    }

    private function reversalRequest(array $w): PaymentCorrectionRequest
    {
        return app(PaymentCorrectionRequestService::class)->request($w['school'], new PaymentCorrectionData(
            paymentId: $w['paymentId'], kind: PaymentCorrectionData::REVERSAL, reason: 'Recorded twice by mistake', idempotencyKey: (string) Str::uuid(),
        ), $w['requester'])['request'];
    }

    private function refundRequest(array $w): PaymentCorrectionRequest
    {
        return app(PaymentCorrectionRequestService::class)->request($w['school'], new PaymentCorrectionData(
            paymentId: $w['paymentId'], kind: PaymentCorrectionData::REFUND, reason: 'Paid back in part', idempotencyKey: (string) Str::uuid(),
            chargeId: $w['charge']->id, amount: Money::of('50.00', 'INR'), accountId: $w['settlement']->id,
            method: ManualPaymentMethod::Cash, refundedOn: $this->today($w),
        ), $w['requester'])['request'];
    }

    /** @return array<int, string> */
    private function op(array $w, string $operation, string $targetId, User $actor): array
    {
        return ['php', __DIR__.'/../../Support/payment-correction-request-op.php', $operation, $w['school']->id, $targetId, $actor->id];
    }

    private function corrections(array $w): int
    {
        return $this->inSchool($w['school'], fn () => PaymentCorrection::query()->where('payment_id', $w['paymentId'])->count());
    }

    private function statusOf(array $w, PaymentCorrectionRequest $request): string
    {
        return $this->inSchool($w['school'], fn () => $request->refresh()->status);
    }

    #[Test]
    public function two_approvals_of_one_request_make_one_correction(): void
    {
        $w = $this->world();
        $request = $this->reversalRequest($w);

        [$holder, $contender] = $this->raceWithHeldHolder($this->op($w, 'approve', $request->id, $this->approver($w)), $this->op($w, 'approve', $request->id, $this->approver($w)));

        $this->assertSame('approved', $holder);
        $this->assertSame('rejected:PaymentCorrectionRequestRefusedException', $contender);
        $this->assertSame(1, $this->corrections($w));
    }

    #[Test]
    public function a_rejection_waiting_on_an_approval_finds_the_request_decided(): void
    {
        $w = $this->world();
        $request = $this->reversalRequest($w);

        [$holder, $contender] = $this->raceWithHeldHolder($this->op($w, 'approve', $request->id, $this->approver($w)), $this->op($w, 'reject', $request->id, $this->approver($w)));

        $this->assertSame(['approved', 'rejected:PaymentCorrectionRequestRefusedException'], [$holder, $contender]);
        $this->assertSame(PaymentCorrectionRequest::APPROVED, $this->statusOf($w, $request));
        $this->assertSame(1, $this->corrections($w));
    }

    #[Test]
    public function an_approval_waiting_on_a_withdrawal_executes_nothing(): void
    {
        $w = $this->world();
        $request = $this->reversalRequest($w);

        [$holder, $contender] = $this->raceWithHeldHolder($this->op($w, 'withdraw', $request->id, $w['requester']), $this->op($w, 'approve', $request->id, $this->approver($w)));

        $this->assertSame(['withdrawn', 'rejected:PaymentCorrectionRequestRefusedException'], [$holder, $contender]);
        $this->assertSame(0, $this->corrections($w));
    }

    #[Test]
    public function a_second_request_on_the_same_payment_waits_and_goes_stale(): void
    {
        $w = $this->world();
        $first = $this->refundRequest($w);
        $second = $this->refundRequest($w);

        [$holder, $contender] = $this->raceWithHeldHolder($this->op($w, 'approve', $first->id, $this->approver($w)), $this->op($w, 'approve', $second->id, $this->approver($w)));

        $this->assertSame('approved', $holder);
        // Waited on the Payment's correction lock, then re-read the Payment: a new generation.
        $this->assertSame('rejected:PaymentCorrectionRequestStaleException', $contender);
        $this->assertSame(PaymentCorrectionRequest::PENDING, $this->statusOf($w, $second));
        $this->assertSame(1, $this->corrections($w));
    }

    #[Test]
    public function an_approval_waiting_on_a_concurrent_payment_correction_is_refused(): void
    {
        $w = $this->world();
        $request = $this->reversalRequest($w);
        $core = $this->createUserWithCapabilities($w['school'], ['finance.payments.view', 'finance.ledger.reverse']);

        [$holder, $contender] = $this->raceWithHeldHolder($this->op($w, 'reverse', $w['paymentId'], $core), $this->op($w, 'approve', $request->id, $this->approver($w)));

        $this->assertSame('reversed', $holder);
        // The Payment is reversed (final) by the time the approval reads it under the lock.
        $this->assertSame('rejected:PaymentCorrectionRefusedException', $contender);
        $this->assertSame(PaymentCorrectionRequest::PENDING, $this->statusOf($w, $request));
        $this->assertSame(1, $this->corrections($w));
    }
}
