<?php

namespace App\Domain\Payroll\Statutory\Calculation;

use App\Support\Money\Money;

final class EsiContributionResult
{
    public function __construct(
        public readonly bool $isCovered,
        public readonly Money $employeeContribution,
        public readonly Money $employerContribution,
    ) {}
}
