<?php

namespace App\Domain\HR\Application;

use App\Domain\HR\Infrastructure\Employee;
use App\Models\School;
use App\Support\Tenancy\TenantContext;

/**
 * Phase 8A.12 -- School-scoped, read-only, deterministic duplicate
 * detection for Employee import. NO fuzzy/ML matching (checkpoint
 * brief section 12/34) -- exactly two signals, in order:
 *
 * 1. **Exact**: the row's `user_id` already links an existing Employee
 *    in this School. `employees` already enforces `unique(school_id,
 *    user_id)` at the database level (8A.1) -- this is a read-side
 *    check of that same authoritative fact, not a new identity rule.
 * 2. **Potential**: the row's `full_name`, case-folded and
 *    whitespace-collapsed (never diacritic-stripped, never
 *    reordered -- checkpoint brief section 17), matches an existing
 *    Employee's `full_name` under the identical normalization. This is
 *    a WARNING signal only -- two genuinely different people sharing a
 *    name is not prohibited (section 39/40: no `UNIQUE(school_id,
 *    full_name)` constraint exists or is added).
 *
 * Archived Employees are included in both checks -- an archived
 * Employee is still a real historical record; a `user_id` or name
 * match against one is exactly the "this may be a rehire, not a new
 * hire" case `EmployeeImportService` must surface rather than silently
 * create a second Employee for (checkpoint brief section 31).
 */
class EmployeeDuplicateDetector
{
    public function __construct(private readonly TenantContext $context) {}

    public function detect(School $school, EmployeeImportRow $row): EmployeeDuplicateDetectionResult
    {
        return $this->context->withSchool($school, function () use ($school, $row) {
            if ($row->userId !== null) {
                $existing = Employee::query()
                    ->where('school_id', $school->id)
                    ->where('user_id', $row->userId)
                    ->first();

                if ($existing !== null) {
                    return EmployeeDuplicateDetectionResult::exact($existing->id, $existing->employee_number);
                }
            }

            $normalized = self::normalizeName($row->fullName);

            $candidateCount = Employee::query()
                ->where('school_id', $school->id)
                ->whereRaw("lower(regexp_replace(trim(both from full_name), '\\s+', ' ', 'g')) = ?", [$normalized])
                ->count();

            if ($candidateCount > 0) {
                // The user_id lookup above and this name lookup are two
                // separate READ COMMITTED statements: a concurrent import
                // for the SAME linked User can commit between them, so the
                // name match may be that very Employee. Re-check the exact
                // key before reporting a merely "potential" duplicate.
                if ($row->userId !== null) {
                    $existing = Employee::query()
                        ->where('school_id', $school->id)
                        ->where('user_id', $row->userId)
                        ->first();

                    if ($existing !== null) {
                        return EmployeeDuplicateDetectionResult::exact($existing->id, $existing->employee_number);
                    }
                }

                return EmployeeDuplicateDetectionResult::potential($candidateCount);
            }

            return EmployeeDuplicateDetectionResult::none();
        });
    }

    /**
     * Safe candidate-matching normalization ONLY -- never used to
     * mutate a stored `full_name`, never used as an identity/uniqueness
     * key. Trim + collapse internal whitespace + case-fold (multibyte-
     * safe) -- deliberately does NOT strip diacritics, reorder tokens,
     * or touch non-ASCII scripts (checkpoint brief sections 17/51:
     * "do not corrupt diacritics/Arabic/Indic names").
     */
    public static function normalizeName(string $fullName): string
    {
        return mb_strtolower(preg_replace('/\s+/u', ' ', trim($fullName)));
    }
}
