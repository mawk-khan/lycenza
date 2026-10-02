<?php

namespace App\Domain\Finance\Application\Periods;

use App\Models\School;

/**
 * E21.3A (ADR 0064 §8): how far one School's Finance evidence is from a
 * retention cutover. Read-only and never "ready": deleting historical
 * Finance evidence does not exist in this repository (E21.3A2 is the
 * checkpoint that may add it, after ratification), so
 * `retention_cutover_not_implemented` always stands.
 *
 * Blockers (codes):
 * - `unmapped_journal_entries`: entries without a period (not backfilled,
 *   or ambiguous);
 * - `no_closed_financial_period`: nothing has been closed yet;
 * - `balance_verification_failed`: the dual-read check differs;
 * - `retention_cutover_not_implemented`: permanent in E21.3A.
 */
class FinanceRetentionReadiness
{
    public const CUTOVER_NOT_IMPLEMENTED = 'retention_cutover_not_implemented';

    public function __construct(
        private readonly FinancialPeriodService $periods,
        private readonly FinancialBalanceVerifier $verifier,
    ) {}

    /**
     * @return array{blockers: list<string>, closed_through: ?string, unmapped_entries: int, verification: string, mismatches: int}
     */
    public function assess(School $school): array
    {
        $unmapped = $this->periods->unmappedEntryCount($school);
        $latest = $this->periods->latestClosed($school);
        $verification = $this->verifier->verifySnapshot($school);

        return [
            'blockers' => array_values(array_filter([
                $unmapped > 0 ? 'unmapped_journal_entries' : null,
                $latest === null ? 'no_closed_financial_period' : null,
                $verification->passed() ? null : 'balance_verification_failed',
                self::CUTOVER_NOT_IMPLEMENTED,
            ])),
            'closed_through' => $latest?->endsOn,
            'unmapped_entries' => $unmapped,
            'verification' => $verification->passed() ? 'passed' : 'failed',
            'mismatches' => count($verification->all()),
        ];
    }
}
