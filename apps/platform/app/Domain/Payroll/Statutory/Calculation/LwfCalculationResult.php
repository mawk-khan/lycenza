<?php

namespace App\Domain\Payroll\Statutory\Calculation;

use App\Support\Money\Money;

final class LwfCalculationResult
{
    public function __construct(
        public readonly bool $shouldCharge,
        public readonly Money $employeeAmount,
        public readonly Money $employerAmount,
    ) {}
}
