<?php

namespace App\Domain\Students\Application\Exceptions;

/**
 * A rollover plan's configuration (mappings, item decisions/overrides)
 * and dry-run validation are only meaningful while the plan is still
 * `draft` or `validated` -- the accepted architecture's plan lifecycle
 * (docs/modules/STUDENT-ENROLLMENT.md, "Plan lifecycle (DECIDED,
 * minimal)") makes an `executing` plan's configuration immutable, and
 * a `completed`/`completed_with_errors`/`cancelled` plan is terminal.
 * Thrown by both EnrollmentRolloverPlanService's configuration-mutation
 * methods and EnrollmentRolloverDryRunService::run() -- they share the
 * identical allowed-status set.
 */
class RolloverPlanNoLongerConfigurableException extends StudentException
{
    public function __construct()
    {
        parent::__construct(
            422,
            'ROLLOVER_PLAN_NO_LONGER_CONFIGURABLE',
            'This rollover plan can no longer be configured or (re)validated -- it is executing or has reached a terminal status.',
        );
    }
}
