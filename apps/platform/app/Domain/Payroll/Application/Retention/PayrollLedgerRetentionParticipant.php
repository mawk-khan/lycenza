<?php

namespace App\Domain\Payroll\Application\Retention;

use App\Domain\Finance\Application\Periods\FinancialPeriodSummary;
use App\Domain\Finance\Application\Periods\JournalPeriodIndex;
use App\Domain\Finance\Application\Retention\FinanceRetentionParticipant;
use App\Domain\Finance\Application\Retention\FinanceRetentionUnit;
use App\Domain\Payroll\Infrastructure\PayrollRunPosting;
use App\Domain\Payroll\Statutory\Infrastructure\PayrollStatutoryRunPosting;
use App\Models\School;

/**
 * E21.3A2 (E21-D8 x E21-D9, ADR 0064 §17): payroll postings reference
 * their journal entries, and payroll runs, results and postings are D9
 * employment/payroll evidence. Finance D8 never deletes them, and so never
 * deletes the journal entries they reference: every such entry at or before
 * the horizon is reported as a RETAINED unit
 * (`payroll_evidence_retained`).
 *
 * This is the recorded D8/D9 intersection. Payroll ledger detail stays
 * until a Payroll-owned D9 mechanism expires the payroll records
 * themselves (none exists). Payroll totals stay exact either way: they
 * are ledger-account totals, carried in the account baselines.
 */
class PayrollLedgerRetentionParticipant implements FinanceRetentionParticipant
{
    public function key(): string
    {
        return 'payroll';
    }

    public function claimedEntryIds(School $school): array
    {
        $claimed = [];
        foreach ([PayrollRunPosting::class, PayrollStatutoryRunPosting::class] as $model) {
            foreach ($model::query()->where('school_id', $school->id)->toBase()->pluck('journal_entry_id') as $entryId) {
                $claimed[(string) $entryId] = true;
            }
        }

        return $claimed;
    }

    public function units(School $school, FinancialPeriodSummary $horizon, JournalPeriodIndex $index): array
    {
        $units = [];
        foreach (array_keys($this->claimedEntryIds($school)) as $entryId) {
            if ($index->isThrough($entryId, $horizon)) {
                $units[] = new FinanceRetentionUnit($this->key(), [], [$entryId], [], 'payroll_evidence_retained');
            }
        }

        return $units;
    }

    public function readings(School $school, FinanceRetentionUnit $unit): array
    {
        return [];
    }
}
