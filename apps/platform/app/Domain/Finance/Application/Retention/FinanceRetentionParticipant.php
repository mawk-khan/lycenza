<?php

namespace App\Domain\Finance\Application\Retention;

use App\Domain\Finance\Application\Periods\FinancialPeriodSummary;
use App\Domain\Finance\Application\Periods\JournalPeriodIndex;
use App\Models\School;

/**
 * E21.3A2 (ADR 0064 §17): a module whose records reference Finance journal
 * entries and so decide when that evidence may expire. Finance defines the
 * contract; Payments (charges, payments, adjustments, late fees, receipts)
 * and Payroll (postings, always retained under D9) implement it, so Finance
 * never reads their tables (CLAUDE.md rule 4). Registered under `TAG`.
 *
 * Every method runs inside the School's TenantContext.
 */
interface FinanceRetentionParticipant
{
    public const TAG = 'finance.retention_participants';

    public function key(): string;

    /**
     * Journal entries this module's records reference: never standalone.
     *
     * @return array<string, true>
     */
    public function claimedEntryIds(School $school): array;

    /**
     * Units whose every journal entry lies at or before `$horizon` (the
     * latest D8-eligible closed period). A unit that must stay carries
     * its reason (`isBlocked()`); units with later activity are not
     * returned at all (not yet eligible).
     *
     * @return list<FinanceRetentionUnit>
     */
    public function units(School $school, FinancialPeriodSummary $horizon, JournalPeriodIndex $index): array;

    /**
     * Exact canonical values the unit could affect (e.g. each affected
     * charge's outstanding, each affected Student's dues, the receipt
     * series' next numbers), read through the production reads. Captured
     * just before and just after the expiry, inside its transaction; any
     * difference rolls the expiry back. Never logged.
     *
     * @return array<string, string>
     */
    public function readings(School $school, FinanceRetentionUnit $unit): array;
}
