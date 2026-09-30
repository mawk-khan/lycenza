<?php

namespace App\Domain\HR\Application\Exceptions;

/**
 * TCH.1 (ADR 0063 section 4): App\Domain\HR\Application\ActingEmployeeResolver
 * could not establish the full identity chain for this User at this
 * School. There is no partial identity and no fallback.
 *
 * `$reason` (one of the constants below) is for the calling service and
 * for tests only. The message and code are the same for every reason, so a
 * future HTTP consumer never discloses which link of the chain failed;
 * such a consumer may translate this into its own non-disclosing answer
 * (ADR 0063 section 18).
 */
class ActingEmployeeUnavailableException extends HrException
{
    public const string USER_UNAVAILABLE = 'user_unavailable';

    public const string SCHOOL_NOT_OPERATIONAL = 'school_not_operational';

    public const string MEMBERSHIP_NOT_ACTIVE = 'membership_not_active';

    public const string NOT_LINKED = 'not_linked';

    public const string EMPLOYEE_NOT_ACTIVE = 'employee_not_active';

    public const string NO_ELIGIBLE_EMPLOYMENT = 'no_eligible_employment';

    public const string AMBIGUOUS_EMPLOYMENT = 'ambiguous_employment';

    public function __construct(public readonly string $reason)
    {
        parent::__construct(403, 'HR_ACTING_EMPLOYEE_UNAVAILABLE', 'You are not an eligible Employee of this School.');
    }
}
