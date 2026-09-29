<?php

namespace App\Domain\Fees\Infrastructure;

use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\NormalizesCode;
use App\Support\Tenancy\BelongsToSchool;
use Database\Factories\FeeHeadFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * FEE.1 (ADR 0062 §5): a School's fee catalogue entry, mapping future
 * charges to one receivable (asset) and one revenue (income) ledger
 * account. Persistence only -- `App\Domain\Fees\Application\FeeHeadService`
 * owns every write. Deactivated, never deleted.
 *
 * @property string $id
 * @property string $school_id
 * @property string $code
 * @property string $name
 * @property string|null $description
 * @property string $status
 * @property string $receivable_ledger_account_id
 * @property string $revenue_ledger_account_id
 * @property string $currency
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class FeeHead extends Model
{
    use BelongsToSchool, GeneratesUuidV7, HasFactory, NormalizesCode;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    public const STATUSES = [self::STATUS_ACTIVE, self::STATUS_INACTIVE];

    protected $table = 'fee_heads';

    protected $fillable = [
        'school_id', 'code', 'name', 'description', 'status',
        'receivable_ledger_account_id', 'revenue_ledger_account_id', 'currency',
    ];

    protected static function newFactory(): FeeHeadFactory
    {
        return FeeHeadFactory::new();
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }
}
