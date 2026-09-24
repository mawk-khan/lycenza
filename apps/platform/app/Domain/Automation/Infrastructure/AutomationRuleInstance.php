<?php

namespace App\Domain\Automation\Infrastructure;

use App\Models\User;
use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A School's enablement of one catalog rule type (ADR 0043 §1). Written
 * only by AutomationRuleService (configuration) and
 * AutomationExecutionService (suspension).
 *
 * @property string $id
 * @property string $school_id
 * @property string $rule_type
 * @property string $status
 * @property string|null $owner_user_id
 * @property Carbon|null $enabled_at
 * @property Carbon|null $suspended_at
 * @property string|null $suspension_reason
 */
class AutomationRuleInstance extends Model
{
    use BelongsToSchool, GeneratesUuidV7;

    public const STATUS_ENABLED = 'enabled';

    public const STATUS_DISABLED = 'disabled';

    public const STATUS_SUSPENDED = 'suspended';

    protected $fillable = ['school_id', 'rule_type', 'status', 'owner_user_id', 'enabled_at', 'suspended_at', 'suspension_reason'];

    protected function casts(): array
    {
        return [
            'enabled_at' => 'datetime',
            'suspended_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }
}
