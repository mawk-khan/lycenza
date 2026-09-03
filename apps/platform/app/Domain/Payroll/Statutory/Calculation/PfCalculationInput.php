<?php

namespace App\Domain\Payroll\Statutory\Calculation;

use App\Support\Money\Money;

/**
 * Checkpoint 9.6B -- one EmploymentRecord's PF calculation input for
 * one payroll cycle. `componentAmounts` groups every statutory-
 * participating salary component's amount under its
 * `PfComponentClassification` (never a component NAME) -- see that
 * enum's own docblock.
 */
final class PfCalculationInput
{
    /**
     * @param  array<string, Money>  $componentAmounts  Money amounts summed per PfComponentClassification::value key. Any classification absent from this array is treated as zero for that bucket.
     */
    public function __construct(
        public readonly array $componentAmounts,
        public readonly PfEmployeeStatutoryFacts $facts,
        public readonly ?Money $voluntaryEmployeeContribution = null,
    ) {}

    private function bucket(PfComponentClassification $classification): Money
    {
        return $this->componentAmounts[$classification->value] ?? Money::of('0.00', 'INR');
    }

    public function coreWage(): Money
    {
        return $this->bucket(PfComponentClassification::CoreWage);
    }

    public function testedRemuneration(): Money
    {
        return $this->bucket(PfComponentClassification::TestedRemuneration);
    }
}
