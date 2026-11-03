<?php

namespace App\Domain\HR\Application\Workforce;

/**
 * HR.1A: the business meaning of a placement change
 * (`employee_placement_changes_kind_check`). The four dated kinds are chosen
 * by a person and must match what actually changes:
 *
 *   transfer            the Campus changes (Department / Position may too)
 *   promotion           the Position changes, as an advancement (Campus /
 *                       Department may too)
 *   designation_change  the Position changes laterally (Campus / Department
 *                       may too)
 *   department_change   the Department changes; Campus and Position stay
 *
 * `initial_placement` (the first primary assignment of an employment) and
 * `primary_correction` (a re-designation of which assignment is primary) are
 * recorded by the services themselves.
 */
enum PlacementChangeKind: string
{
    case TRANSFER = 'transfer';
    case PROMOTION = 'promotion';
    case DESIGNATION_CHANGE = 'designation_change';
    case DEPARTMENT_CHANGE = 'department_change';

    public function label(): string
    {
        return match ($this) {
            self::TRANSFER => 'Transfer',
            self::PROMOTION => 'Promotion',
            self::DESIGNATION_CHANGE => 'Designation change',
            self::DEPARTMENT_CHANGE => 'Department change',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $k) => $k->value, self::cases());
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(fn (self $k) => ['value' => $k->value, 'label' => $k->label()], self::cases());
    }

    /** Every recorded value, the system ones included (display). */
    public static function labelFor(string $value): string
    {
        return match ($value) {
            'initial_placement' => 'Initial placement',
            'primary_correction' => 'Primary assignment correction',
            default => self::tryFrom($value)?->label() ?? $value,
        };
    }
}
