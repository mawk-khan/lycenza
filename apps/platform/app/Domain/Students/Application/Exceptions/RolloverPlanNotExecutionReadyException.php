<?php

namespace App\Domain\Students\Application\Exceptions;

/**
 * Phase 1B.7C: thrown by EnrollmentRolloverItemExecutionService::execute()
 * unless BOTH `status === 'validated'` AND `validated_configuration_version
 * === configuration_version` hold -- the same
 * `EnrollmentRolloverPlan::isValidatedForCurrentConfiguration()` staleness
 * signal the dry-run engine relies on. `status` alone is NOT sufficient:
 * `EnrollmentRolloverPlanService::upsertMapping()`/`setItemDecision()`
 * bump `configuration_version` without touching `status`, so a plan can
 * read `status = 'validated'` while no longer describing its current
 * configuration. Execution never silently re-runs dry-run to recover
 * from this -- the caller must explicitly revalidate first.
 */
class RolloverPlanNotExecutionReadyException extends StudentException
{
    public function __construct()
    {
        parent::__construct(
            422,
            'ROLLOVER_PLAN_NOT_EXECUTION_READY',
            'This rollover plan is not currently validated for its present configuration. Re-run validation before executing any Item.',
        );
    }
}
