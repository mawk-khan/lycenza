<?php

namespace App\Support\Money\Exceptions;

use InvalidArgumentException;

/**
 * Thrown by Money::of() for any input that cannot safely represent an
 * exact monetary amount -- a non-decimal-string amount (including any
 * float), scientific notation, or a currency code that isn't a
 * three-letter uppercase ISO 4217-shaped string. See
 * docs/modules/FINANCE.md "Application representation".
 */
class InvalidMoneyException extends InvalidArgumentException {}
