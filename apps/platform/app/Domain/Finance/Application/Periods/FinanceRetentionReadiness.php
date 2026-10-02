<?php

namespace App\Domain\Finance\Application\Periods;

use App\Domain\Finance\Application\Retention\FinanceRetentionEligibility;
use App\Domain\Finance\Application\Retention\FinanceRetentionService;
use App\Models\School;
use App\Support\Retention\RetentionHolds;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * E21.3A / E21.3A2 (ADR 0064 §8, §18): how ready one School's Finance
 * evidence is for D8 expiry. Read-only.
 *
 * School-level blockers (codes):
 * - `unmapped_journal_entries`: entries without a period (not backfilled,
 *   or ambiguous); they block every period up to their date;
 * - `no_closed_financial_period`;
 * - `balance_verification_failed`: the dual-read check differs;
 * - `retention_cutover_not_enabled`: FINANCE_RETENTION_ENABLED is off or
 *   FINANCE_RETENTION_YEARS is unset (or invalid);
 * - `legal_hold`.
 *
 * Per period, oldest first: the eligibility blockers
 * (`period_not_closed`, `period_too_young`, `period_mapping_incomplete`,
 * `baseline_missing`, `legal_hold`, `retention_cutover_not_enabled`), else
 * `dependency_blocked` when a retained unit (an unsettled charge, payroll
 * evidence, ...) has entries in it, else `ready`.
 */
class FinanceRetentionReadiness
{
    public function __construct(
        private readonly FinancialPeriodService $periods,
        private readonly FinancialBalanceVerifier $verifier,
        private readonly FinanceRetentionEligibility $eligibility,
        private readonly FinanceRetentionService $retention,
        private readonly RetentionHolds $holds,
    ) {}

    /**
     * @return array{blockers: list<string>, periods: array<string, list<string>>, closed_through: ?string, expired_through: ?string, unmapped_entries: int, verification: string, mismatches: int}
     */
    public function assess(School $school): array
    {
        $unmapped = $this->periods->unmappedEntryCount($school);
        $latest = $this->periods->latestClosed($school);
        $verification = $this->verifier->verifySnapshot($school);
        try {
            $years = $this->eligibility->years();
            $configured = $years !== null && $this->eligibility->enabled();
        } catch (InvalidArgumentException) {
            $years = null;
            $configured = false;
        }

        $now = CarbonImmutable::now('UTC');
        $periods = [];
        foreach (array_reverse($this->periods->periods($school)) as $period) {
            $periods[$period->key] = $this->eligibility->blockers($school, $period, $now, $years);
        }

        $horizon = $this->eligibility->horizon($school, $now, $years);
        if ($horizon !== null) {
            $index = $this->periods->journalPeriodIndex($school);
            $blockedStarts = [];
            foreach ($this->retention->plan($school, $horizon) as $unit) {
                if ($unit->isBlocked()) {
                    foreach ($unit->entryIds as $entryId) {
                        foreach (array_reverse($this->periods->periods($school)) as $period) {
                            if ($index->isIn($entryId, $period)) {
                                $blockedStarts[$period->key] = true;
                            }
                        }
                    }
                }
            }
            foreach ($periods as $key => $codes) {
                if ($codes === [] && isset($blockedStarts[$key])) {
                    $periods[$key] = ['dependency_blocked'];
                }
            }
        }
        foreach ($periods as $key => $codes) {
            if ($codes === []) {
                $periods[$key] = ['ready'];
            }
        }

        return [
            'blockers' => array_values(array_filter([
                $unmapped > 0 ? 'unmapped_journal_entries' : null,
                $latest === null ? 'no_closed_financial_period' : null,
                $verification->passed() ? null : 'balance_verification_failed',
                $configured ? null : 'retention_cutover_not_enabled',
                $this->holds->isHeld($school->id) ? 'legal_hold' : null,
            ])),
            'periods' => $periods,
            'closed_through' => $latest?->endsOn,
            'expired_through' => $this->periods->expiryAnchor($school)?->key,
            'unmapped_entries' => $unmapped,
            'verification' => $verification->passed() ? 'passed' : 'failed',
            'mismatches' => count($verification->all()),
        ];
    }
}
