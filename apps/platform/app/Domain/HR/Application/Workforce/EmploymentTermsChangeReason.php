<?php

namespace App\Domain\HR\Application\Workforce;

/**
 * HR.1A: why an employment's terms change (`employment_terms_reason_check`).
 * Only these three are chosen by a person; `initial` (the first terms, made
 * with the employment) and `backfill` (the HR.1A cut-over) are recorded by
 * the database alone. No free text: a reason never carries a health,
 * performance or disciplinary narrative.
 */
enum EmploymentTermsChangeReason: string
{
    case RECLASSIFICATION = 'reclassification';
    case CONTRACT_CHANGE = 'contract_change';
    case CORRECTION = 'correction';

    public function label(): string
    {
        return match ($this) {
            self::RECLASSIFICATION => 'Reclassification',
            self::CONTRACT_CHANGE => 'Contract change',
            self::CORRECTION => 'Correction of a recording error',
        };
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

    /** Every recorded value, the system ones included (display). */
    public static function labelFor(string $value): string
    {
        return match ($value) {
            'initial' => 'Start of employment',
            'backfill' => 'Recorded at the HR.1A cut-over',
            default => self::tryFrom($value)?->label() ?? $value,
        };
    }
}
