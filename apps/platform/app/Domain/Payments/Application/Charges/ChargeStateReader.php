<?php

namespace App\Domain\Payments\Application\Charges;

use App\Domain\Fees\Application\ChargeService;
use App\Domain\Finance\Application\Periods\FinancialPeriodService;
use App\Domain\Payments\Infrastructure\FinancialPeriodChargeState;
use App\Domain\Payments\Infrastructure\PaymentAllocation;
use App\Domain\Payments\Infrastructure\PaymentCorrectionLine;
use App\Models\School;
use App\Support\Money\Money;
use App\Support\Tenancy\TenantContext;

/**
 * E21.3A2 (ADR 0064 §15): THE authoritative charge-state read. A charge's
 * state = its carried state in the School's latest closed period + every
 * later fact (by journal entry):
 * - allocations whose Payment settled after that period;
 * - minus adjustments posted through it but voided after it;
 * - plus later adjustments still live;
 * - a cancellation after it.
 * A charge assessed after the latest close has no carried state and is
 * read from its own facts. Every outstanding amount, Student due, statement,
 * allocation capacity check and late-fee evaluation goes through here.
 *
 * Retention removes a charge only together with every fact of it (a whole
 * settled unit), so a retained charge always has its later facts. The
 * all-history computation is `AllHistoryChargeReader`, used by the balance
 * verifier only.
 *
 * Trusted, read-only, no capability check (the caller authorizes). Needs no
 * lock itself: callers that act on the result read it under the charge's
 * row lock.
 */
class ChargeStateReader
{
    private const SCALE = 2;

    public function __construct(
        private readonly ChargeService $charges,
        private readonly FinancialPeriodService $periods,
        private readonly TenantContext $context,
    ) {}

    /**
     * @param  list<string>  $chargeIds
     * @return array<string, ChargeState> keyed by charge id; unknown ids are absent
     */
    public function forCharges(School $school, array $chargeIds): array
    {
        $states = [];
        foreach (array_chunk(array_values(array_unique($chargeIds)), 1000) as $chunk) {
            $states += $this->context->withSchool($school, fn () => $this->chunk($school, $chunk));
        }

        return $states;
    }

    /**
     * UAT-C3: every payment-correction line on these charges as a signed
     * amount (apply +, release -) with its correction's journal entry -- the
     * same [amount, entry] shape as an allocation, so every reader that sums
     * allocations by entry (this one, the period close, the all-history
     * check) treats a correction as a later fact of its own entry.
     *
     * @param  list<string>|null  $chargeIds  null: every charge of the School
     * @return array<string, list<array{0: string, 1: string}>>
     */
    public static function correctionFacts(School $school, ?array $chargeIds): array
    {
        $facts = [];
        foreach (PaymentCorrectionLine::query()->where('payment_correction_lines.school_id', $school->id)
            ->when($chargeIds !== null, fn ($q) => $q->whereIn('payment_correction_lines.charge_id', $chargeIds))
            ->join('payment_corrections', fn ($join) => $join->on('payment_corrections.id', '=', 'payment_correction_lines.payment_correction_id')->on('payment_corrections.school_id', '=', 'payment_correction_lines.school_id'))
            ->toBase()->select(['payment_correction_lines.charge_id', 'payment_correction_lines.direction', 'payment_correction_lines.amount', 'payment_corrections.journal_entry_id'])->cursor() as $row) {
            $amount = (string) $row->amount;
            $facts[(string) $row->charge_id][] = [$row->direction === PaymentCorrectionLine::RELEASE ? '-'.$amount : $amount, (string) $row->journal_entry_id];
        }

        return $facts;
    }

