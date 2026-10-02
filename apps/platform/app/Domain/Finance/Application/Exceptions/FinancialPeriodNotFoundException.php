<?php

namespace App\Domain\Finance\Application\Exceptions;

/** E21.3A: no such financial period in this School (also for another School's id). */
class FinancialPeriodNotFoundException extends FinanceException
{
    public function __construct(string $periodId)
    {
        parent::__construct(404, 'FINANCIAL_PERIOD_NOT_FOUND', "No financial period with id '{$periodId}' was found in this School.");
    }
}
