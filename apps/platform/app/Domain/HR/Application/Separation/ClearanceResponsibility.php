<?php

namespace App\Domain\HR\Application\Separation;

/**
 * HR.12: who may clear a School-configured ADMINISTRATIVE clearance item --
 * a closed list mapped to an existing capability in code
 * (`separation_clearance_requirements_responsibility_check`). A School picks
 * the responsibility; it never writes an authorization expression. Domain
 * obligations (Library, Finance, Inventory, Timetable, Transport, Leave,
 * School access) are never configurable items: they are read from their
 * owners (SeparationObligations), never attested here.
 */
enum ClearanceResponsibility: string
{
    case HR = 'hr';
    case SCHOOL_ADMINISTRATION = 'school_administration';

    public function label(): string
    {
        return match ($this) {
            self::HR => 'HR',
            self::SCHOOL_ADMINISTRATION => 'School administration',
        };
    }

    /** The whole-School capability that clears an item of this responsibility. */
    public function capability(): string
    {
        return match ($this) {
            self::HR => SeparationCapabilities::MANAGE,
            self::SCHOOL_ADMINISTRATION => 'school.settings.manage',
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
}
