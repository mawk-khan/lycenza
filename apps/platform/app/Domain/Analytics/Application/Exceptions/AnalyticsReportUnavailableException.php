<?php

namespace App\Domain\Analytics\Application\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * The fail-closed outcome of AnalyticsReadGate: the report is refused
 * BEFORE any source data is read. Rendered by the framework as HTTP 503
 * -- the report is unavailable in this deployment's configuration, not
 * a permission problem (a 403 stays reserved for missing
 * `analytics.view`).
 */
class AnalyticsReportUnavailableException extends HttpException
{
    public static function personCohortPolicyNotConfigured(string $readModelKey): self
    {
        return new self(503, "Analytics report [{$readModelKey}] counts people and is unavailable: no minimum person-cohort size has been approved.");
    }

    public static function notRegistered(string $readModelKey): self
    {
        return new self(503, "Analytics report [{$readModelKey}] is not registered.");
    }
}
