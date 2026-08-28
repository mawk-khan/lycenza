<?php

namespace App\Domain\HR\Application\Exceptions;

/**
 * Thrown by App\Domain\HR\Application\EmploymentService::end() when the
 * target EmploymentRecord already has a non-null `ends_on` (checkpoint
 * 8A.13, sections 21/22/36: repeated separation must be a safe,
 * deterministic rejection, never a silent date/status rewrite and
 * never a second, conflicting end date under concurrency). The check
 * runs against a `lockForUpdate()`-locked read of the row inside the
 * same transaction as the write, so two genuinely concurrent `end()`
 * calls for the same EmploymentRecord always serialize: the second
 * transaction blocks until the first commits, then re-reads the
 * now-ended row and throws this instead of racing to overwrite it.
 *
 * Maps to 409 Conflict, not 422 -- this is a state-conflict (the
 * resource has already transitioned), the same category
 * `ConcurrentActivationConflictException` uses in Academic Structure.
 */
class EmploymentAlreadyEndedException extends HrException
{
    public function __construct(
        public readonly string $employmentRecordId,
        public readonly string $existingEndsOn,
    ) {
        parent::__construct(409, 'HR_EMPLOYMENT_ALREADY_ENDED', "EmploymentRecord {$employmentRecordId} was already ended on {$existingEndsOn}.");
    }
}
