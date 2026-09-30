<?php

namespace App\Domain\Finance\Http;

use App\Domain\Finance\Application\Exceptions\InvalidLedgerAccountException;
use App\Support\Http\ChecksCaseInsensitiveUniqueCode;
use Illuminate\Validation\ValidationException;

/**
 * Finance-owned HTTP glue for the ledger-account controllers (API and
 * browser).
 *
 * - A field-level ledger-account rejection becomes an ordinary 422
 *   validation error on that field, so API and browser clients see one
 *   error shape. Every other domain exception keeps its own status (404,
 *   409) through the global envelope.
 * - Code uniqueness comes from the neutral Support trait.
 *
 * Finance never depends on Fees (DOMAIN-MAP); this replaces the FEE.1 use of
 * the Fees-owned `TranslatesFeeSetupErrors` here (closure remediation).
 */
trait TranslatesLedgerAccountErrors
{
    use ChecksCaseInsensitiveUniqueCode;

    /**
     * @template T
     *
     * @param  callable(): T  $operation
     * @return T
     */
    protected function translatingLedgerAccountErrors(callable $operation): mixed
    {
        try {
            return $operation();
        } catch (InvalidLedgerAccountException $e) {
            throw ValidationException::withMessages([$e->field() => [$e->getMessage()]]);
        }
    }
}
