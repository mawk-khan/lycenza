<?php

namespace App\Domain\Payroll\Application;

/**
 * HR.R1-C6 (OD-HR-24): `$conflicted` is about the one actor asked about, and
 * `$authored` says they recorded such a change (asked of an obligation only).
 * `$unlinkedEmployees` counts first-paid Employees with no uncorrected link, current
 * or ended: the check cannot see who they are, so this is never evidence that no
 * conflict exists. A correction says who an Employee is not, never who they are, so
 * an Employee left with only corrected links is among them.
 * `$correctedEmployees` counts first-paid Employees with any corrected link (OD-HR-25)
 * and `$actorHadCorrectedLink` says the one actor asked about holds one of those.
 * Neither blocks anything: both are recorded on the decision, so a correction that
 * cleared someone's way is visible on the decision it cleared.
 */
final readonly class SelfBeneficiaryFinding
{
    public function __construct(public bool $conflicted, public int $unlinkedEmployees, public bool $authored = false, public int $correctedEmployees = 0, public bool $actorHadCorrectedLink = false) {}
}
