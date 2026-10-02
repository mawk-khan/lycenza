<?php

namespace App\Domain\Finance\Application\Periods;

use App\Models\School;

/**
 * E21.3A (ADR 0064 §5): a subledger that carries its own state across a
 * financial-period close. Finance defines the contract and calls each
 * participant inside the close transaction; the implementations live in the
 * modules that depend on Finance (today Payments' charge states), so
 * Finance never depends on them (CLAUDE.md rule 4). Participants are
 * registered under the container tag `TAG`.
 *
 * Every method runs inside the caller's School TenantContext. `snapshot()`
 * and `verify()` run inside the close transaction, after the close
 * service has locked the School's open periods.
 */
interface FinancialPeriodCloseParticipant
{
    public const TAG = 'finance.period_close_participants';

    /** A stable key ("charges"), used in blocker codes and the fingerprint. */
    public function key(): string;

    /**
     * Deterministic reasons the period cannot close yet (machine codes).
     *
     * @return list<string>
     */
    public function blockers(School $school, FinancialPeriodSummary $period, JournalPeriodIndex $index): array;

    /**
     * Informational findings that do not block (machine codes).
     *
     * @return list<string>
     */
    public function notices(School $school, FinancialPeriodSummary $period, JournalPeriodIndex $index): array;

    /**
     * Writes this participant's immutable baseline for $period (the period
     * is still open) and returns a canonical digest of what it wrote.
     */
    public function snapshot(School $school, FinancialPeriodSummary $period, JournalPeriodIndex $index): string;

    /**
     * Dual-read check: the current state computed from all history must
     * equal the latest closed baseline plus later detail, exactly.
     *
     * @return list<string> mismatch descriptions (ids and codes only, never amounts or names)
     */
    public function verify(School $school, JournalPeriodIndex $index): array;
}
