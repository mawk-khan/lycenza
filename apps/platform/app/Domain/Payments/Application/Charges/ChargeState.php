<?php

namespace App\Domain\Payments\Application\Charges;

use App\Support\Money\Money;

/**
 * E21.3A2: one charge's financial state: its amount, what is allocated to
 * it, its live fee adjustments and whether it is cancelled. Exact Money
 * only.
 */
final class ChargeState
{
    public function __construct(
        public readonly string $chargeId,
        public readonly string $studentId,
        public readonly Money $amount,
        public readonly bool $cancelled,
        public readonly Money $allocated,
        public readonly Money $adjusted,
    ) {}

    /** Amount - allocations - live adjustments (whether or not cancelled). */
    public function net(): Money
    {
        return $this->amount->add($this->allocated->negated())->add($this->adjusted->negated());
    }

    /** What the Student owes on it: nothing once cancelled (FEE.4 statement rule). */
    public function outstanding(): Money
    {
        return $this->cancelled ? Money::of('0.00', $this->amount->currency()) : $this->net();
    }
}
