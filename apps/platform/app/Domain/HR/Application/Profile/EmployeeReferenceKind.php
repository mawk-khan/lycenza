<?php

namespace App\Domain\HR\Application\Profile;

/**
 * HR.2A (D1-03): the closed kinds of an Employee's external reference code
 * (`employee_reference_codes_kind_check`). Closed because each kind carries
 * a fixed contract -- School-unique per kind, never reused, one live code per
 * Employee, immutable value -- that imports and integrations rely on; a
 * School-defined kind could not. A biometric code is NOT a kind: it is a
 * StaffAttendance identity behind HRX-L2.
 */
enum EmployeeReferenceKind: string
{
    case STAFF_REFERENCE = 'staff_reference';
    case ERP = 'erp';
    case LEGACY = 'legacy';

    public function label(): string
    {
        return match ($this) {
            self::STAFF_REFERENCE => 'Staff reference code',
            self::ERP => 'ERP code',
            self::LEGACY => 'Legacy code',
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
}
