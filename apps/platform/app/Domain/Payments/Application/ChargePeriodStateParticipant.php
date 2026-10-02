<?php

namespace App\Domain\Payments\Application;

use App\Domain\Fees\Application\ChargeLedgerFact;
use App\Domain\Fees\Application\ChargeService;
use App\Domain\Fees\Application\FeeAdjustmentLedgerFact;
use App\Domain\Finance\Application\Periods\FinancialPeriodCloseParticipant;
use App\Domain\Finance\Application\Periods\FinancialPeriodSummary;
use App\Domain\Finance\Application\Periods\JournalPeriodIndex;
use App\Domain\Payments\Application\Charges\AllHistoryChargeReader;
use App\Domain\Payments\Application\Charges\ChargeStateReader;
use App\Domain\Payments\Infrastructure\FinancialPeriodChargeState;
use App\Domain\Payments\Infrastructure\PaymentAllocation;
use App\Domain\Payments\Infrastructure\PaymentReceipt;
use App\Models\School;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Uid\UuidV7;

/**
 * E21.3A (ADR 0064 §5): carries every charge's state across a financial
 * period close, so a charge's outstanding and a Student's dues no longer
 * need the closed period's detail.
 *
 * A charge's state AS OF period P counts only facts whose journal entry is
 * in P or earlier:
 * - the charge itself (assessed through P);
 * - allocations (by their Payment's settlement entry);
 * - fee adjustments posted through P and not voided through P;
 * - cancellation (by the cancellation entry).
 *
 * Current state = latest closed state + later facts:
 * - later allocations;
 * - minus adjustments posted through P but voided later;
 * - plus later adjustments still live;
 * - cancellation if it happened later.
 *
 * `verify()` compares the production read (`ChargeStateReader`, which every
 * outstanding, due, statement and capacity check now uses) with the
 * all-history reading (`AllHistoryChargeReader`, verification only) per
 * charge and per Student (E21.3A2).
 *
 * Payments reads charges and adjustments only through Fees' `ChargeService`
 * and periods only through Finance's `JournalPeriodIndex` (rule 4).
 */
class ChargePeriodStateParticipant implements FinancialPeriodCloseParticipant
{
    private const SCALE = 2;

    public function __construct(
        private readonly ChargeService $charges,
        private readonly ChargeStateReader $states,
        private readonly AllHistoryChargeReader $allHistory,
    ) {}

    public function key(): string
    {
        return 'charges';
    }

    public function blockers(School $school, FinancialPeriodSummary $period, JournalPeriodIndex $index): array
    {
        return [];
    }

    /**
     * Receipt numbers follow the settlement date and the ledger period the
     * posting date, so a payment recorded late can carry a receipt of the
     * previous series inside this period. That is expected and never
     * renumbered; it is surfaced so the admin is not surprised.
     */
    public function notices(School $school, FinancialPeriodSummary $period, JournalPeriodIndex $index): array
    {
        $differs = false;
        foreach (PaymentReceipt::query()->where('payment_receipts.school_id', $school->id)
            ->join('payments', fn ($join) => $join->on('payments.id', '=', 'payment_receipts.payment_id')->on('payments.school_id', '=', 'payment_receipts.school_id'))
            ->toBase()->select(['payment_receipts.series_key', 'payments.journal_entry_id'])->cursor() as $row) {
            if ($index->isIn((string) $row->journal_entry_id, $period) && $row->series_key !== $period->key) {
                $differs = true;
                break;
            }
        }

        return $differs ? ['receipt_series_differs_from_period'] : [];
    }

    public function snapshot(School $school, FinancialPeriodSummary $period, JournalPeriodIndex $index): string
    {
        [$charges, $allocations, $adjustments] = $this->facts($school);

        $rows = [];
        $canonical = [];
        foreach ($charges as $charge) {
            if (! $index->isThrough($charge->journalEntryId, $period)) {
                continue;
            }
            $allocated = '0.00';
            foreach ($allocations[$charge->id] ?? [] as [$amount, $entryId]) {
                if ($index->isThrough($entryId, $period)) {
                    $allocated = bcadd($allocated, $amount, self::SCALE);
                }
            }
            $live = '0.00';
            foreach ($adjustments[$charge->id] ?? [] as $adjustment) {
                if ($index->isThrough($adjustment->journalEntryId, $period)
                    && ! ($adjustment->cancelled && $index->isThrough($adjustment->cancellationJournalEntryId, $period))) {
                    $live = bcadd($live, $adjustment->amount, self::SCALE);
                }
            }
            $cancelled = $charge->cancelled && $index->isThrough($charge->cancellationJournalEntryId, $period);

            $rows[] = [
                'id' => (string) new UuidV7,
                'school_id' => $school->id,
                'financial_period_id' => $period->id,
                'charge_id' => $charge->id,
                'currency' => $charge->currency,
                'amount' => $charge->amount,
                'allocated_total' => $allocated,
                'live_adjusted_total' => $live,
                'cancelled' => $cancelled,
                'created_at' => now(),
            ];
            $canonical[] = "{$charge->id}|{$charge->currency}|{$charge->amount}|{$allocated}|{$live}|".($cancelled ? '1' : '0');
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('financial_period_charge_states')->insert($chunk);
        }

        return hash('sha256', implode("\n", $canonical));
    }

