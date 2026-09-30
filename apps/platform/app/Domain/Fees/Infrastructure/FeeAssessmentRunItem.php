<?php

namespace App\Domain\Fees\Infrastructure;

use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * FEE.2 (ADR 0062 §9.2): one Student x structure line of a run -- its
 * preview result (closed reason catalogue) and, for a `ready` item, its
 * execution state. Phase immutability is database-enforced
 * (`fees_guard_fee_assessment_run_item`).
 *
 * @property string $id
 * @property string $school_id
 * @property string $fee_assessment_run_id
 * @property string $student_id
 * @property string|null $student_enrollment_id
 * @property string|null $campus_id
 * @property string $grade_level_id
 * @property Carbon|null $enrollment_starts_on
 * @property string $fee_structure_line_id
 * @property string $fee_structure_installment_id
 * @property string $fee_head_id
 * @property string $billing_period_key
 * @property string $amount
 * @property string $currency
 * @property string $preview_result
 * @property string|null $reason
 * @property string|null $excluded_by_user_id
 * @property Carbon|null $excluded_at
 * @property string|null $execution_status
 * @property string|null $failure_reason
 * @property string|null $fee_assessment_id
 * @property Carbon|null $executed_at
 */
class FeeAssessmentRunItem extends Model
{
    use BelongsToSchool, GeneratesUuidV7;

    public const RESULT_READY = 'ready';

    public const RESULT_ALREADY_ASSESSED = 'already_assessed';

    public const RESULT_EXCLUDED = 'excluded';

    public const RESULT_BLOCKED = 'blocked';

    public const PREVIEW_RESULTS = [self::RESULT_READY, self::RESULT_ALREADY_ASSESSED, self::RESULT_EXCLUDED, self::RESULT_BLOCKED];

    // Excluded reasons (ADR 0062 §9.2 catalogue).
    public const REASON_OPTIONAL_NOT_SELECTED = 'optional_not_selected';

    public const REASON_PERIOD_BEFORE_ENROLLMENT = 'period_before_enrollment';

    public const REASON_ENROLLMENT_CANCELLED = 'enrollment_cancelled';

    public const REASON_STUDENT_INACTIVE = 'student_inactive';

    public const REASON_STAFF_EXCLUDED = 'staff_excluded';

    // Blocked reasons.
    public const REASON_NO_STRUCTURE = 'no_structure';

    public const REASON_HEAD_INACTIVE = 'head_inactive';

    public const REASON_ACCOUNT_INVALID = 'account_invalid';

    public const EXEC_PENDING = 'pending';

    public const EXEC_SUCCEEDED = 'succeeded';

    public const EXEC_SKIPPED = 'skipped_already_assessed';

    public const EXEC_FAILED = 'failed';

    // Execution failure reasons (closed catalogue).
    public const FAIL_STRUCTURE_NOT_ACTIVE = 'structure_not_active';

    public const FAIL_STRUCTURE_NOT_RESOLVED = 'structure_not_resolved';

    public const FAIL_ENROLLMENT_NOT_QUALIFYING = 'enrollment_not_qualifying';

    public const FAIL_STUDENT_INACTIVE = 'student_inactive';

    public const FAIL_OPTIONAL_NOT_SELECTED = 'optional_not_selected';

    public const FAIL_HEAD_INACTIVE = 'head_inactive';

    public const FAIL_ACCOUNT_INVALID = 'account_invalid';

    public const FAIL_ERROR = 'error';

    /** FEE.3 (G1): matching standing concessions exceed what remains of the charge; refused, never reduced. */
    public const FAIL_CONCESSION_EXCEEDS_OUTSTANDING = 'concession_exceeds_outstanding';

    /** FEE.3 (F2): a concession applies but the School's concession account is missing or invalid. */
    public const FAIL_CONCESSION_ACCOUNT_INVALID = 'concession_account_invalid';

    protected $table = 'fee_assessment_run_items';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'enrollment_starts_on' => 'date',
            'excluded_at' => 'datetime',
            'executed_at' => 'datetime',
        ];
    }
}
