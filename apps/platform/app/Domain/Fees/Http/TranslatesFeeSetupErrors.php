<?php

namespace App\Domain\Fees\Http;

use App\Domain\Fees\Application\Exceptions\InvalidFeeHeadException;
use App\Domain\Fees\Application\Exceptions\InvalidFeeOptionalSelectionException;
use App\Domain\Fees\Application\Exceptions\InvalidFeeStructureException;
use App\Domain\Finance\Application\Exceptions\InvalidLedgerAccountException;
use App\Support\Http\ChecksCaseInsensitiveUniqueCode;
use Illuminate\Validation\ValidationException;

/**
 * FEE.1: HTTP glue for the fee setup controllers (API and browser).
 *
 * - A field-level domain rejection (Invalid*Exception) becomes an ordinary
 *   422 validation error on that field, so API and browser clients see one
 *   error shape. Every other domain exception keeps its own status (404,
 *   409) through the global envelope.
 * - Code uniqueness comes from the neutral Support trait (CLAUDE.md rule
 *   74). The ledger-account controllers use Finance's own
 *   `TranslatesLedgerAccountErrors`, never this trait: Finance does not
 *   depend on Fees (DOMAIN-MAP).
 */
trait TranslatesFeeSetupErrors
{
    use ChecksCaseInsensitiveUniqueCode;

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
}