    public function verify(School $school, JournalPeriodIndex $index): array
    {
        // OLD: every retained fact (verification only); NEW: the production
        // carry-forward read. A retained charge always keeps all of its facts
        // (retention removes whole settled units), so they must agree.
        $old = $this->allHistory->forCharges($school);
        $new = $this->states->forCharges($school, array_keys($old));
        $latest = $index->latestClosed;
        $baseline = $latest === null ? collect() : FinancialPeriodChargeState::query()
            ->where('school_id', $school->id)
            ->where('financial_period_id', $latest->id)
            ->pluck('charge_id')
            ->flip();

        $assessedEntry = [];
        foreach ($this->charges->ledgerFacts($school) as $fact) {
            $assessedEntry[$fact->id] = $fact->journalEntryId;
        }

        $mismatches = [];
        $oldDues = [];
        $newDues = [];
        foreach ($old as $id => $o) {
            $n = $new[$id] ?? null;
            if ($n === null) {
                $mismatches[] = "charge:{$id}:missing";

                continue;
            }
            if ($latest !== null && ! $baseline->has($id) && $index->isClosedHistory($assessedEntry[$id] ?? null)) {
                $mismatches[] = "charge:{$id}:state_missing";
            }
            foreach (['cancelled' => [$o->cancelled, $n->cancelled], 'allocated' => [$o->allocated->amount(), $n->allocated->amount()],
                'adjusted' => [$o->adjusted->amount(), $n->adjusted->amount()], 'outstanding' => [$o->outstanding()->amount(), $n->outstanding()->amount()]] as $field => [$a, $b]) {
                if ($a !== $b) {
                    $mismatches[] = "charge:{$id}:{$field}";
                }
            }
            $oldDues[$o->studentId] = bcadd($oldDues[$o->studentId] ?? '0.00', $o->outstanding()->amount(), self::SCALE);
            $newDues[$n->studentId] = bcadd($newDues[$n->studentId] ?? '0.00', $n->outstanding()->amount(), self::SCALE);
        }
        foreach ($oldDues as $studentId => $dues) {
            if (bccomp($dues, $newDues[$studentId] ?? '0.00', self::SCALE) !== 0) {
                $mismatches[] = "student:{$studentId}:dues";
            }
        }
        foreach ($baseline->keys() as $chargeId) {
            if (! isset($old[(string) $chargeId])) {
                $mismatches[] = "charge:{$chargeId}:state_orphan";
            }
        }

        return $mismatches;
    }

    /**
     * @return array{0: list<ChargeLedgerFact>, 1: array<string, list<array{0: string, 1: string}>>, 2: array<string, list<FeeAdjustmentLedgerFact>>}
     */
    private function facts(School $school): array
    {
        $allocations = [];
        foreach (PaymentAllocation::query()->where('payment_allocations.school_id', $school->id)
            ->join('payments', fn ($join) => $join->on('payments.id', '=', 'payment_allocations.payment_id')->on('payments.school_id', '=', 'payment_allocations.school_id'))
            ->toBase()->select(['payment_allocations.charge_id', 'payment_allocations.amount', 'payments.journal_entry_id'])->cursor() as $row) {
            $allocations[(string) $row->charge_id][] = [(string) $row->amount, (string) $row->journal_entry_id];
        }

        $adjustments = [];
        foreach ($this->charges->adjustmentLedgerFacts($school) as $adjustment) {
            $adjustments[$adjustment->chargeId][] = $adjustment;
        }

        return [$this->charges->ledgerFacts($school), $allocations, $adjustments];
    }
}
