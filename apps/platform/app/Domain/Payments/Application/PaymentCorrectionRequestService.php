<?php

namespace App\Domain\Payments\Application;

use App\Domain\Fees\Application\ChargeService;
use App\Domain\Guardians\Application\GuardianBeneficiaryRelationship;
use App\Domain\Payments\Application\Exceptions\PaymentCorrectionRefusedException;
use App\Domain\Payments\Application\Exceptions\PaymentCorrectionRequestRefusedException;
use App\Domain\Payments\Application\Exceptions\PaymentCorrectionRequestStaleException;
use App\Domain\Payments\Application\Exceptions\PaymentNotFoundException;
use App\Domain\Payments\Domain\ManualPaymentMethod;
use App\Domain\Payments\Infrastructure\Payment;
use App\Domain\Payments\Infrastructure\PaymentAllocation;
use App\Domain\Payments\Infrastructure\PaymentCorrection;
use App\Domain\Payments\Infrastructure\PaymentCorrectionLine;
use App\Domain\Payments\Infrastructure\PaymentCorrectionRequest;
use App\Models\School;
use App\Models\User;
use App\Support\Approval\ApprovalDecisionAuthority;
use App\Support\Approval\ApprovalParticipants;
use App\Support\Approval\ApprovalProvenance;
use App\Support\Approval\MakerCheckerPolicy;
use App\Support\Audit\AuditRecorder;
use App\Support\Auth\Mfa\FreshMfaProof;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Money\Money;
use App\Support\Tenancy\SchoolOperationalGuard;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * ORG.8C (ADR 0076 §61): the one human path to a Payment correction --
 * reallocation, reversal or recorded refund is REQUESTED, then decided by
 * someone else, and an approval executes it at once through
 * `PaymentCorrectionService::executeApproved()` in the same transaction.
 *
 * This service owns the request, the decision, maker/checker, the beneficiary
 * conflict and staleness; the correction service owns the money (locks,
 * plans, ledger, lines, receipts' state, audit of the correction). Whole-School
 * only (`finance.payment_corrections.*`, never Campus-adopted).
 *
 * A decision (approve or reject) runs: request FOR UPDATE -> pending ->
 * the decider's CURRENT authority (`ApprovalDecisionAuthority`) -> shared
 * maker/checker over `participants()` (the requester: only they author the
 * frozen terms) -> the beneficiary conflict (never a Guardian of any Student
 * the Payment or the request touches, ORG.8A-R1's structural relationship)
 * -> approve: execute under the Payment lock against the requested generation
 * (stale otherwise) -> record the decision -> audit. Any refusal rolls the
 * whole decision back: a request is never approved without its correction.
 * The database repeats every rule on the recorded decider.
 */
class PaymentCorrectionRequestService
{
    use AuthorizesCapability;

    public const string WORKFLOW = 'payment_corrections';

    public const string VIEW = 'finance.payment_corrections.view';

    public const string REQUEST = 'finance.payment_corrections.request';

    public const string APPROVE = 'finance.payment_corrections.approve';

    public function __construct(
        private readonly PaymentCorrectionService $corrections,
        private readonly ChargeService $charges,
        private readonly GuardianBeneficiaryRelationship $beneficiary,
        private readonly ApprovalDecisionAuthority $authority,
        private readonly MakerCheckerPolicy $makerChecker,
        private readonly SchoolOperationalGuard $operational,
        private readonly AuditRecorder $audit,
        private readonly TenantContext $context,
    ) {}

    /** The request's material participants: its requester, the only author of its frozen terms. */
    public static function participants(PaymentCorrectionRequest $request): ApprovalParticipants
    {
        return new ApprovalParticipants(self::WORKFLOW, $request->id, ['requester' => [$request->requested_by_user_id]]);
    }

    /**
     * `$seenCorrections`: how many corrections the Payment had on the page the
     * requester submitted from. When given, the request is refused as stale if
     * the Payment was corrected since, so it never binds a generation its
     * requester did not see.
     *
     * @return array{request: PaymentCorrectionRequest, replayed: bool}
     */
    public function request(School $school, PaymentCorrectionData $data, User $actor, ?int $seenCorrections = null): array
    {
        $this->authorizeCapabilityFor($actor, self::REQUEST, $school);
        $this->assertValidTerms($data);

        return $this->context->withSchool($school, fn (): array => DB::transaction(function () use ($school, $data, $actor, $seenCorrections): array {
            $this->operational->requireOperational($school->id);
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ["payments.correction_request:{$school->id}:{$data->idempotencyKey}"]);

            $existing = PaymentCorrectionRequest::query()->where('school_id', $school->id)->where('idempotency_key', $data->idempotencyKey)->first();
            if ($existing !== null) {
                if (! $this->sameRequest($existing, $data, $actor)) {
                    throw PaymentCorrectionRequestRefusedException::idempotencyConflict();
                }

                return ['request' => $existing, 'replayed' => true];
            }

            $payment = Payment::query()->where('school_id', $school->id)->find($data->paymentId) ?? throw new PaymentNotFoundException($data->paymentId);
            // The correction lock: the generation read here cannot move until commit.
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ["payments.correct-payment:{$school->id}:{$payment->id}"]);
            if (PaymentCorrection::query()->where('school_id', $school->id)->where('payment_id', $payment->id)->where('kind', PaymentCorrection::REVERSAL)->exists()) {
                throw PaymentCorrectionRefusedException::alreadyReversed();
            }
            $generation = PaymentCorrection::query()->where('school_id', $school->id)->where('payment_id', $payment->id)->count();
            if ($seenCorrections !== null && $seenCorrections !== $generation) {
                throw new PaymentCorrectionRequestStaleException($payment->id);
            }

            try {
                $request = $this->create($school, $payment, $data, $actor, $generation);
            } catch (QueryException $e) {
                // A charge or account that is not this School's (composite keys).
                if ($e->getCode() === '23503') {
                    throw PaymentCorrectionRequestRefusedException::referenceInvalid();
                }

                throw $e;
            }

            $this->audit->school($school, 'payment_correction_request.requested', actor: $actor, subject: $request, metadata: [
                'paymentCorrectionRequestId' => $request->id,
                'paymentId' => $payment->id,
                'kind' => $request->kind,
                ...ApprovalProvenance::metadata(self::participants($request), ApprovalProvenance::REQUESTED),
            ]);

            return ['request' => $request->refresh(), 'replayed' => false];
        }));
    }

    private function create(School $school, Payment $payment, PaymentCorrectionData $data, User $actor, int $generation): PaymentCorrectionRequest
    {
        return PaymentCorrectionRequest::query()->create([
            'school_id' => $school->id,
            'payment_id' => $payment->id,
            'kind' => $data->kind,
            'charge_id' => $data->kind === PaymentCorrection::REVERSAL ? null : $data->chargeId,
            'to_charge_id' => $data->kind === PaymentCorrection::REALLOCATION ? $data->toChargeId : null,
            'amount' => $data->kind === PaymentCorrection::REVERSAL ? null : $data->amount?->amount(),
            'currency' => $payment->currency,
            'settlement_ledger_account_id' => $data->kind === PaymentCorrection::REFUND ? $data->accountId : null,
            'refund_method' => $data->kind === PaymentCorrection::REFUND ? $data->method?->value : null,
            'refund_reference' => $data->kind === PaymentCorrection::REFUND ? $this->reference($data->reference) : null,
            'refunded_on' => $data->kind === PaymentCorrection::REFUND ? $data->refundedOn : null,
            'reason' => trim($data->reason),
            'prior_correction_count' => $generation,
            'status' => PaymentCorrectionRequest::PENDING,
            'requested_by_user_id' => $actor->id,
            'idempotency_key' => $data->idempotencyKey,
        ]);
    }

    /** `$mfa`: the fresh code the transport verified for $approver, recorded as provenance. */
    public function approve(School $school, string $requestId, User $approver, ?FreshMfaProof $mfa = null): PaymentCorrectionRequest
    {
        $this->authorizeCapabilityFor($approver, self::APPROVE, $school);

        return $this->decide($school, $requestId, function (PaymentCorrectionRequest $request) use ($school, $approver, $mfa): void {
            $this->assertMayDecide($school, $request, $approver);

            $executed = $this->corrections->executeApproved($school, $this->dataFor($request), $approver, $request->prior_correction_count);

            $request->forceFill([
                'status' => PaymentCorrectionRequest::APPROVED,
                'decided_by_user_id' => $approver->id,
                'decided_at' => now(),
                'payment_correction_id' => $executed['correctionId'],
            ])->save();

            $this->audit->school($school, 'payment_correction_request.approved', actor: $approver, subject: $request, metadata: [
                'paymentCorrectionRequestId' => $request->id,
                'paymentId' => $request->payment_id,
                'kind' => $request->kind,
                'paymentCorrectionId' => $executed['correctionId'],
                ...ApprovalProvenance::metadata(self::participants($request), ApprovalProvenance::APPROVED, $approver, 'school', $mfa),
            ]);
        });
    }

    public function reject(School $school, string $requestId, User $decider): PaymentCorrectionRequest
    {
        $this->authorizeCapabilityFor($decider, self::APPROVE, $school);

        return $this->decide($school, $requestId, function (PaymentCorrectionRequest $request) use ($school, $decider): void {
            $this->assertMayDecide($school, $request, $decider);

            $request->forceFill([
                'status' => PaymentCorrectionRequest::REJECTED,
                'decided_by_user_id' => $decider->id,
                'decided_at' => now(),
            ])->save();

            $this->audit->school($school, 'payment_correction_request.rejected', actor: $decider, subject: $request, metadata: [
                'paymentCorrectionRequestId' => $request->id,
                'paymentId' => $request->payment_id,
                'kind' => $request->kind,
                ...ApprovalProvenance::metadata(self::participants($request), ApprovalProvenance::REJECTED, $decider, 'school'),
            ]);
        });
    }

    public function withdraw(School $school, string $requestId, User $actor): PaymentCorrectionRequest
    {
        $this->authorizeCapabilityFor($actor, self::REQUEST, $school);

        return $this->decide($school, $requestId, function (PaymentCorrectionRequest $request) use ($school, $actor): void {
            if ($request->requested_by_user_id !== $actor->id) {
                throw PaymentCorrectionRequestRefusedException::notRequester();
            }
            $request->forceFill(['status' => PaymentCorrectionRequest::WITHDRAWN, 'withdrawn_at' => now(), 'withdrawn_by_user_id' => $actor->id])->save();

            $this->audit->school($school, 'payment_correction_request.withdrawn', actor: $actor, subject: $request, metadata: [
                'paymentCorrectionRequestId' => $request->id,
                'paymentId' => $request->payment_id,
                'kind' => $request->kind,
            ]);
        });
    }

    /**
     * Every Student whose Charges the Payment (its allocations and earlier
     * correction lines) or the request's own Charges touch -- the database
     * guard resolves the same set.
     *
     * @return list<string>
     */
    public function affectedStudentIds(School $school, PaymentCorrectionRequest $request): array
    {
        return $this->context->withSchool($school, function () use ($school, $request): array {
            $chargeIds = PaymentAllocation::query()->where('school_id', $school->id)->where('payment_id', $request->payment_id)->pluck('charge_id')
                ->merge(PaymentCorrectionLine::query()->where('school_id', $school->id)->where('payment_id', $request->payment_id)->pluck('charge_id'))
                ->merge(array_filter([$request->charge_id, $request->to_charge_id]))
                ->map(fn ($id): string => (string) $id)->unique()->values()->all();

            return array_values(array_unique($this->charges->studentIdsForCharges($school, $chargeIds)));
        });
    }

    /** @param callable(PaymentCorrectionRequest): void $transition */
    private function decide(School $school, string $requestId, callable $transition): PaymentCorrectionRequest
    {
        return $this->context->withSchool($school, fn (): PaymentCorrectionRequest => DB::transaction(function () use ($school, $requestId, $transition): PaymentCorrectionRequest {
            $this->operational->requireOperational($school->id);
            $request = Str::isUuid($requestId)
                ? PaymentCorrectionRequest::query()->where('school_id', $school->id)->lockForUpdate()->find($requestId)
                : null;
            if ($request === null) {
                throw PaymentCorrectionRequestRefusedException::notFound();
            }
            if ($request->status !== PaymentCorrectionRequest::PENDING) {
                throw PaymentCorrectionRequestRefusedException::notPending($request->status);
            }

            $transition($request);

            return $request->refresh();
        }));
    }

    /**
     * Under the request lock: the decider's current whole-School authority,
     * then maker/checker (backed by `payment_correction_requests_sod_check`),
     * then the beneficiary conflict (backed by
     * `payment_correction_requests_beneficiary_guardian_guard`).
     */
    private function assertMayDecide(School $school, PaymentCorrectionRequest $request, User $decider): void
    {
        $this->authority->requireCurrent($decider, self::APPROVE, $school);

        if (! $this->makerChecker->permits($decider, self::participants($request))) {
            throw PaymentCorrectionRequestRefusedException::ownRequest();
        }

        if ($this->beneficiary->isGuardianOfAny($decider, $school, $this->affectedStudentIds($school, $request))) {
            throw PaymentCorrectionRequestRefusedException::decisionConflict();
        }
    }

    /** The exact correction the request froze, keyed by the request id. */
    private function dataFor(PaymentCorrectionRequest $request): PaymentCorrectionData
    {
        return new PaymentCorrectionData(
            paymentId: $request->payment_id,
            kind: $request->kind,
            reason: $request->reason,
            idempotencyKey: $request->id,
            chargeId: $request->charge_id,
            toChargeId: $request->to_charge_id,
            amount: $request->amount === null ? null : Money::of((string) $request->amount, $request->currency),
            accountId: $request->settlement_ledger_account_id,
            method: $request->refund_method === null ? null : ManualPaymentMethod::from($request->refund_method),
            refundedOn: $request->refunded_on?->toDateString(),
            reference: $request->refund_reference,
        );
    }

    private function assertValidTerms(PaymentCorrectionData $data): void
    {
        if (! Str::isUuid($data->idempotencyKey) || ! Str::isUuid($data->paymentId)) {
            throw PaymentCorrectionRequestRefusedException::idempotencyConflict();
        }
        $reason = trim($data->reason);
        if (mb_strlen($reason) < 5 || mb_strlen($reason) > 500) {
            throw PaymentCorrectionRefusedException::reasonRequired();
        }
        if ($data->kind !== PaymentCorrection::REVERSAL && ($data->amount === null || ! $data->amount->isPositive() || ! preg_match('/^\d{1,12}\.\d{2}$/', $data->amount->amount()))) {
            throw PaymentCorrectionRefusedException::invalidAmount();
        }
        if ($data->kind === PaymentCorrection::REFUND && $data->method === null) {
            throw PaymentCorrectionRefusedException::invalidMethod();
        }
    }

    /** Replays only the same requester's identical request; anything else fails closed. */
    private function sameRequest(PaymentCorrectionRequest $existing, PaymentCorrectionData $data, User $actor): bool
    {
        $amount = $data->kind === PaymentCorrection::REVERSAL ? null : $data->amount?->amount();

        return $existing->requested_by_user_id === $actor->id
            && $existing->payment_id === $data->paymentId
            && $existing->kind === $data->kind
            && $existing->charge_id === ($data->kind === PaymentCorrection::REVERSAL ? null : $data->chargeId)
            && $existing->to_charge_id === ($data->kind === PaymentCorrection::REALLOCATION ? $data->toChargeId : null)
            && (($existing->amount === null && $amount === null) || ($existing->amount !== null && $amount !== null && bccomp((string) $existing->amount, $amount, 2) === 0))
            && $existing->settlement_ledger_account_id === ($data->kind === PaymentCorrection::REFUND ? $data->accountId : null)
            && $existing->refund_method === ($data->kind === PaymentCorrection::REFUND ? $data->method?->value : null)
            && $existing->refunded_on?->toDateString() === ($data->kind === PaymentCorrection::REFUND ? $data->refundedOn : null)
            && $existing->refund_reference === ($data->kind === PaymentCorrection::REFUND ? $this->reference($data->reference) : null)
            && $existing->reason === trim($data->reason);
    }

    private function reference(?string $reference): ?string
    {
        $reference = $reference === null ? null : trim($reference);

        return $reference === '' ? null : $reference;
    }
}
