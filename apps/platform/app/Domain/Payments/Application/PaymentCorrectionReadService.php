<?php

namespace App\Domain\Payments\Application;

use App\Domain\Fees\Application\ChargeService;
use App\Domain\Fees\Application\ChargeStatementLine;
use App\Domain\Finance\Application\LedgerAccountSummary;
use App\Domain\Finance\Application\LedgerService;
use App\Domain\Payments\Application\Access\PaymentCampusVisibility;
use App\Domain\Payments\Application\Charges\ChargeStateReader;
use App\Domain\Payments\Application\Exceptions\PaymentNotFoundException;
use App\Domain\Payments\Domain\ManualPaymentMethod;
use App\Domain\Payments\Infrastructure\Payment;
use App\Domain\Payments\Infrastructure\PaymentCorrection;
use App\Domain\Payments\Infrastructure\PaymentCorrectionLine;
use App\Domain\Payments\Infrastructure\PaymentCorrectionRequest;
use App\Models\School;
use App\Models\User;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Authorization\Campus\AuthorizesCampusScope;
use App\Support\Authorization\CapabilityResolver;
use App\Support\Money\Money;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;

/**
 * UAT-C3 (ADR 0075 §16): what the payment page shows about corrections --
 * where the Payment stands now on each charge, its correction history, and
 * (only for an actor who may correct) the choices the correction forms
 * offer. Read-only, under `finance.payments.view`; the choices are display
 * help only -- `PaymentCorrectionService` re-checks everything under its
 * locks. ORG.8C (ADR 0076 §61): the forms REQUEST a correction
 * (`finance.payment_corrections.request`), and the Payment's correction
 * requests are listed only for `finance.payment_corrections.view`.
 *
 * ORG.7G-1 (ADR 0076 §50): the reader's finance.payments.view CampusScope is
 * resolved here, and a Payment it may not see (PaymentCampusVisibility) is
 * "not found" -- so every Charge shown is in scope. The correction choices
 * stay whole-School capability checks (a Campus reader gets none), and a
 * Campus reader sees no journal entry ids.
 */
class PaymentCorrectionReadService
{
    use AuthorizesCampusScope;
    use AuthorizesCapability;

    public function __construct(
        private readonly PaymentApplicationReader $applications,
        private readonly ChargeService $charges,
        private readonly ChargeStateReader $states,
        private readonly LedgerService $ledger,
        private readonly CapabilityResolver $capabilities,
        private readonly TenantContext $context,
        private readonly PaymentCampusVisibility $visibility,
    ) {}

