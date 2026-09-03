<?php

namespace App\Domain\Payroll\Statutory\Calculation;

/**
 * Checkpoint 9.6A/9.6B (ADR 0035 correction addendum §1.6) --
 * Telangana Professional Tax monthly-wage slabs, effective-dated
 * rather than hardcoded in the service.
 */
final class ProfessionalTaxRuleVersion
{
    public function __construct(
        public readonly string $effectiveFrom,
        public readonly string $jurisdiction,
        /** @var list<array{upTo: ?string, amount: string}> Ordered ascending by `upTo`; the final row's `upTo` is null (no upper bound). */
        public readonly array $slabs,
    ) {}

    public static function telanganaEffectiveApril2026(): self
    {
        return new self(
            effectiveFrom: '2026-04-01',
            jurisdiction: 'Telangana',
            slabs: [
                ['upTo' => '15000.00', 'amount' => '0.00'],
                ['upTo' => '20000.00', 'amount' => '150.00'],
                ['upTo' => null, 'amount' => '200.00'],
            ],
        );
    }
}
