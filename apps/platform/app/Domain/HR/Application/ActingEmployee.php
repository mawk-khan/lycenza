<?php

namespace App\Domain\HR\Application;

/**
 * TCH.1 (ADR 0063 section 4): a VERIFIED staff identity -- "this User,
 * at this School, on this School-local date, is this eligible Employee".
 * Produced only by ActingEmployeeResolver, never constructed from request
 * data, and never persisted: it is resolved state, not an aggregate.
 *
 * It identifies; it authorizes nothing. Holding one grants no capability
 * and no access to any resource -- a future owned-resource check combines
 * it with a capability AND an ownership fact (ADR 0063 section 11).
 *
 * Carries identifiers only: no profile, contact or HR detail is copied
 * here. A consumer that needs more reads it through the owning HR service.
 */
final readonly class ActingEmployee
{
    public function __construct(
        public string $schoolId,
        public string $userId,
        public string $employeeId,
        public string $employmentRecordId,
        public string $asOf,
    ) {}
}
