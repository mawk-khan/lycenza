<?php

namespace App\Domain\Students\Application;

use App\Domain\Students\Application\Exceptions\StudentLifecycleReasonException;

/**
 * ORG.8E (ADR 0076 §63, 8E-D1): the closed, code-owned reasons for each
 * high-impact Student lifecycle action. Mirrored by
 * `student_lifecycle_events_reason_check`; changing a list is a reviewed
 * release (both together), never School configuration. There is no `other`
 * and no free text.
 */
final class StudentLifecycleReason
{
    public const string DEACTIVATE = 'student_deactivated';

    public const string REACTIVATE = 'student_reactivated';

    public const string WITHDRAW_ENROLLMENT = 'enrollment_withdrawn';

    public const string CANCEL_ENROLLMENT = 'enrollment_cancelled';

    /** action => reason codes */
    public const array CODES = [
        self::DEACTIVATE => ['left_school', 'transferred_out', 'completed_studies', 'duplicate_record', 'entered_in_error'],
        self::REACTIVATE => ['returned_to_school', 'deactivated_in_error'],
        self::WITHDRAW_ENROLLMENT => ['left_school', 'transferred_out', 'guardian_request'],
        self::CANCEL_ENROLLMENT => ['did_not_join', 'duplicate_placement', 'entered_in_error'],
    ];

    /** @throws StudentLifecycleReasonException */
    public static function require(string $action, string $reasonCode): void
    {
        if (! in_array($reasonCode, self::CODES[$action], true)) {
            throw new StudentLifecycleReasonException($action);
        }
    }
}
