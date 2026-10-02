<?php

namespace App\Domain\Payments\Application\Retention;

use App\Domain\Fees\Application\ChargeService;
use App\Domain\Finance\Application\Periods\FinancialPeriodSummary;
use App\Domain\Finance\Application\Periods\JournalPeriodIndex;
use App\Domain\Finance\Application\Retention\FinanceRetentionParticipant;
use App\Domain\Finance\Application\Retention\FinanceRetentionUnit;
use App\Domain\Payments\Application\Charges\ChargeStateReader;
use App\Domain\Payments\Infrastructure\LateFeeAssessment;
use App\Domain\Payments\Infrastructure\Payment;
use App\Domain\Payments\Infrastructure\PaymentAllocation;
use App\Domain\Payments\Infrastructure\PaymentReceiptCounter;
use App\Models\School;

/**
 * E21.3A2 (E21-D8, ADR 0064 §17): Payments' Finance retention units.
 *
 * A unit is a CLUSTER of charges that must go together: charges sharing a
 * Payment, and a late-fee charge with its source charge. With them go
 * everything that depends on them (the database derives and re-verifies
 * it):
 * - allocations, Payments, receipts and provider events;
 * - fee adjustments and targeted concessions;
 * - fee assessments, assessment and late-fee run items;
 * - carried charge states;
 * - the journal entries of all of these.
 *
 * Rules:
 * - Eligible only when EVERY entry of the cluster is at or before the
 *   horizon; anything later means not yet eligible.
 * - A cluster with any charge still owing (not cancelled, outstanding not
 *   exactly 0) is retained (`open_charge`) until it is settled and that
 *   settlement has itself aged past a later close (ADR 0064 §17).
 * - A cluster above the database's unit cap is retained
 *   (`unit_too_large`).
 * - A canteen order or another live reference is refused by the database
 *   (counted as dependency-blocked).
 * - Receipt counters are never touched, so no receipt number is reused.
 *
 * Reads charges and adjustments only through Fees' `ChargeService` and
 * periods only through Finance (rule 4).
 */
class ChargeRetentionParticipant implements FinanceRetentionParticipant
{
    private const MAX_CHARGES = 500;

    private const MAX_ENTRIES = 2000;

    public function __construct(
        private readonly ChargeService $charges,
        private readonly ChargeStateReader $states,
    ) {}

    public function key(): string
    {
        return 'charges';
    }

    public function claimedEntryIds(School $school): array
    {
        $claimed = [];
        foreach ($this->charges->ledgerFacts($school) as $fact) {
            $claimed[$fact->journalEntryId] = true;
            if ($fact->cancellationJournalEntryId !== null) {
                $claimed[$fact->cancellationJournalEntryId] = true;
            }
        }
        foreach ($this->charges->adjustmentLedgerFacts($school) as $adjustment) {
            $claimed[$adjustment->journalEntryId] = true;
            if ($adjustment->cancellationJournalEntryId !== null) {
                $claimed[$adjustment->cancellationJournalEntryId] = true;
            }
        }
        foreach (Payment::query()->where('school_id', $school->id)->toBase()->pluck('journal_entry_id') as $entryId) {
            $claimed[(string) $entryId] = true;
        }

        return $claimed;
    }

