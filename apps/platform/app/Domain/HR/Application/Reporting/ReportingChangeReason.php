<?php

namespace App\Domain\HR\Application\Reporting;

/**
 * HR.3A: why a reporting line starts, changes, ends or is corrected
 * (`reporting_relationships_reason_check`). A closed list chosen by a person;
 * no free text (no performance, health or disciplinary narrative). A cut-over
 * backfill line has none, and a line ended because an employment ended
 * records how (`ended_via`), not a reason.
 */
enum ReportingChangeReason: string
{
    case ORGANISATIONAL_CHANGE = 'organisational_change';
    case RESTRUCTURING = 'restructuring';
    case EMPLOYEE_TRANSFER = 'employee_transfer';
    case MANAGER_DEPARTURE = 'manager_departure';
    case ADMINISTRATIVE_CORRECTION = 'administrative_correction';

    public function label(): string
    {
        return match ($this) {
            self::ORGANISATIONAL_CHANGE => 'Organisational change',
            self::RESTRUCTURING => 'Restructuring',
            self::EMPLOYEE_TRANSFER => 'Employee transfer',
            self::MANAGER_DEPARTURE => 'Manager departure',
            self::ADMINISTRATIVE_CORRECTION => 'Administrative correction',
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

    public static function labelFor(?string $value): ?string
    {
        return $value === null ? null : (self::tryFrom($value)?->label() ?? $value);
    }
}
