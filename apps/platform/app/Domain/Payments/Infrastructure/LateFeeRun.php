<?php

namespace App\Domain\Payments\Infrastructure;

use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * FEE.5 (ADR 0062 §16.2): one late-fee rule evaluated at one School-calendar
 * `evaluation_date`, staff-triggered (no scheduler), with the §9 lifecycle.
 * `LateFeeRunService` is the only writer.
 *
 * @property string $id
 * @property string $school_id
 * @property string $fee_late_fee_rule_id
 * @property Carbon $evaluation_date
 * @property string $status
 * @property int|null $previewed_rule_version
 * @property int $ready_count
 * @property string $ready_amount
 * @property int $not_eligible_count
 * @property int $already_assessed_count
 * @property int $succeeded_count
 * @property int $skipped_count
 * @property int $failed_count
 * @property string $assessed_amount
 * @property string $currency
 * @property string|null $created_by_user_id
 * @property Carbon|null $previewed_at
 * @property string|null $previewed_by_user_id
 * @property Carbon|null $execution_started_at
 * @property string|null $executed_by_user_id
 * @property Carbon|null $completed_at
 * @property Carbon|null $cancelled_at
 * @property string|null $cancelled_by_user_id
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class LateFeeRun extends Model
{
    use BelongsToSchool, GeneratesUuidV7;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PREVIEWED = 'previewed';

    public const STATUS_EXECUTING = 'executing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_COMPLETED_WITH_ERRORS = 'completed_with_errors';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [self::STATUS_DRAFT, self::STATUS_PREVIEWED, self::STATUS_EXECUTING, self::STATUS_COMPLETED, self::STATUS_COMPLETED_WITH_ERRORS, self::STATUS_CANCELLED];

    protected $table = 'late_fee_runs';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'evaluation_date' => 'date',
            'previewed_rule_version' => 'integer',
            'ready_count' => 'integer',
            'not_eligible_count' => 'integer',
            'already_assessed_count' => 'integer',
            'succeeded_count' => 'integer',
            'skipped_count' => 'integer',
            'failed_count' => 'integer',
            'previewed_at' => 'datetime',
            'execution_started_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }
}
