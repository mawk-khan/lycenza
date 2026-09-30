<?php

namespace App\Support\Http;

use App\Models\School;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * Shared, domain-neutral HTTP validation glue: a School-scoped code is
 * checked case-insensitively (`upper(code)`, the same expression as the
 * unique indexes) so a case-variant duplicate is a clean 422 before it
 * reaches the database (CLAUDE.md rule 74).
 *
 * Lives in Support (not in any one module) so Finance and Fees controllers
 * can both use it without a Finance -> Fees dependency (DOMAIN-MAP).
 */
trait ChecksCaseInsensitiveUniqueCode
{
    /**
     * @param  array<string, string>  $scope  extra equality columns, e.g. ['academic_year_id' => $id]
     */
    protected function caseInsensitiveUniqueCode(string $table, School $school, array $scope = []): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($table, $school, $scope): void {
            if (! is_string($value)) {
                return;
            }

            $taken = DB::table($table)
                ->where('school_id', $school->id)
                ->where($scope)
                ->whereRaw('upper(code) = ?', [strtoupper(trim($value))])
                ->exists();

            if ($taken) {
                $fail('The code has already been taken.');
            }
        };
    }
}
