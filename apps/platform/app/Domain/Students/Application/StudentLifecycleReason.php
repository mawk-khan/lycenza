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
    /**
     * SIS 11.1: the human label of every code -- the same text as
     * resources/js/lifecycleReasons.ts (StudentReportRegistryTest keeps the
     * two equal), so a server-rendered report never shows a raw code.
     */
    public const array LABELS = [
        'left_school' => 'Left the School',
        'transferred_out' => 'Transferred to another school',
        'completed_studies' => 'Completed studies',
        'duplicate_record' => 'Duplicate record',
        'entered_in_error' => 'Entered in error',
        'returned_to_school' => 'Returned to the School',
        'deactivated_in_error' => 'Deactivated in error',
        'guardian_request' => 'Guardian request',
        'did_not_join' => 'Did not join',
        'duplicate_placement' => 'Duplicate placement',
    ];

    public static function require(string $action, string $reasonCode): void
    {
        if (! in_array($reasonCode, self::CODES[$action], true)) {
            throw new StudentLifecycleReasonException($action);
        }
    }
}