    /** @return array<string, mixed> */
    public function forPayment(School $school, string $paymentId, string $paidOn, User $actor): array
    {
        $scope = $this->authorizeCampusScopeFor($actor, 'finance.payments.view', $school);
        if (! Str::isUuid($paymentId) || (! $scope->isWholeSchool() && $this->visibility->visibleAmong($school, $scope, [$paymentId]) === [])) {
            throw new PaymentNotFoundException($paymentId);
        }

        return $this->context->withSchool($school, function () use ($school, $scope, $paymentId, $paidOn, $actor) {
            $corrections = PaymentCorrection::query()->where('school_id', $school->id)->where('payment_id', $paymentId)
                ->with('lines')->orderBy('created_at')->orderBy('id')->get();
            $reversal = $corrections->firstWhere('kind', PaymentCorrection::REVERSAL);
            $current = $this->applications->forPayment($school, $paymentId);

            // ORG.8C: every kind is requested under one whole-School capability.
            $canRequest = $reversal === null && $this->capabilities->canInSchool($actor, PaymentCorrectionRequestService::REQUEST, $school);
            $canReallocate = $canRequest;
            $canReverseOrRefund = $canRequest;

            $chargeIds = array_keys($current);
            foreach ($corrections as $correction) {
                foreach ($correction->lines as $line) {
                    $chargeIds[] = $line->charge_id;
                }
            }
            $lines = collect($this->charges->statementLinesForCharges($school, array_values(array_unique($chargeIds))))->keyBy('chargeId');
            $studentIds = $lines->filter(fn (ChargeStatementLine $l) => isset($current[$l->chargeId]))->pluck('studentId')->unique()->values()->all();

            $destinations = [];
            if ($canReallocate) {
                foreach ($studentIds as $studentId) {
                    $open = $this->charges->uncancelledChargesForStudent($school, $studentId);
                    $states = $this->states->forCharges($school, $open->pluck('chargeId')->all());
                    foreach ($open as $charge) {
                        $outstanding = ($states[$charge->chargeId] ?? null)?->outstanding() ?? Money::of($charge->amount, $charge->currency);
                        if ($outstanding->isPositive()) {
                            $destinations[] = [
                                'chargeId' => $charge->chargeId,
                                'studentId' => $studentId,
                                'description' => $charge->description,
                                'outstanding' => $outstanding->amount(),
                                'dueDate' => $charge->dueDate?->toDateString(),
                            ];
                        }
                    }
                }
            }

            return [
                'reversed' => $reversal === null ? null : [
                    'on' => CarbonImmutable::parse($reversal->created_at)->setTimezone($school->timezone)->toDateString(),
                    'reason' => $reversal->reason,
                ],
                'standing' => collect($current)->map(fn (string $amount, string $chargeId) => [
                    'chargeId' => $chargeId,
                    'studentId' => $lines->get($chargeId)?->studentId,
                    'description' => $lines->get($chargeId)?->description,
                    'amount' => $amount,
                ])->values()->all(),
                'history' => $corrections->map(fn (PaymentCorrection $c) => [
                    'id' => $c->id,
                    'kind' => $c->kind,
                    'amount' => $c->amount,
                    'reason' => $c->reason,
                    'recordedAt' => CarbonImmutable::parse($c->created_at)->toIso8601String(),
                    'recordedOn' => CarbonImmutable::parse($c->created_at)->setTimezone($school->timezone)->toDateString(),
                    'byName' => User::query()->find($c->corrected_by_user_id)?->name,
                    'journalEntryId' => $scope->isWholeSchool() ? $c->journal_entry_id : null,
                    'refundMethodLabel' => $c->refund_method === null ? null : ManualPaymentMethod::tryFrom($c->refund_method)?->label(),
                    'refundReference' => $c->refund_reference,
                    'refundedOn' => $c->refunded_on?->toDateString(),
                    'lines' => $c->lines->sortBy('direction')->map(fn (PaymentCorrectionLine $l) => [
                        'chargeId' => $l->charge_id,
                        'description' => $lines->get($l->charge_id)?->description,
                        'direction' => $l->direction,
                        'amount' => $l->amount,
                    ])->values()->all(),
                ])->values()->all(),
                'canReallocate' => $canReallocate,
                'canRecordReplacement' => $reversal !== null && $this->capabilities->canInSchool($actor, ManualPaymentRecordingService::CAPABILITY, $school),
                'studentIds' => $lines->pluck('studentId')->unique()->values()->all(),
                'canReverseOrRefund' => $canReverseOrRefund,
                'destinations' => $destinations,
                'refundAccounts' => $canReverseOrRefund
                    ? $this->ledger->activeAccountsOfType($school, 'asset')
                        ->map(fn (LedgerAccountSummary $a) => ['id' => $a->ledgerAccountId, 'code' => $a->code, 'name' => $a->name])->values()->all()
                    : [],
                'methods' => $canReverseOrRefund ? ManualPaymentRecordingService::methodOptions() : [],
                'refundEarliest' => $paidOn,
                'today' => CarbonImmutable::now($school->timezone)->toDateString(),
                'requests' => $this->capabilities->canInSchool($actor, PaymentCorrectionRequestService::VIEW, $school)
                    ? $this->presentRequests($school, $actor, PaymentCorrectionRequest::query()->where('school_id', $school->id)->where('payment_id', $paymentId)
                        ->orderByDesc('created_at')->orderByDesc('id')->get(), $lines->map(fn (ChargeStatementLine $l) => $l->description)->all())
                    : null,
            ];
        });
    }

