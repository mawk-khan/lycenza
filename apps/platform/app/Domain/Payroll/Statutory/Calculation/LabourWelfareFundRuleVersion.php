<?php

namespace App\Domain\Payroll\Statutory\Calculation;

/**
 * Checkpoint 9.6A/9.6B (ADR 0036 correction addendum §1.7) --
 * Telangana Labour Welfare Fund fixed amounts, charged once per
 * statutory annual cycle -- never twice yearly.
 */
final class LabourWelfareFundRuleVersion
{
    public function __construct(
        public readonly string $effectiveFrom,
        public readonly string $jurisdiction,
        public readonly string $employeeAmount,
        public readonly string $employerAmount,
    ) {}

    public static function telanganaEffectiveApril2026(): self
    {
        return new self(
            effectiveFrom: '2026-04-01',
            jurisdiction: 'Telangana',
            employeeAmount: '2.00',
            employerAmount: '5.00',
        );
    }
}
