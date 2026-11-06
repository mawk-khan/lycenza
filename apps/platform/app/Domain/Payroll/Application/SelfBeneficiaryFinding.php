<?php

namespace App\Domain\Payroll\Application;

/**
 * HR.R1-C6 (OD-HR-24): `$conflicted` is about the one actor asked about, and
 * `$authored` says they recorded such a change (asked of an obligation only).
 * `$unlinkedEmployees` counts first-paid Employees with no uncorrected link, current
 * or ended: the check cannot see who they are, so this is never evidence that no
 * conflict exists. `$correctedEmployees` are those of them with a link corrected as
 * erroneous (OD-HR-25). They are still unknown: a correction says who an Employee is
 * not, never who they are.
 */
final readonly class SelfBeneficiaryFinding
{
    public function __construct(public bool $conflicted, public int $unlinkedEmployees, public bool $authored = false, public int $correctedEmployees = 0) {}
}
