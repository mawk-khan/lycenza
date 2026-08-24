<?php

namespace App\Domain\Students\Application\Exceptions;

/**
 * Phase 1B.7C: defense in depth for EnrollmentRolloverItemExecutionService::execute()
 * -- an Item whose persisted `validation_result` is not `ready`/
 * `already_enrolled` (and is not the separately-handled `excluded`
 * skip path) must never produce a target Enrollment. In practice this
 * should be unreachable once the Plan-validity gate has already passed
 * (a `validated` Plan guarantees zero `review`/`blocked` Items exist),
 * but dry-run decisions are never silently overridden by execution --
 * see this checkpoint's brief, section 44.
 */
class RolloverItemNotExecutableException extends StudentException
{
    public function __construct(?string $validationResult)
    {
        parent::__construct(
            422,
            'ROLLOVER_ITEM_NOT_EXECUTABLE',
            "This rollover Item cannot be executed -- its validation result is '".($validationResult ?? 'none')."', not 'ready' or 'already_enrolled'.",
        );
    }
}
