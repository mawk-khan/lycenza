<?php

namespace App\Domain\Payments\Application\Access;

use App\Domain\Fees\Application\ChargeCampusFilter;
use App\Domain\Fees\Application\ChargeDetail;
use App\Domain\Fees\Application\ChargeQuery;
use App\Domain\Fees\Application\ChargeReadService;
use App\Domain\Fees\Application\ChargeService;
use App\Domain\Fees\Application\ChargeSummary;
use App\Domain\Payments\Application\Collections\DailyCollections;
use App\Domain\Payments\Application\Collections\DailyCollectionsQuery;
use App\Domain\Payments\Application\Collections\DailyCollectionsReadService;
use App\Domain\Payments\Application\Collections\OutstandingFeesList;
use App\Domain\Payments\Application\Collections\OutstandingFeesReadService;
use App\Domain\Payments\Application\PaymentApplicationReader;
use App\Domain\Payments\Application\PaymentDetail;
use App\Domain\Payments\Application\PaymentQuery;
use App\Domain\Payments\Application\PaymentReadService;
use App\Domain\Payments\Application\PaymentReceiptReadService;
use App\Domain\Payments\Application\PaymentSummary;
use App\Domain\Payments\Application\ReceiptDocument;
use App\Domain\Payments\Infrastructure\Payment;
use App\Domain\Students\Application\StudentIdentityLookup;
use App\Domain\Students\Application\StudentLookupCandidate;
use App\Models\School;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Authorization\Campus\AuthorizesCampusScope;
use App\Support\Authorization\Campus\CampusScope;
use App\Support\Authorization\Campus\CampusScopeResolver;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * ORG.7G-1 (ADR 0076 §50): THE Finance read-access layer for the
 * Campus-adopted Finance read keys (finance.charges.view,
 * finance.payments.view). A controller resolves the actor's CampusScope
 * (authorizeCampusScope(), which refuses "none" with 403) for entry and
 * display, and reads ONLY through these methods. Each one RE-RESOLVES the
 * actor's scope itself (the underlying read services stay authorization
 * boundaries) and applies it in SQL before any row returns:
 *
 * - Charges: by their STORED Campus (charges.campus_id, ORG.7G-1A) -- never
 *   the Student's current Campus; a NULL-Campus Charge is whole-School only.
 * - Payments, receipts, collections: PaymentCampusVisibility (G1-D4) -- one
 *   distinct non-NULL Campus across every Charge the Payment touches, in
 *   scope; a mixed or NULL-touching Payment is whole-School only, atomically.
 * - Charge balances stay correct (G1-D7): an in-scope Charge shows the amount
 *   a hidden Payment applied to it, without that Payment's id or receipt.
 * - Debtors (G1-D8): Students with an OPEN Charge in scope, leavers included,
 *   projected as id / studentNumber / displayName only.
 *
 * An out-of-scope record is the same "not found" as an unknown one. Request
 * filters only narrow. Whole-School scope: existing behaviour, unchanged.
 */
class FinanceCampusAccess
{
    use AuthorizesCampusScope;

    public function __construct(
        private readonly ChargeReadService $chargeReads,
        private readonly ChargeService $charges,
        private readonly OutstandingFeesReadService $outstanding,
        private readonly PaymentReadService $payments,
        private readonly PaymentReceiptReadService $receipts,
        private readonly DailyCollectionsReadService $collections,
        private readonly PaymentCampusVisibility $visibility,
        private readonly PaymentApplicationReader $applications,
        private readonly StudentIdentityLookup $students,
        private readonly CampusScopeResolver $resolver,
        private readonly AuditRecorder $audit,
    ) {}

    /** Charges within scope, by stored Campus, in SQL (NULL Campus: whole-School only). */
    public function restrictCharges(Builder|QueryBuilder $charges, CampusScope $scope, string $column = 'campus_id'): Builder|QueryBuilder
    {
        return $scope->restrict($charges, $column);
    }

    /**
     * @param  Builder<Payment>  $payments
     * @return Builder<Payment>
     */
    public function restrictPayments(Builder $payments, School $school, CampusScope $scope): Builder
    {
        return $this->visibility->restrict($payments, $school, $scope);
    }

    /** @return LengthAwarePaginator<int, ChargeSummary> */
    public function charges(School $school, ChargeQuery $query, User $actor): LengthAwarePaginator
    {
        return $this->chargeReads->listCharges($school, $query, $actor);
    }

    public function charge(School $school, string $chargeId, User $actor): ChargeDetail
    {
        return $this->chargeReads->getChargeDetail($school, $chargeId, $actor);
    }

    /**
     * The Payments standing on a Charge the caller has ALREADY read (charge()):
     * the ones the actor's finance.payments.view scope may see, by id, and the
     * summed amount of the rest (hidden Payments, G1-D7) -- never their ids.
     * Null without finance.payments.view (no Payment is listed).
     *
     * @return array{payments: list<array{paymentId: string, amount: string}>, otherAmount: string|null}|null
     */
    public function chargePayments(School $school, User $actor, string $chargeId): ?array
    {
        $paymentScope = $this->resolver->campusScope($actor, 'finance.payments.view', $school);
        if ($paymentScope->isNone() || ! Str::isUuid($chargeId)) {
            return null;
        }
        // The Charge itself must be in the reader's charge scope (never trust the caller's read).
        $chargeScope = $this->resolver->campusScope($actor, 'finance.charges.view', $school);
        $charge = $this->charges->campusAttributionQuery($school)->where('charges.id', $chargeId)->first();
        if ($charge === null || ! $chargeScope->covers($charge->campus_id === null ? null : (string) $charge->campus_id)) {
            return null;
        }
        $net = $this->applications->netByCharge($school, [$chargeId])[$chargeId] ?? [];
        $visible = array_flip($this->visibility->visibleAmong($school, $paymentScope, array_map('strval', array_keys($net))));

        $shown = [];
        $other = null;
        foreach ($net as $paymentId => $amount) {
            if (isset($visible[(string) $paymentId])) {
                $shown[] = ['paymentId' => (string) $paymentId, 'amount' => $amount];
            } else {
                $other = bcadd($other ?? '0.00', $amount, 2);
            }
        }

        return ['payments' => $shown, 'otherAmount' => $other];
    }

