<?php

namespace App\Domain\Payments\Application;

use App\Domain\Payments\Infrastructure\PaymentAllocation;
use App\Domain\Payments\Infrastructure\PaymentCorrectionLine;
use App\Models\School;

/**
 * UAT-C3: how much of each Payment currently stands on each charge -- its
 * original allocations plus payment-correction lines (apply +, release -).
 * Trusted, read-only, no capability check (callers authorize); run inside
 * the School's TenantContext.
 */
class PaymentApplicationReader
{
    /**
     * What one Payment currently has on each charge (only non-zero), for
     * corrections and the payment page.
     *
     * @return array<string, string> charge id => net amount
     */
    public function forPayment(School $school, string $paymentId): array
    {
        $net = [];
        foreach (PaymentAllocation::query()->where('school_id', $school->id)->where('payment_id', $paymentId)->toBase()->get(['charge_id', 'amount']) as $row) {
            $net[(string) $row->charge_id] = bcadd($net[(string) $row->charge_id] ?? '0.00', (string) $row->amount, 2);
        }
        foreach (PaymentCorrectionLine::query()->where('school_id', $school->id)->where('payment_id', $paymentId)->toBase()->get(['charge_id', 'direction', 'amount']) as $row) {
            $signed = $row->direction === PaymentCorrectionLine::RELEASE ? '-'.$row->amount : (string) $row->amount;
            $net[(string) $row->charge_id] = bcadd($net[(string) $row->charge_id] ?? '0.00', $signed, 2);
        }
        ksort($net);

        return array_filter($net, fn (string $amount) => bccomp($amount, '0.00', 2) !== 0);
    }

    /**
     * @param  list<string>  $chargeIds
     * @return array<string, array<string, string>> charge id => payment id => net amount (only non-zero)
     */
    public function netByCharge(School $school, array $chargeIds, ?string $paymentId = null): array
    {
        if ($chargeIds === []) {
            return [];
        }

        $net = [];
        foreach (PaymentAllocation::query()->where('school_id', $school->id)->whereIn('charge_id', $chargeIds)
            ->when($paymentId !== null, fn ($q) => $q->where('payment_id', $paymentId))
            ->toBase()->get(['charge_id', 'payment_id', 'amount']) as $row) {
            $net[(string) $row->charge_id][(string) $row->payment_id] = bcadd($net[(string) $row->charge_id][(string) $row->payment_id] ?? '0.00', (string) $row->amount, 2);
        }
        foreach (PaymentCorrectionLine::query()->where('school_id', $school->id)->whereIn('charge_id', $chargeIds)
            ->when($paymentId !== null, fn ($q) => $q->where('payment_id', $paymentId))
            ->toBase()->get(['charge_id', 'payment_id', 'direction', 'amount']) as $row) {
            $signed = $row->direction === PaymentCorrectionLine::RELEASE ? '-'.$row->amount : (string) $row->amount;
            $net[(string) $row->charge_id][(string) $row->payment_id] = bcadd($net[(string) $row->charge_id][(string) $row->payment_id] ?? '0.00', $signed, 2);
        }

        foreach ($net as $chargeId => $payments) {
            $net[$chargeId] = array_filter($payments, fn (string $amount) => bccomp($amount, '0.00', 2) !== 0);
        }

        return array_filter($net);
    }
}
