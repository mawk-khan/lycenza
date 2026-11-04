<?php

namespace App\Domain\HR\Application\Separation;

/**
 * HR.12 (owner decision 2026-11-04, "closed core set"): why an employment is
 * being separated through a case (`employment_separation_cases_reason_check`).
 * A closed list chosen by a person; never free text. There is deliberately no
 * dismissal or disciplinary reason (OD-HR-8, legal first) and no health reason
 * (HRX-L1). Each maps to the terminal employment status its finalization
 * writes. Adding a reason is a reviewed code and migration change.
 */
enum SeparationReason: string
{
    case RESIGNATION = 'resignation';
    case RETIREMENT = 'retirement';
    case CONTRACT_END = 'contract_end';
    case MUTUAL_AGREEMENT = 'mutual_agreement';

    public function label(): string
    {
        return match ($this) {
            self::RESIGNATION => 'Resignation',
            self::RETIREMENT => 'Retirement',
            self::CONTRACT_END => 'End of fixed-term contract',
            self::MUTUAL_AGREEMENT => 'Mutual agreement',
        };
    }

    /** The terminal `employment_records.status` the finalization writes. */
    public function terminalStatus(): string
    {
        return $this === self::RETIREMENT ? 'retired' : 'separated';
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $r) => $r->value, self::cases());
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(fn (self $r) => ['value' => $r->value, 'label' => $r->label()], self::cases());
    }
}
