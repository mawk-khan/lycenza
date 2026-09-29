<?php

namespace App\Domain\Fees\Http;

use App\Domain\Fees\Application\Exceptions\InvalidFeeHeadException;
use App\Domain\Fees\Application\Exceptions\InvalidFeeOptionalSelectionException;
use App\Domain\Fees\Application\Exceptions\InvalidFeeStructureException;
use App\Domain\Finance\Application\Exceptions\InvalidLedgerAccountException;
use App\Models\School;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * FEE.1: shared HTTP glue for the fee setup and ledger-account controllers.
 *
 * - A field-level domain rejection (Invalid*Exception) becomes an ordinary
 *   422 validation error on that field, so API and browser clients see one
 *   error shape. Every other domain exception keeps its own status (404,
 *   409) through the global envelope.
 * - Code uniqueness is checked case-insensitively (`upper(code)`, the same
 *   expression as the unique indexes) so a case-variant duplicate is a
 *   clean 422 before it reaches the database (CLAUDE.md rule 74).
 */
trait TranslatesFeeSetupErrors
{
    /**
     * @template T
     *
     * @param  callable(): T  $operation
     * @return T
     */
    protected function translatingFeeSetupErrors(callable $operation): mixed
    {
        try {
            return $operation();
        } catch (InvalidFeeHeadException|InvalidFeeStructureException|InvalidFeeOptionalSelectionException|InvalidLedgerAccountException $e) {
            throw ValidationException::withMessages([$e->field() => [$e->getMessage()]]);
        }
    }

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