    /**
     * ORG.8C (ADR 0076 §61): the whole-School correction-request queue, newest
     * first (`finance.payment_corrections.view`). Minimal: the Payment's
     * amount, the requested terms, who asked and who decided -- no
     * Student, Guardian or charge detail (those stay on the Payment page,
     * under its own visibility).
     *
     * @return LengthAwarePaginator<int, non-empty-array<string, mixed>>
     */
    public function requestQueue(School $school, User $actor, ?string $status, int $page): LengthAwarePaginator
    {
        $this->authorizeCapabilityFor($actor, PaymentCorrectionRequestService::VIEW, $school);

        return $this->context->withSchool($school, function () use ($school, $actor, $status, $page): LengthAwarePaginator {
            $paginator = PaymentCorrectionRequest::query()->where('school_id', $school->id)
                ->when($status !== null, fn ($q) => $q->where('status', $status))
                ->orderByDesc('created_at')->orderByDesc('id')
                ->paginate(25, ['*'], 'page', max(1, $page))
                ->withQueryString();
            $requests = collect($paginator->items());
            $payments = Payment::query()->where('school_id', $school->id)->whereIn('id', $requests->pluck('payment_id')->unique()->all())->get()->keyBy('id');

            $rows = collect($this->presentRequests($school, $actor, $requests, []))->mapWithKeys(fn (array $r): array => [$r['id'] => [
                ...$r,
                'paymentAmount' => $payments->get($r['paymentId'])?->amount,
                'paymentCurrency' => $payments->get($r['paymentId'])?->currency,
            ]]);

            return $paginator->through(fn (PaymentCorrectionRequest $r): array => $rows[$r->id]);
        });
    }

    /**
     * The display choices are help only: the service re-checks authority,
     * maker/checker and the beneficiary conflict under its locks.
     *
     * @param  iterable<PaymentCorrectionRequest>  $requests
     * @param  array<string, string|null>  $chargeDescriptions
     * @return list<array<string, mixed>>
     */
    private function presentRequests(School $school, User $actor, iterable $requests, array $chargeDescriptions): array
    {
        $requests = collect($requests);
        $people = User::query()->whereIn('id', $requests->pluck('requested_by_user_id')->merge($requests->pluck('decided_by_user_id'))->filter()->unique()->all())->pluck('name', 'id');
        $canApprove = $this->capabilities->canInSchool($actor, PaymentCorrectionRequestService::APPROVE, $school);
        $canRequest = $this->capabilities->canInSchool($actor, PaymentCorrectionRequestService::REQUEST, $school);

        return $requests->map(function (PaymentCorrectionRequest $r) use ($school, $actor, $people, $canApprove, $canRequest, $chargeDescriptions): array {
            $pending = $r->status === PaymentCorrectionRequest::PENDING;
            $mine = $r->requested_by_user_id === $actor->id;

            return [
                'id' => $r->id,
                'paymentId' => $r->payment_id,
                'kind' => $r->kind,
                'status' => $r->status,
                'amount' => $r->amount,
                'reason' => $r->reason,
                'chargeDescription' => $r->charge_id === null ? null : ($chargeDescriptions[$r->charge_id] ?? null),
                'toChargeDescription' => $r->to_charge_id === null ? null : ($chargeDescriptions[$r->to_charge_id] ?? null),
                'refundMethodLabel' => $r->refund_method === null ? null : ManualPaymentMethod::tryFrom($r->refund_method)?->label(),
                'refundReference' => $r->refund_reference,
                'refundedOn' => $r->refunded_on?->toDateString(),
                'requestedByName' => $people[$r->requested_by_user_id] ?? null,
                'requestedOn' => CarbonImmutable::parse($r->created_at)->setTimezone($school->timezone)->toDateString(),
                'decidedByName' => $r->decided_by_user_id === null ? null : ($people[$r->decided_by_user_id] ?? null),
                'decidedOn' => $r->decided_at === null ? ($r->withdrawn_at === null ? null : CarbonImmutable::parse($r->withdrawn_at)->setTimezone($school->timezone)->toDateString())
                    : CarbonImmutable::parse($r->decided_at)->setTimezone($school->timezone)->toDateString(),
                'isMine' => $mine,
                'canDecide' => $pending && $canApprove && ! $mine,
                'canWithdraw' => $pending && $mine && $canRequest,
            ];
        })->values()->all();
    }
}
