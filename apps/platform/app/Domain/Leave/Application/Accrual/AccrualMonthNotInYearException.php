<?php

namespace App\Domain\Leave\Application\Accrual;

use InvalidArgumentException;

/** HR.R1-C32 (WO-9): the month asked about is not a month of the chosen Leave Year. */
final class AccrualMonthNotInYearException extends InvalidArgumentException {}
