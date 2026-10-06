<?php

namespace App\Domain\Library\Infrastructure;

use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * OPF.4 (ADR 0067 D3): one immutable, numbered version of a School's Library
 * fine policy. The highest version governs; a change is a new version, so a
 * version a fine used never changes (insert-only for the runtime role). An
 * `active` version names the FEE fee head (ledger destination), the daily
 * rate, the grace days and an optional cap; a `disabled` version switches
 * fines off. Written only by LibraryFinePolicyService.
 *
 * @property string $id
 * @property string $school_id
 * @property int $version
 * @property string $status active|disabled
 * @property string|null $fee_head_id
 * @property string|null $daily_rate
 * @property int|null $grace_days
 * @property string|null $max_amount
 * @property string $currency
 * @property string|null $created_by_user_id
 * @property Carbon|null $created_at
 */
class LibraryFinePolicy extends Model
{
    use BelongsToSchool, GeneratesUuidV7;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_DISABLED = 'disabled';

    public const UPDATED_AT = null;

    protected $table = 'library_fine_policies';

    protected $fillable = ['school_id', 'version', 'status', 'fee_head_id', 'daily_rate', 'grace_days', 'max_amount', 'currency', 'created_by_user_id'];

    protected function casts(): array
    {
        return ['version' => 'integer', 'grace_days' => 'integer'];
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }
}
