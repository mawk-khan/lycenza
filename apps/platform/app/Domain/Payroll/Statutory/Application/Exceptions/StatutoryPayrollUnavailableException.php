<?php

namespace App\Domain\Payroll\Statutory\Application\Exceptions;

/**
 * HR-PG1/PG2: statutory Payroll is not available in this environment
 * (`StatutoryPayrollAvailability`). A fixed code and message: it never names a
 * School, Employee, Payroll run, identifier or record, so it discloses nothing
 * about whether any exists.
 */
class StatutoryPayrollUnavailableException extends StatutoryPayrollException
{
    public const CODE = 'STATUTORY_PAYROLL_UNAVAILABLE';

    public const MESSAGE = 'Statutory Payroll is not available in this environment.';

    public function __construct()
    {
        parent::__construct(403, self::CODE, self::MESSAGE);
    }

    /** Merged into the /api error envelope: a refusal is never cached. @return array<string, string> */
    public function getHeaders(): array
    {
        return ['Cache-Control' => 'private, no-store'];
    }
}
