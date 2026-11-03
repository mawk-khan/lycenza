<?php

namespace App\Domain\HR\Application\Workforce;

/**
 * HR.1A (S-12): the CLOSED employment-type catalogue, the one the OpenAPI
 * contract (`EmployeeProfileEmployment.employment_type`) and the importer
 * already declared, now enforced on every write path and in the database
 * (`employment_records_employment_type_check`, `employment_terms_employment_type_check`).
 */
enum EmploymentType: string
{
    case PERMANENT = 'permanent';
    case PROBATIONARY = 'probationary';
    case FIXED_TERM = 'fixed_term';
    case PART_TIME = 'part_time';
    case TEMPORARY = 'temporary';
    case CONTRACT = 'contract';
    case CONSULTANT = 'consultant';

    public function label(): string
    {
        return match ($this) {
            self::PERMANENT => 'Permanent',
            self::PROBATIONARY => 'Probationary',
            self::FIXED_TERM => 'Fixed term',
            self::PART_TIME => 'Part time',
            self::TEMPORARY => 'Temporary',
            self::CONTRACT => 'Contract',
            self::CONSULTANT => 'Consultant',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $t) => $t->value, self::cases());
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(fn (self $t) => ['value' => $t->value, 'label' => $t->label()], self::cases());
    }
}
