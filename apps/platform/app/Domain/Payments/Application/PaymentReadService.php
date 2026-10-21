<?php

namespace App\Domain\Payments\Application;

use App\Domain\Payments\Application\Access\PaymentCampusVisibility;
use App\Domain\Payments\Application\Exceptions\PaymentNotFoundException;
use App\Domain\Payments\Infrastructure\Payment;
use App\Models\School;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Authorization\Campus\AuthorizesCampusScope;
use App\Support\Tenancy\TenantContext;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;

/**
 * Phase 0G.5 -- the sole authorized read path for `payments`, mirroring
 * `App\Domain\Fees\Application\ChargeReadService`'s exact disclosure-
 * boundary/authorization/audit discipline. Every result is a typed DTO
 * (`PaymentSummary`/`PaymentDetail`), never the raw `Payment` Eloquent
 * model.
 *
 * ORG.7G-1 (ADR 0076 §50): the check RESOLVES the actor's CampusScope and
 * every read applies PaymentCampusVisibility in SQL (G1-D4: a Payment is
 * Campus-visible only when every Charge it touches has the same stored
 * Campus, in scope; otherwise it is the same "not found" as an unknown one).
 *
 * Single capability, `finance.payments.view`, checked BEFORE any query
 * runs. `finance.payments.manage` is deliberately NOT introduced in
 * 0G.5 (rule 53) -- there is no human-triggered "record a payment"
 * action to gate; the only write path
 * (`PaymentProviderEventService::recordSettlement()`) is a trusted
 * SYSTEM boundary, not a human capability (rule 54). Adding an unused
 * `.manage` capability now would be exactly the "speculative capability
 * registration" rule 53 warns against.
 */
class PaymentReadService
{
    use AuthorizesCampusScope;

    public const int MAX_PER_PAGE = 100;

    public function __construct(
        private readonly TenantContext $context,
        private readonly AuditRecorder $audit,
        private readonly PaymentCampusVisibility $visibility,
    ) {}

    /**
     * @return LengthAwarePaginator<int, PaymentSummary>
     */
    public function listPayments(School $school, PaymentQuery $query, User $actor): LengthAwarePaginator
    {
        $scope = $this->authorizeCampusScopeFor($actor, 'finance.payments.view', $school);

        return $this->context->withSchool($school, function () use ($school, $scope, $query, $actor) {
            $builder = $this->visibility->restrict(Payment::query()->where('payments.school_id', $school->id), $school, $scope)
                ->when(
                    $query->providerPaymentReference !== null,
                    fn ($q) => $q->where('provider_payment_reference', $query->providerPaymentReference)
                )
                ->orderByDesc('created_at')
                ->orderByDesc('id');

            $perPage = min($query->perPage, self::MAX_PER_PAGE);
            $paginator = $builder->paginate($perPage, ['*'], 'page', $query->page);

            $payments = $paginator->getCollection()->map(fn (Payment $payment) => PaymentSummary::fromModel($payment));

            $this->audit->school($school, 'payment.list_viewed', actor: $actor, metadata: [
                'resultCount' => $payments->count(),
            ]);

            return new LengthAwarePaginator(
                $payments,
                $paginator->total(),
                $paginator->perPage(),
                $paginator->currentPage(),
                ['path' => $paginator->path()],
            );
        });
    }

    public function getPaymentDetail(School $school, string $paymentId, User $actor): PaymentDetail
    {
        $scope = $this->authorizeCampusScopeFor($actor, 'finance.payments.view', $school);

        return $this->context->withSchool($school, function () use ($school, $scope, $paymentId, $actor) {
            $payment = Str::isUuid($paymentId)
                ? $this->visibility->restrict(Payment::query()->where('payments.school_id', $school->id), $school, $scope)->with(['allocations', 'receipt'])->find($paymentId)
                : null;

            if ($payment === null) {
                throw new PaymentNotFoundException($paymentId);
            }

            $this->audit->school($school, 'payment.detail_viewed', actor: $actor, subject: $payment, metadata: [
                'paymentId' => $payment->id,
            ]);

            return PaymentDetail::fromModel($payment);
        });
    }
}
