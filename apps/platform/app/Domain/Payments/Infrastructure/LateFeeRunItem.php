<?php

namespace App\Domain\Payments\Infrastructure;

use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * FEE.5 (ADR 0062 §16.2): one candidate source charge of a late-fee run,
 * with preview provenance (outstanding, calculated, cap, final) and the
 * execution-time facts actually used. Closed reason catalogues; phase
 * immutability by trigger (`payments_guard_late_fee_run_item`).
 *
 * @property string $id
 * @property string $school_id
 * @property string $late_fee_run_id
 * @property string $source_charge_id
 * @property string $student_id
 * @property string $academic_year_id
 * @property string $fee_head_id
 * @property string $billing_period_key
 * @property Carbon $due_date
 * @property Carbon $final_grace_date
 * @property string $outstanding_amount
 * @property string $calculated_amount
 * @property string $final_amount
 * @property bool $cap_applied
 * @property string $currency
 * @property string $preview_result
 * @property string|null $reason
 * @property string|null $execution_status
 * @property string|null $failure_reason
 * @property string|null $executed_outstanding_amount
 * @property string|null $executed_amount
 * @property bool|null $executed_cap_applied
 * @property string|null $late_fee_assessment_id
 * @property Carbon|null $executed_at
 */
class LateFeeRunItem extends Model
{
    use BelongsToSchool, GeneratesUuidV7;

    public const RESULT_READY = 'ready';

    public const RESULT_NOT_ELIGIBLE = 'not_eligible';

    public const RESULT_ALREADY_ASSESSED = 'already_assessed';

    public const PREVIEW_RESULTS = [self::RESULT_READY, self::RESULT_NOT_ELIGIBLE, self::RESULT_ALREADY_ASSESSED];

    public const REASON_GRACE_NOT_ELAPSED = 'grace_not_elapsed';

    public const REASON_FULLY_SETTLED = 'fully_settled';

    public const REASON_ZERO_AMOUNT = 'zero_amount';

    public const REASON_ALREADY_ASSESSED = 'already_assessed';

    public const EXEC_PENDING = 'pending';

    public const EXEC_SUCCEEDED = 'succeeded';

    public const EXEC_SKIPPED = 'skipped_already_assessed';

    public const EXEC_FAILED = 'failed';

    public const EXECUTION_STATUSES = [self::EXEC_PENDING, self::EXEC_SUCCEEDED, self::EXEC_SKIPPED, self::EXEC_FAILED];

    public const FAIL_RULE_NOT_ACTIVE = 'rule_not_active';

    public const FAIL_SOURCE_NOT_ELIGIBLE = 'source_not_eligible';

    public const FAIL_GRACE_NOT_ELAPSED = 'grace_not_elapsed';

    public const FAIL_FULLY_SETTLED = 'fully_settled';

    public const FAIL_ZERO_AMOUNT = 'zero_amount';

    public const FAIL_ACCOUNT_INVALID = 'account_invalid';

    public const FAIL_ERROR = 'error';

    protected $table = 'late_fee_run_items';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'due_date' => 'date',
            'final_grace_date' => 'date',
            'cap_applied' => 'boolean',
            'executed_cap_applied' => 'boolean',
            'executed_at' => 'datetime',
        ];
    }
}
