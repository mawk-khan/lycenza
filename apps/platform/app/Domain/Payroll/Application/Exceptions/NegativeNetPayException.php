<?php

namespace App\Domain\Payroll\Application\Exceptions;

/**
 * Thrown when a calculated result would produce a negative net amount
 * for a regular run (ADR 0034 "Calculation semantics" / Mandatory
 * Decision #13) -- rejected outright rather than silently clamped to
 * zero or allowed to post as a negative liability. The database CHECK
 * `payroll_run_results_net_identity_check` plus the run-kind-aware
 * sign trigger `trg_payroll_run_results_validate_sign` remain the
 * authoritative backstop.
 */
class NegativeNetPayException extends PayrollException
{
    public function __construct(public readonly string $employmentRecordId, public readonly string $netAmount)
    {
        parent::__construct(422, 'PAYROLL_NEGATIVE_NET_PAY', "Calculated net pay for EmploymentRecord {$employmentRecordId} is negative ({$netAmount}) -- rejected.");
    }
}
