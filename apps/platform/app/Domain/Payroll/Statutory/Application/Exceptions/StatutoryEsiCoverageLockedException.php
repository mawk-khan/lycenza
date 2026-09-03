<?php

namespace App\Domain\Payroll\Statutory\Application\Exceptions;

/**
 * Checkpoint 9.6I -- ESI coverage for a contribution period, once a
 * `payroll_statutory_calculation_results` row exists that consumed
 * it, is immutable (ADR 0036 correction addendum §1.8's "decided
 * once" continuity rule, extended here to admin corrections too: a
 * correction after the fact would silently change history a payslip
 * or Finance posting already relied on). An admin wishing to correct
 * coverage for an ALREADY-CONSUMED period must instead correct the
 * next period going forward, or use Payroll's own correction-run
 * mechanism for the financial effect.
 */
class StatutoryEsiCoverageLockedException extends StatutoryPayrollException
{
    public function __construct(string $employmentRecordId, string $periodStart)
    {
        parent::__construct(
            409,
            'STATUTORY_ESI_COVERAGE_LOCKED',
            "ESI coverage for employment record '{$employmentRecordId}' period starting '{$periodStart}' has already been used by a finalized statutory calculation and can no longer be changed.",
        );
    }
}
