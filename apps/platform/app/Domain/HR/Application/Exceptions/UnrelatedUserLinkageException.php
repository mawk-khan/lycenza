<?php

namespace App\Domain\HR\Application\Exceptions;

/**
 * Thrown when an Employee cannot be linked to the requested User
 * (EmployeeService::create() with a `user_id`, or
 * EmployeeService::linkUser()). `App\Models\User` is a central/global
 * identity (docs/modules/HR.md 2.1) with no inherent relationship to any
 * School, so `employees.user_id` accepting an arbitrary valid User id
 * would let HR staff link an Employee record to a completely unrelated
 * person's account.
 *
 * TCH.1 (ADR 0063 section 6) hardens the check: since the link is now an
 * authorization input (ActingEmployee), the User must be enabled AND hold
 * an ACTIVE membership at the target School -- an `invited` or `suspended`
 * membership is no longer enough -- and the check runs inside the link's
 * own transaction with the membership and User rows locked FOR SHARE.
 * It still runs at link time only: a membership suspended afterwards does
 * not unwind the stored link (docs/modules/HR.md 2.6); it makes
 * ActingEmployee resolution fail instead.
 *
 * `$reason` is internal (user_unavailable|no_membership|membership_not_active);
 * the message is deliberately one generic sentence for every reason, so
 * the response never tells a caller whether an account exists, is
 * disabled, or belongs to another School.
 */
class UnrelatedUserLinkageException extends HrException
{
    public const string USER_UNAVAILABLE = 'user_unavailable';

    public const string NO_MEMBERSHIP = 'no_membership';

    public const string MEMBERSHIP_NOT_ACTIVE = 'membership_not_active';

    public function __construct(
        public readonly string $userId,
        public readonly string $schoolId,
        public readonly string $reason = self::NO_MEMBERSHIP,
    ) {
        parent::__construct(422, 'HR_UNRELATED_USER_LINKAGE', 'The specified User cannot be linked as an Employee: it has no active membership at this School.');
    }
}
