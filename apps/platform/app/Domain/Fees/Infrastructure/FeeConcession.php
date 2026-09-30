<?php

namespace App\Domain\Fees\Infrastructure;

use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * FEE.3 (ADR 0062 §14; owner decisions F, M): an approvable concession
 * request -- never a financial posting itself. `targeted` names one charge
 * (fixed amount); `standing` covers a Student x year x fee head (NULL =
 * every head) x validity window and applies when assessment creates a
 * matching charge. Lifecycle and separation of duties are
 * database-enforced (`fees_validate_fee_concession`,
 * `fee_concessions_sod_check`); `FeeConcessionService` is the only writer.
 * There is deliberately no note/reason column (M).
 *
 * @property string $id
 * @property string $school_id
 * @property string $student_id
 * @property string $academic_year_id
 * @property string $category
 * @property string $scope
 * @property string|null $charge_id
 * @property string|null $fee_head_id
 * @property Carbon|null $valid_from
 * @property Carbon|null $valid_to
 * @property string $kind
 * @property string|null $fixed_amount
 * @property string|null $percentage
 * @property string $currency
 * @property string $status
 * @property string $idempotency_key
 * @property string $requested_by_user_id
 * @property string|null $decided_by_user_id
 * @property Carbon|null $decided_at
 * @property Carbon|null $withdrawn_at
 * @property string|null $revoked_by_user_id
 * @property Carbon|null $revoked_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class FeeConcession extends Model
{
    use BelongsToSchool, GeneratesUuidV7;

    public const CATEGORIES = ['concession', 'scholarship', 'waiver'];

    public const SCOPE_TARGETED = 'targeted';

    public const SCOPE_STANDING = 'standing';

    public const KIND_FIXED = 'fixed';

    public const KIND_PERCENTAGE = 'percentage';

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_WITHDRAWN = 'withdrawn';

    public const STATUS_REVOKED = 'revoked';

    public const STATUSES = [self::STATUS_PENDING, self::STATUS_APPROVED, self::STATUS_REJECTED, self::STATUS_WITHDRAWN, self::STATUS_REVOKED];

    protected $table = 'fee_concessions';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'valid_from' => 'date',
            'valid_to' => 'date',
            'decided_at' => 'datetime',
            'withdrawn_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function isTargeted(): bool
    {
        return $this->scope === self::SCOPE_TARGETED;
    }
}
