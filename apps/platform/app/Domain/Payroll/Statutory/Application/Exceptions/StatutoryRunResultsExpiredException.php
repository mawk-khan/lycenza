<?php

namespace App\Domain\Payroll\Statutory\Application\Exceptions;

/**
 * E21.3F (E21-D9) -- raised when a statutory export is requested for a run
 * whose detail payroll retention has already reduced (`results_expired_at`):
 * an export built from the remaining results would silently be a partial
 * filing. The run's ledger posting and totals are unchanged; nothing is
 * reconstructed from them.
 */
class StatutoryRunResultsExpiredException extends StatutoryPayrollException
{
    public function __construct(string $runId)
    {
        parent::__construct(409, 'PAYROLL_RESULTS_EXPIRED', "Payroll run '{$runId}' no longer holds every Employee's results (retention policy); a statutory export would be incomplete.");
    }
}
