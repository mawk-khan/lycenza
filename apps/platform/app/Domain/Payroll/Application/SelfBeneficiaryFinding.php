<?php

namespace App\Domain\Payroll\Application;

/**
 * HR.R1-C6 (OD-HR-24): `$conflicted` is about the one actor asked about, and
 * `$authored` says they recorded such a change (asked of an obligation only).
 * `$unlinkedEmployees` counts first-paid Employees with no linked account: the
 * check cannot see them, so this is never evidence that no conflict exists.
 */
final readonly class SelfBeneficiaryFinding
{
    public function __construct(public bool $conflicted, public int $unlinkedEmployees, public bool $authored = false) {}
}
