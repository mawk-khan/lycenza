<?php

namespace App\Domain\HR\Application\Workforce;

/**
 * HR.1A: why a placement changes (`employee_placement_changes_reason_check`).
 * A closed list chosen by a person for every dated change; `initial_placement`
 * is recorded by the services for an employment's first primary assignment.
 * No free text (no performance, health or disciplinary narrative).
 */
enum PlacementChangeReason: string
{
    case STAFFING_REQUIREMENT = 'staffing_requirement';
    case EMPLOYEE_REQUEST = 'employee_request';
    case CAREER_PROGRESSION = 'career_progression';
    case RESTRUCTURING = 'restructuring';
    case ADMINISTRATIVE_CORRECTION = 'administrative_correction';

    public function label(): string
    {
        return match ($this) {
            self::STAFFING_REQUIREMENT => 'Staffing requirement',
            self::EMPLOYEE_REQUEST => 'Employee request',
            self::CAREER_PROGRESSION => 'Career progression',
            self::RESTRUCTURING => 'Restructuring',
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

    public static function labelFor(string $value): string
    {
        return $value === 'initial_placement' ? 'Initial placement' : (self::tryFrom($value)?->label() ?? $value);
    }
}
