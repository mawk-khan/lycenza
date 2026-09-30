<?php

namespace App\Domain\Fees\Infrastructure;

use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * FEE.5 (ADR 0062 §16.1; owner decision H): a late-fee rule --
 * configuration only. Scope: one fee structure plus an optional fee head;
 * `late_fee_head_id` carries the late fee's accounts. `fixed` or
 * `percentage` (of current outstanding), `grace_days`, optional cap.
 * Created inactive, editable only while inactive, never deleted
 * (`fees_validate_late_fee_rule`). `LateFeeRuleService` is the only writer.
 *
 * @property string $id
 * @property string $school_id
 * @property string $name
 * @property string $fee_structure_id
 * @property string|null $fee_head_id
 * @property string $late_fee_head_id
 * @property int $grace_days
 * @property string $kind
 * @property string|null $fixed_amount
 * @property string|null $percentage
 * @property string|null $max_amount
 * @property string $currency
 * @property string $status
 * @property int $configuration_version
 * @property string|null $created_by_user_id
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class FeeLateFeeRule extends Model
{
    use BelongsToSchool, GeneratesUuidV7;

    public const KIND_FIXED = 'fixed';

    public const KIND_PERCENTAGE = 'percentage';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    protected $table = 'fee_late_fee_rules';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'grace_days' => 'integer',
            'configuration_version' => 'integer',
        ];
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }
}
