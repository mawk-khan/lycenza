<?php

namespace App\Domain\Payments\Application;

use App\Domain\Fees\Application\ChargeLedgerFact;
use App\Domain\Fees\Application\ChargeService;
use App\Domain\Fees\Application\FeeAdjustmentLedgerFact;
use App\Domain\Finance\Application\Periods\FinancialPeriodCloseParticipant;
use App\Domain\Finance\Application\Periods\FinancialPeriodSummary;
use App\Domain\Finance\Application\Periods\JournalPeriodIndex;
use App\Domain\Payments\Infrastructure\FinancialPeriodChargeState;
use App\Domain\Payments\Infrastructure\PaymentAllocation;
use App\Domain\Payments\Infrastructure\PaymentReceipt;
use App\Models\School;
use App\Support\Money\Money;
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
 * `verify()` compares that with the all-history reading (the same formula
 * `ChargeOutstandingReader` and `StudentFeeStatementReadService` use) per
 * charge and per Student.
 *
 * Payments reads charges and adjustments only through Fees' `ChargeService`
 * and periods only through Finance's `JournalPeriodIndex` (rule 4).
 */
class ChargePeriodStateParticipant implements FinancialPeriodCloseParticipant
{
    private const SCALE = 2;

    public function __construct(
        private readonly ChargeService $charges,
        private readonly ChargeOutstandingReader $outstanding,
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
        [$charges, $allocations, $adjustments] = $this->facts($school);
        $latest = $index->latestClosed;
        $baseline = $latest === null ? collect() : FinancialPeriodChargeState::query()
            ->where('school_id', $school->id)
            ->where('financial_period_id', $latest->id)
            ->get()
            ->keyBy('charge_id');

        $amounts = [];
        foreach ($charges as $charge) {
            $amounts[$charge->id] = Money::of($charge->amount, $charge->currency);
        }
        $oldOutstanding = $this->outstanding->forCharges($school, $amounts);

        $mismatches = [];
        $oldDues = [];
        $newDues = [];
        foreach ($charges as $charge) {
            // OLD: the whole history, the formula every current read uses.
            $oldAllocated = '0.00';
            foreach ($allocations[$charge->id] ?? [] as [$amount]) {
                $oldAllocated = bcadd($oldAllocated, $amount, self::SCALE);
            }
            $oldLive = '0.00';
            foreach ($adjustments[$charge->id] ?? [] as $adjustment) {
                if (! $adjustment->cancelled) {
                    $oldLive = bcadd($oldLive, $adjustment->amount, self::SCALE);
                }
            }
            $old = $charge->cancelled ? '0.00' : $oldOutstanding[$charge->id]->amount();

            // NEW: latest closed state + later detail only.
            $state = $baseline->get($charge->id);
            if ($state === null) {
                if ($index->isClosedHistory($charge->journalEntryId)) {
                    $mismatches[] = "charge:{$charge->id}:state_missing";
                }
                [$cancelled, $allocated, $live] = [$charge->cancelled, $oldAllocated, $oldLive];
            } else {
                if (bccomp((string) $state->amount, $charge->amount, self::SCALE) !== 0 || $state->currency !== $charge->currency) {
                    $mismatches[] = "charge:{$charge->id}:amount";
                }
                $cancelled = $state->cancelled || ($charge->cancelled && ! $index->isClosedHistory($charge->cancellationJournalEntryId));
                $allocated = (string) $state->allocated_total;
                foreach ($allocations[$charge->id] ?? [] as [$amount, $entryId]) {
                    if (! $index->isClosedHistory($entryId)) {
                        $allocated = bcadd($allocated, $amount, self::SCALE);
                    }
                }
                $live = (string) $state->live_adjusted_total;
                foreach ($adjustments[$charge->id] ?? [] as $adjustment) {
                    if ($index->isClosedHistory($adjustment->journalEntryId)) {
                        if ($adjustment->cancelled && ! $index->isClosedHistory($adjustment->cancellationJournalEntryId)) {
                            $live = bcsub($live, $adjustment->amount, self::SCALE);
                        }
                    } elseif (! $adjustment->cancelled) {
                        $live = bcadd($live, $adjustment->amount, self::SCALE);
                    }
                }
            }
            $new = $cancelled ? '0.00' : bcsub(bcsub($charge->amount, $allocated, self::SCALE), $live, self::SCALE);

            if ($cancelled !== $charge->cancelled) {
                $mismatches[] = "charge:{$charge->id}:cancelled";
            }
            if (bccomp($allocated, $oldAllocated, self::SCALE) !== 0) {
                $mismatches[] = "charge:{$charge->id}:allocated";
            }
            if (bccomp($live, $oldLive, self::SCALE) !== 0) {
                $mismatches[] = "charge:{$charge->id}:adjusted";
            }
            if (bccomp($new, $old, self::SCALE) !== 0) {
                $mismatches[] = "charge:{$charge->id}:outstanding";
            }

            $oldDues[$charge->studentId] = bcadd($oldDues[$charge->studentId] ?? '0.00', $old, self::SCALE);
            $newDues[$charge->studentId] = bcadd($newDues[$charge->studentId] ?? '0.00', $new, self::SCALE);
        }

        foreach ($oldDues as $studentId => $dues) {
            if (bccomp($dues, $newDues[$studentId], self::SCALE) !== 0) {
                $mismatches[] = "student:{$studentId}:dues";
            }
        }
        $known = array_flip(array_map(fn (ChargeLedgerFact $charge) => $charge->id, $charges));
        foreach ($baseline->keys() as $chargeId) {
            if (! isset($known[(string) $chargeId])) {
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
