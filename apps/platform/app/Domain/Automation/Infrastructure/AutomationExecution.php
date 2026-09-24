<?php

namespace App\Domain\Automation\Infrastructure;

use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One rule instance reacting to one trigger occurrence (ADR 0043 §7).
 *
 * @property string $id
 * @property string $school_id
 * @property string $rule_instance_id
 * @property string $trigger_key
 * @property string $trigger_event_type
 * @property string $subject_type
 * @property string $subject_id
 * @property string|null $correlation_id
 * @property string $status
 * @property string|null $outcome_code
 * @property int $attempts
 * @property Carbon|null $next_attempt_at
 * @property Carbon|null $processing_lease_expires_at
 * @property Carbon|null $completed_at
 * @property Carbon $created_at
 */
class AutomationExecution extends Model
{
    use BelongsToSchool, GeneratesUuidV7;

    public const STATUS_PENDING = 'pending';

    public const STATUS_RUNNING = 'running';

    public const STATUS_SUCCEEDED = 'succeeded';

    public const STATUS_SKIPPED = 'skipped';

    public const STATUS_FAILED = 'failed';

    public const STATUS_ABANDONED = 'abandoned';

    protected $fillable = [
        'school_id', 'rule_instance_id', 'trigger_key', 'trigger_event_type', 'subject_type', 'subject_id',
        'correlation_id', 'status', 'outcome_code', 'attempts', 'next_attempt_at', 'processing_lease_expires_at', 'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'next_attempt_at' => 'datetime',
            'processing_lease_expires_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<AutomationRuleInstance, $this> */
    public function ruleInstance(): BelongsTo
    {
        return $this->belongsTo(AutomationRuleInstance::class, 'rule_instance_id');
    }
}
