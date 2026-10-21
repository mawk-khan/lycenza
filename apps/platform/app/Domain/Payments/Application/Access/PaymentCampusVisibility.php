<?php

namespace App\Domain\Payments\Application\Access;

use App\Domain\Fees\Application\ChargeService;
use App\Domain\Payments\Infrastructure\Payment;
use App\Domain\Payments\Infrastructure\PaymentAllocation;
use App\Domain\Payments\Infrastructure\PaymentCorrectionLine;
use App\Models\School;
use App\Support\Authorization\Campus\CampusScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * ORG.7G-1 (ADR 0076 §50, owner decision G1-D4): THE rule that makes a
 * Payment visible to a Campus-scoped finance.payments.view holder, in SQL.
 *
 * A Payment has no Campus. It is Campus-visible only when the Charges it
 * touches -- its original allocations AND every correction line (a
 * reallocation can move money onto another Charge) -- have exactly ONE
 * DISTINCT stored Campus, none of them NULL, and that Campus is in scope.
 * So an A-only Payment is visible to A (and to A+B); an A+B Payment, or one
 * touching a whole-School (NULL-Campus) Charge, is whole-School only -- even
 * for an actor holding every Campus it touches. A Payment is atomic for
 * visibility: it is never returned with some allocations hidden.
 *
 * One correlated EXISTS per candidate Payment (index probes on the
 * allocation and correction-line `payment_id` keys), never a per-Payment
 * PHP check. The Charge -> Campus mapping comes from Fees
 * (ChargeService::campusAttributionQuery(), rule 4). Whole School: no
 * restriction. Data only -- the caller resolved the scope.
 */
class PaymentCampusVisibility
{
    public function __construct(private readonly ChargeService $charges) {}

    /**
     * @param  Builder<Payment>  $payments
     * @return Builder<Payment>
     */
    public function restrict(Builder $payments, School $school, CampusScope $scope): Builder
    {
        if ($scope->isWholeSchool()) {
            return $payments;
        }
        if ($scope->isNone()) {
            return $payments->whereRaw('1 = 0');
        }

        return $payments->whereExists($this->singleCampusInScope($school, $scope));
    }

    /**
     * Which of $paymentIds the scope may see (for a Charge page listing the
     * Payments standing on an in-scope Charge).
     *
     * @param  list<string>  $paymentIds
     * @return list<string>
     */
    public function visibleAmong(School $school, CampusScope $scope, array $paymentIds): array
    {
        if ($paymentIds === []) {
            return [];
        }

        return $this->restrict(Payment::query()->where('payments.school_id', $school->id)->whereIn('payments.id', $paymentIds), $school, $scope)
            ->pluck('payments.id')->map(fn ($id) => (string) $id)->values()->all();
    }

    private function singleCampusInScope(School $school, CampusScope $scope): QueryBuilder
    {
        $touched = PaymentAllocation::query()->toBase()
            ->select('payment_allocations.charge_id')
            ->where('payment_allocations.school_id', $school->id)
            ->whereColumn('payment_allocations.payment_id', 'payments.id')
            ->unionAll(PaymentCorrectionLine::query()->toBase()
                ->select('payment_correction_lines.charge_id')
                ->where('payment_correction_lines.school_id', $school->id)
                ->whereColumn('payment_correction_lines.payment_id', 'payments.id'));

        return DB::query()
            ->fromSub($touched, 'touched')
            ->joinSub($this->charges->campusAttributionQuery($school), 'charge_campus', 'charge_campus.id', '=', 'touched.charge_id')
            ->selectRaw('1')
            ->havingRaw('count(DISTINCT charge_campus.campus_id) = 1')
            ->havingRaw('bool_and(coalesce(charge_campus.campus_id = ANY(?::uuid[]), false))', ['{'.implode(',', $scope->campusIds()).'}']);
    }
}