    public function units(School $school, FinancialPeriodSummary $horizon, JournalPeriodIndex $index): array
    {
        $facts = [];
        foreach ($this->charges->ledgerFacts($school) as $fact) {
            $facts[$fact->id] = $fact;
        }
        $entries = [];
        foreach ($facts as $fact) {
            $entries[$fact->id] = array_filter([$fact->journalEntryId, $fact->cancellationJournalEntryId]);
        }
        foreach ($this->charges->adjustmentLedgerFacts($school) as $adjustment) {
            $entries[$adjustment->chargeId][] = $adjustment->journalEntryId;
            if ($adjustment->cancellationJournalEntryId !== null) {
                $entries[$adjustment->chargeId][] = $adjustment->cancellationJournalEntryId;
            }
        }

        $parent = [];
        $find = function (string $id) use (&$parent, &$find): string {
            if (! isset($parent[$id]) || $parent[$id] === $id) {
                return $parent[$id] = $id;
            }

            return $parent[$id] = $find($parent[$id]);
        };
        $union = function (string $a, string $b) use (&$parent, $find): void {
            $parent[$find($a)] = $find($b);
        };

        $byPayment = [];
        foreach (PaymentAllocation::query()->where('payment_allocations.school_id', $school->id)
            ->join('payments', fn ($join) => $join->on('payments.id', '=', 'payment_allocations.payment_id')->on('payments.school_id', '=', 'payment_allocations.school_id'))
            ->toBase()->select(['payment_allocations.charge_id', 'payment_allocations.payment_id', 'payments.journal_entry_id'])->get() as $row) {
            $chargeId = (string) $row->charge_id;
            $entries[$chargeId][] = (string) $row->journal_entry_id;
            if (isset($byPayment[(string) $row->payment_id])) {
                $union($chargeId, $byPayment[(string) $row->payment_id]);
            }
            $byPayment[(string) $row->payment_id] = $chargeId;
        }
        foreach (LateFeeAssessment::query()->where('school_id', $school->id)->toBase()->get(['charge_id', 'source_charge_id']) as $row) {
            $union((string) $row->charge_id, (string) $row->source_charge_id);
        }

        $clusters = [];
        foreach (array_keys($facts) as $chargeId) {
            $clusters[$find($chargeId)][] = $chargeId;
        }

        $candidates = [];
        foreach ($clusters as $chargeIds) {
            $clusterEntries = [];
            foreach ($chargeIds as $chargeId) {
                foreach ($entries[$chargeId] ?? [] as $entryId) {
                    $clusterEntries[$entryId] = true;
                }
            }
            if (array_filter(array_keys($clusterEntries), fn (string $id) => ! $index->isThrough($id, $horizon)) !== []) {
                continue;
            }
            sort($chargeIds);
            $candidates[] = [$chargeIds, array_keys($clusterEntries)];
        }

        $states = $this->states->forCharges($school, array_merge(...array_map(fn (array $c) => $c[0], $candidates ?: [[[]]])));
        $units = [];
        foreach ($candidates as [$chargeIds, $entryIds]) {
            sort($entryIds);
            $students = array_values(array_unique(array_map(fn (string $id) => $facts[$id]->studentId, $chargeIds)));
            $reason = null;
            if (count($chargeIds) > self::MAX_CHARGES || count($entryIds) > self::MAX_ENTRIES) {
                $reason = 'unit_too_large';
            } elseif (array_filter($chargeIds, fn (string $id) => ! $states[$id]->cancelled && ! $states[$id]->net()->isZero()) !== []) {
                $reason = 'open_charge';
            }
            $units[] = new FinanceRetentionUnit($this->key(), $chargeIds, $entryIds, $students, $reason);
        }

        return $units;
    }

    public function readings(School $school, FinanceRetentionUnit $unit): array
    {
        $readings = [];

        // Each charge of the unit owes nothing before; afterwards it no longer exists.
        $states = $this->states->forCharges($school, $unit->chargeIds);
        foreach ($unit->chargeIds as $chargeId) {
            $readings["charge:{$chargeId}"] = isset($states[$chargeId]) ? $states[$chargeId]->outstanding()->amount() : '0.00';
        }

        // Each affected Student's dues, over every charge they have.
        foreach ($unit->studentIds as $studentId) {
            $chargeIds = array_map(fn ($line) => $line->chargeId, $this->charges->statementLinesForStudent($school, $studentId));
            $dues = '0.00';
            foreach ($this->states->forCharges($school, $chargeIds) as $state) {
                $dues = bcadd($dues, $state->outstanding()->amount(), 2);
            }
            $readings["student:{$studentId}"] = $dues;
        }

        // Receipt series: the next number of every series never moves.
        foreach (PaymentReceiptCounter::query()->where('school_id', $school->id)->orderBy('series_key')->get() as $counter) {
            $readings["receipt_series:{$counter->series_key}"] = (string) $counter->next_value;
        }

        return $readings;
    }
}
