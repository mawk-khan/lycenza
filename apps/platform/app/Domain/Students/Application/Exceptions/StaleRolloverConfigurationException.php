<?php

namespace App\Domain\Students\Application\Exceptions;

/**
 * `EnrollmentRolloverDryRunService::run()` captures the plan's
 * `configuration_version` before running its (potentially expensive)
 * calculation, then re-verifies it under `lockForUpdate()` immediately
 * before persisting results (docs/modules/STUDENT-ENROLLMENT.md,
 * "Atomicity, idempotency, resumability"). If a configuration edit
 * landed in that window, the computed results no longer describe the
 * plan's CURRENT configuration -- persisting them anyway (even just
 * the Item validation metadata, let alone marking the Plan `validated`)
 * would silently claim validation belongs to a newer configuration
 * than was actually evaluated. The whole persistence transaction rolls
 * back; nothing about this dry-run attempt is recorded. The caller
 * (or a future scheduled retry) simply runs dry-run again.
 */
class StaleRolloverConfigurationException extends StudentException
{
    public function __construct()
    {
        parent::__construct(
            409,
            'STALE_ROLLOVER_CONFIGURATION',
            'This rollover plan\'s configuration changed while validation was running. Re-run validation.',
        );
    }
}