    /**
     * ORG.9D-2 (ADR 0076 §74): the Outstanding fees reader's scope -- the
     * intersection of finance.charges.view and finance.payments.view -- for a
     * caller that must refuse BEFORE reading (a fee reminder's initiator).
     * Refuses (403) without either key, or when the two scopes share nothing.
     *
     * @throws AuthorizationException
     */
    public function outstandingScope(School $school, User $actor): CampusScope
    {
        $scope = $this->authorizeCampusScopeFor($actor, 'finance.charges.view', $school)
            ->intersect($this->authorizeCampusScopeFor($actor, 'finance.payments.view', $school));
        if ($scope->isNone()) {
            throw new AuthorizationException('No Finance scope covers outstanding balances.');
        }

        return $scope;
    }

    /** @param  list<string>|null  $studentIds */
    public function outstandingFees(School $school, User $actor, string $status, ?string $academicYearId, ?array $studentIds, ?ChargeCampusFilter $campus, ?string $asAt = null): OutstandingFeesList
    {
        return $this->outstanding->list($school, $actor, $status, $academicYearId, $studentIds, $campus, $asAt);
    }

    /**
     * G1-D8: Students with at least one OPEN (uncancelled, still owing) Charge
     * whose stored Campus is in scope -- active, transferred, inactive or
     * leaver alike -- matching `$term`, bounded; one SQL statement.
     *
     * @return list<StudentLookupCandidate>
     */
    public function debtors(School $school, User $actor, ?string $term, int $limit = StudentIdentityLookup::DEFAULT_LIMIT): array
    {
        $scope = $this->authorizeCampusScopeFor($actor, 'finance.charges.view', $school);
        $found = $this->students->searchAmong($school, $this->openDebtors($school, $scope), $term, $limit);
        $this->audit->school($school, 'finance.debtor_lookup.viewed', actor: $actor, metadata: [
            'resultCount' => count($found),
            'scope' => $scope->isWholeSchool() ? 'school' : 'campus',
        ]);

        return $found;
    }

    /**
     * ORG.7G-2 (ADR 0076 §51): who a payment recorder may record for. A
     * whole-School recorder searches every Student (unchanged); a Campus
     * recorder only Students with an OPEN Charge whose stored Campus its
     * finance.payments.record scope covers -- leavers and moved Students
     * included, never by the current Campus.
     *
     * @return list<StudentLookupCandidate>
     */
    public function collectionDebtors(School $school, User $actor, string $term, int $limit = 10): array
    {
        $scope = $this->authorizeCampusScopeFor($actor, 'finance.payments.record', $school);

        return $scope->isWholeSchool()
            ? $this->students->search($school, $term, $limit)
            : $this->students->searchAmong($school, $this->openDebtors($school, $scope), $term, $limit);
    }

    /** A display decision only: whether the actor records payments anywhere (whole School or a Campus). */
    public function recordsPayments(User $actor, School $school): bool
    {
        return ! $this->resolver->campusScope($actor, 'finance.payments.record', $school)->isNone();
    }

    /** A display decision only: whether the actor could record a payment against a Charge of `$campusId`. */
    public function canCollect(User $actor, School $school, ?string $campusId): bool
    {
        return $this->resolver->campusScope($actor, 'finance.payments.record', $school)->covers($campusId);
    }

    /** Students with an uncancelled, still-owing Charge whose stored Campus `$scope` covers (one SQL subquery). */
    private function openDebtors(School $school, CampusScope $scope): QueryBuilder
    {
        return DB::query()
            ->fromSub($this->charges->liveChargesQuery($school, $scope), 'live')
            ->whereRaw('live.assessed_net - payments_effective_allocated(live.id) > 0')
            ->select('live.student_id');
    }

    /** @return LengthAwarePaginator<int, PaymentSummary> */
    public function payments(School $school, PaymentQuery $query, User $actor): LengthAwarePaginator
    {
        return $this->payments->listPayments($school, $query, $actor);
    }

    public function payment(School $school, string $paymentId, User $actor): PaymentDetail
    {
        return $this->payments->getPaymentDetail($school, $paymentId, $actor);
    }

    public function receipt(School $school, string $paymentId, User $actor): ReceiptDocument
    {
        return $this->receipts->forPayment($school, $paymentId, $actor);
    }

    public function collections(School $school, User $actor, DailyCollectionsQuery $query): DailyCollections
    {
        return $this->collections->list($school, $actor, $query);
    }

    /** A display decision only (e.g. whole-School-only links), never a substitute for a scoped read. */
    public function holdsWholeSchool(User $actor, string $capability, School $school): bool
    {
        return $this->resolver->campusScope($actor, $capability, $school)->isWholeSchool();
    }
}