    /**
     * @param  list<string>  $ids
     * @return array<string, ChargeState>
     */
    private function chunk(School $school, array $ids): array
    {
        $facts = $this->charges->ledgerFacts($school, $ids);
        if ($facts === []) {
            return [];
        }

        $allocations = [];
        foreach (PaymentAllocation::query()->where('payment_allocations.school_id', $school->id)
            ->whereIn('payment_allocations.charge_id', $ids)
            ->join('payments', fn ($join) => $join->on('payments.id', '=', 'payment_allocations.payment_id')->on('payments.school_id', '=', 'payment_allocations.school_id'))
            ->toBase()->select(['payment_allocations.charge_id', 'payment_allocations.amount', 'payments.journal_entry_id'])->get() as $row) {
            $allocations[(string) $row->charge_id][] = [(string) $row->amount, (string) $row->journal_entry_id];
        }
        // UAT-C3: correction lines move a Payment's effect between charges
        // (apply +, release -), dated by the correction's own journal entry.
        foreach (self::correctionFacts($school, $ids) as $chargeId => $rows) {
            foreach ($rows as $row) {
                $allocations[$chargeId][] = $row;
            }
        }
        $adjustments = [];
        foreach ($this->charges->adjustmentLedgerFacts($school, $ids) as $adjustment) {
            $adjustments[$adjustment->chargeId][] = $adjustment;
        }

        $latest = $this->periods->latestClosed($school);
        $baseline = [];
        $through = [];
        if ($latest !== null) {
            foreach (FinancialPeriodChargeState::query()->where('school_id', $school->id)->where('financial_period_id', $latest->id)->whereIn('charge_id', $ids)->get() as $state) {
                $baseline[$state->charge_id] = $state;
            }
            $entries = [];
            foreach ($facts as $fact) {
                $entries[] = $fact->cancellationJournalEntryId;
            }
            foreach ($allocations as $rows) {
                foreach ($rows as [, $entryId]) {
                    $entries[] = $entryId;
                }
            }
            foreach ($adjustments as $rows) {
                foreach ($rows as $adjustment) {
                    $entries[] = $adjustment->journalEntryId;
                    $entries[] = $adjustment->cancellationJournalEntryId;
                }
            }
            $through = $this->periods->entriesThrough($school, array_values(array_filter($entries)), $latest);
        }
        $closed = fn (?string $entryId): bool => $entryId !== null && isset($through[$entryId]);

        $states = [];
        foreach ($facts as $fact) {
            $state = $baseline[$fact->id] ?? null;
            if ($state === null) {
                // Assessed after the latest close: its own facts are all later detail.
                $cancelled = $fact->cancelled;
                $allocated = '0.00';
                foreach ($allocations[$fact->id] ?? [] as [$amount]) {
                    $allocated = bcadd($allocated, $amount, self::SCALE);
                }
                $adjusted = '0.00';
                foreach ($adjustments[$fact->id] ?? [] as $adjustment) {
                    if (! $adjustment->cancelled) {
                        $adjusted = bcadd($adjusted, $adjustment->amount, self::SCALE);
                    }
                }
            } else {
                $cancelled = $state->cancelled || ($fact->cancelled && ! $closed($fact->cancellationJournalEntryId));
                $allocated = (string) $state->allocated_total;
                foreach ($allocations[$fact->id] ?? [] as [$amount, $entryId]) {
                    if (! $closed($entryId)) {
                        $allocated = bcadd($allocated, $amount, self::SCALE);
                    }
                }
                $adjusted = (string) $state->live_adjusted_total;
                foreach ($adjustments[$fact->id] ?? [] as $adjustment) {
                    if ($closed($adjustment->journalEntryId)) {
                        if ($adjustment->cancelled && ! $closed($adjustment->cancellationJournalEntryId)) {
                            $adjusted = bcsub($adjusted, $adjustment->amount, self::SCALE);
                        }
                    } elseif (! $adjustment->cancelled) {
                        $adjusted = bcadd($adjusted, $adjustment->amount, self::SCALE);
                    }
                }
            }

            $states[$fact->id] = new ChargeState(
                chargeId: $fact->id,
                studentId: $fact->studentId,
                amount: Money::of($fact->amount, $fact->currency),
                cancelled: $cancelled,
                allocated: Money::of($allocated, $fact->currency),
                adjusted: Money::of($adjusted, $fact->currency),
            );
        }

        return $states;
    }
}
