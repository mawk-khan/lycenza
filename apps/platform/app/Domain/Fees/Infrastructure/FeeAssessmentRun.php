<?php

namespace App\Domain\Fees\Infrastructure;

use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * FEE.2 (ADR 0062 §9): one staff-triggered assessment of one active fee
 * structure's billing period. Lifecycle and immutability are
 * database-enforced (`fees_validate_fee_assessment_run`);
 * `App\Domain\Fees\Application\FeeAssessmentRunService` owns every write.
 *
 * @property string $id
 * @property string $school_id
 * @property string $fee_structure_id
 * @property string $billing_period_key
 * @property string $status
 * @property int $configuration_version
 * @property int|null $previewed_configuration_version
 * @property int $ready_count
 * @property string $ready_amount
 * @property int $excluded_count
 * @property int $blocked_count
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
class FeeAssessmentRun extends Model
{
    use BelongsToSchool, GeneratesUuidV7;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PREVIEWED = 'previewed';

    public const STATUS_EXECUTING = 'executing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_COMPLETED_WITH_ERRORS = 'completed_with_errors';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_DRAFT, self::STATUS_PREVIEWED, self::STATUS_EXECUTING,
        self::STATUS_COMPLETED, self::STATUS_COMPLETED_WITH_ERRORS, self::STATUS_CANCELLED,
    ];

    public const TERMINAL_STATUSES = [self::STATUS_COMPLETED, self::STATUS_COMPLETED_WITH_ERRORS, self::STATUS_CANCELLED];

    protected $table = 'fee_assessment_runs';

    protected $fillable = ['school_id', 'fee_structure_id', 'billing_period_key', 'status', 'created_by_user_id'];

    protected function casts(): array
    {
        return [
            'configuration_version' => 'integer',
            'previewed_configuration_version' => 'integer',
            'ready_count' => 'integer',
            'excluded_count' => 'integer',
            'blocked_count' => 'integer',
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

    public function isTerminal(): bool
    {
        return in_array($this->status, self::TERMINAL_STATUSES, true);
    }

    /** @return HasMany<FeeAssessmentRunItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(FeeAssessmentRunItem::class);
    }
}
