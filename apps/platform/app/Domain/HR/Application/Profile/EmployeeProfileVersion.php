<?php

namespace App\Domain\HR\Application\Profile;

use App\Domain\HR\Infrastructure\Employee;
use App\Domain\HR\Infrastructure\EmployeePersonalDetail;

/**
 * HR.2A (roadmap "stale-form token"): the optimistic-concurrency tokens of an
 * Employee's two editable profile records -- the core identity
 * (EmployeeService::update()) and the personal details
 * (EmployeePersonalDetailService::setDetails()). The form carries the token
 * it was rendered with; the save recomputes it under the row lock and
 * refuses a mismatch (EmployeeProfileChangedException), so an old form never
 * overwrites a newer change (the SIS StudentProfileVersion pattern, rule 127).
 *
 * A digest of the written values, not `updated_at` (second precision). KEYED
 * (HMAC with a purpose key derived from the application key): the personal
 * token covers the date of birth and personal contacts, which an identity
 * editor may not read; an unkeyed hash of a date could be brute-forced.
 */
final class EmployeeProfileVersion
{
    public static function identity(Employee $employee): string
    {
        return self::digest('identity', [
            $employee->id,
            $employee->employee_number,
            $employee->full_name,
            $employee->work_email ?? '',
            $employee->work_phone ?? '',
        ]);
    }

    public static function personal(string $employeeId, ?EmployeePersonalDetail $detail): string
    {
        return self::digest('personal', [
            $employeeId,
            $detail?->date_of_birth?->toDateString() ?? '',
            $detail->nationality ?? '',
            $detail->marital_status ?? '',
            $detail->preferred_language ?? '',
            $detail->personal_email ?? '',
            $detail->personal_phone ?? '',
            $detail->alternate_phone ?? '',
        ]);
    }

    public static function matches(string $actual, string $expected): bool
    {
        return hash_equals($actual, $expected);
    }

    /** @param  list<string>  $parts */
    private static function digest(string $kind, array $parts): string
    {
        return hash_hmac('sha256', implode("\x1f", [$kind, ...$parts]), hash('sha256', 'lycenza:employee-profile-version:v1|'.(string) config('app.key'), true));
    }
}
