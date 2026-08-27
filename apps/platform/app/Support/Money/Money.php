<?php

declare(strict_types=1);

namespace App\Support\Money;

use App\Support\Money\Exceptions\InvalidMoneyException;
use JsonSerializable;

/**
 * The first-party Money value object committed by ADR 0030 /
 * docs/modules/FINANCE.md ("Application representation") --
 * `ARCHITECTURE.md` §10 rule 1 names this exact pair of options
 * ("PHP's bcmath/a dedicated Money value object"); this class
 * satisfies both halves with zero new Composer dependencies (bcmath
 * is a PHP core extension already compiled into the platform image).
 *
 * Scope boundary (0G.1 vs 0G.2): 0G.1 deliberately limited this class
 * to SAFE REPRESENTATION AND VALIDATION -- exact decimal-string
 * amount, explicit currency, immutable, never backed by float, no
 * arithmetic. 0G.2 adds exactly the one operation
 * App\Domain\Finance\Application\LedgerService actually needs --
 * add(), for summing a proposed posting's debit/credit lines to prove
 * they balance before ever reaching PostgreSQL. No subtract()/
 * multiply()/divide() exist -- LedgerService's reversal logic needs
 * only negated() (0G.1) to build inverse lines from an original's
 * amounts, never real subtraction. PostgreSQL's `NUMERIC` columns and
 * the deferred balance-check constraint trigger remain the
 * authoritative arithmetic regardless of this Application-layer
 * pre-check.
 */
final class Money implements JsonSerializable
{
    /**
     * Deliberately strict: no leading zeros (except a bare "0" or
     * "0.xxx"), no leading "+", no scientific notation (the character
     * class excludes e/E entirely), no whitespace. This is a format
     * safety property, not an arbitrary-precision limit -- any number
     * of fractional digits is accepted; PostgreSQL `NUMERIC(14,2)`
     * (see the ledger_accounts/journal_lines migrations) is what
     * actually bounds precision/scale for stored ledger amounts.
     */
    private const AMOUNT_PATTERN = '/^-?(0|[1-9]\d*)(\.\d+)?$/';

    private const CURRENCY_PATTERN = '/^[A-Z]{3}$/';

    private function __construct(
        private readonly string $amount,
        private readonly string $currency,
    ) {}

    /**
     * The only way to construct a Money instance. Deliberately typed
     * `mixed`, NOT `string` -- PHP's scalar type coercion (float ->
     * string) is governed by the CALLING file's own `strict_types`
     * declaration, not this file's; a caller without `declare(strict_types=1)`
     * would silently have a `float` argument coerced to a string
     * BEFORE a `string $amount` parameter type ever got a chance to
     * reject it, no matter what this file declares. Typing `mixed` and
     * checking `is_string()` explicitly is the only caller-independent
     * way to guarantee "float input not accepted" (proven by
     * `MoneyTest::float_input_is_rejected` calling this from a file
     * without `strict_types`).
     */
    public static function of(mixed $amount, mixed $currency): self
    {
        if (! is_string($amount)) {
            throw new InvalidMoneyException(
                'Invalid Money amount: must be a string, got '.get_debug_type($amount).
                ' (float is never accepted -- pass an exact decimal string).'
            );
        }

        if (! is_string($currency)) {
            throw new InvalidMoneyException('Invalid Money currency: must be a string, got '.get_debug_type($currency).'.');
        }

        if (! preg_match(self::AMOUNT_PATTERN, $amount)) {
            throw new InvalidMoneyException(
                "Invalid Money amount '{$amount}': must be a plain decimal string ".
                '(no leading zeros, no leading +, no scientific notation, no whitespace).'
            );
        }

        if (! preg_match(self::CURRENCY_PATTERN, $currency)) {
            throw new InvalidMoneyException(
                "Invalid Money currency '{$currency}': must be a three-letter uppercase ISO 4217-shaped code."
            );
        }

        return new self($amount, $currency);
    }

    /**
     * The exact decimal string as validated -- never reformatted,
     * never rounded, never routed through a float. This is what any
     * caller must persist/transmit to preserve precision exactly.
     */
    public function amount(): string
    {
        return $this->amount;
    }

    public function currency(): string
    {
        return $this->currency;
    }

    /**
     * Value equality (via bcmath, not string equality) -- "1" and
     * "1.0" are the same Money, but only when the currency also
     * matches. A high fixed comparison scale is used because bcmath's
     * bccomp() requires an explicit scale to compare fractional
     * digits correctly; it is not a precision limit on stored amounts.
     */
    public function equals(self $other): bool
    {
        return $this->currency === $other->currency
            && bccomp($this->amount, $other->amount, 50) === 0;
    }

    public function isZero(): bool
    {
        return bccomp($this->amount, '0', 50) === 0;
    }

    public function isPositive(): bool
    {
        return bccomp($this->amount, '0', 50) === 1;
    }

    public function isNegative(): bool
    {
        return bccomp($this->amount, '0', 50) === -1;
    }

    /**
     * Sign-only manipulation (string-level, not bcmath arithmetic) --
     * safe to include in 0G.1's representation-only scope because it
     * performs no addition/subtraction. Useful to 0G.2 when
     * constructing a reversing entry's lines from an original entry's
     * lines (ADR 0030).
     */
    public function negated(): self
    {
        if ($this->isZero()) {
            return $this;
        }

        return new self(
            str_starts_with($this->amount, '-') ? substr($this->amount, 1) : '-'.$this->amount,
            $this->currency,
        );
    }

    /**
     * Exact addition (bcmath, never float). Requires matching
     * currencies -- Money never silently adds across currencies, even
     * though Phase 0G is INR-only today; that database-level scope
     * restriction (docs/modules/FINANCE.md "Currency scope") is a
     * separate defense from this Application-layer value object's own
     * semantic safety, and this class must stay correct independent of
     * it. Uses the larger of the two operands' own natural decimal
     * scale (never a fixed scale like 2 or a lossy fixed scale like
     * 50) so the result is exact and canonical -- no rounding, no
     * trailing-zero padding beyond what the inputs actually justify.
     */
    public function add(self $other): self
    {
        if ($this->currency !== $other->currency) {
            throw new InvalidMoneyException(
                "Cannot add Money in different currencies ('{$this->currency}' and '{$other->currency}')."
            );
        }

        $scale = max($this->decimalPlaces(), $other->decimalPlaces());

        return new self(bcadd($this->amount, $other->amount, $scale), $this->currency);
    }

    private function decimalPlaces(): int
    {
        $pos = strpos($this->amount, '.');

        return $pos === false ? 0 : strlen($this->amount) - $pos - 1;
    }

    /**
     * @return array{amount: string, currency: string}
     */
    public function jsonSerialize(): array
    {
        return ['amount' => $this->amount, 'currency' => $this->currency];
    }

    public function __toString(): string
    {
        return "{$this->amount} {$this->currency}";
    }
}
