<?php

namespace App\Domain\Payroll\Statutory\Calculation;

/**
 * Checkpoint 9.6A/9.6B (ADR 0036 correction addendum §1.3/§1.5) --
 * effective-dated income-tax rule constants under the Income-tax Act,
 * 2025 (Section 392's annualized/average-rate principle), never a
 * hardcoded slab table or a flat hardcoded surcharge inside the
 * calculation service.
 */
final class IncomeTaxRuleVersion
{
    public function __construct(
        public readonly string $effectiveFrom,
        public readonly string $regime,
        /** @var list<array{upTo: ?string, rate: string}> Ordered ascending; slab boundaries are CUMULATIVE income thresholds, rate applies to the portion of income within that band. Final row's `upTo` is null. */
        public readonly array $slabs,
        public readonly string $standardDeduction,
        public readonly string $rebateQualifyingIncomeThreshold,
        public readonly string $rebateMaximum,
        public readonly string $cessRate,
        /** @var list<array{threshold: string, rate: string}> Surcharge slabs, ascending by threshold -- table-driven, never a single hardcoded flat rate. */
        public readonly array $surchargeSlabs,
    ) {}

    public static function newRegimeEffectiveApril2026(): self
    {
        return new self(
            effectiveFrom: '2026-04-01',
            regime: 'new',
            slabs: [
                ['upTo' => '400000.00', 'rate' => '0.00'],
                ['upTo' => '800000.00', 'rate' => '0.05'],
                ['upTo' => '1200000.00', 'rate' => '0.10'],
                ['upTo' => '1600000.00', 'rate' => '0.15'],
                ['upTo' => '2000000.00', 'rate' => '0.20'],
                ['upTo' => '2400000.00', 'rate' => '0.25'],
                ['upTo' => null, 'rate' => '0.30'],
            ],
            standardDeduction: '75000.00',
            rebateQualifyingIncomeThreshold: '1200000.00',
            rebateMaximum: '60000.00',
            cessRate: '0.04',
            surchargeSlabs: [
                ['threshold' => '5000000.00', 'rate' => '0.10'],
                ['threshold' => '10000000.00', 'rate' => '0.15'],
                ['threshold' => '20000000.00', 'rate' => '0.25'],
            ],
        );
    }

    /**
     * Old regime, unaffected by the correction addendum (which
     * corrects the NEW regime's §87A figures specifically) -- the
     * long-established old-regime slabs/rebate, provided so an
     * employee's own School-policy-approved regime election
     * (§"School policy", never itself a statutory rule) can still be
     * honoured.
     */
    public static function oldRegimeEffectiveApril2026(): self
    {
        return new self(
            effectiveFrom: '2026-04-01',
            regime: 'old',
            slabs: [
                ['upTo' => '250000.00', 'rate' => '0.00'],
                ['upTo' => '500000.00', 'rate' => '0.05'],
                ['upTo' => '1000000.00', 'rate' => '0.20'],
                ['upTo' => null, 'rate' => '0.30'],
            ],
            standardDeduction: '50000.00',
            rebateQualifyingIncomeThreshold: '500000.00',
            rebateMaximum: '12500.00',
            cessRate: '0.04',
            surchargeSlabs: [
                ['threshold' => '5000000.00', 'rate' => '0.10'],
                ['threshold' => '10000000.00', 'rate' => '0.15'],
                ['threshold' => '20000000.00', 'rate' => '0.25'],
                ['threshold' => '50000000.00', 'rate' => '0.37'],
            ],
        );
    }
}
