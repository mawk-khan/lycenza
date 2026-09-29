<?php

namespace App\Domain\Fees\Infrastructure;

use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * FEE.1 (ADR 0062 §7.2): one fee head in a structure with its yearly
 * amount (NUMERIC(14,2), always handled as a decimal string). `frequency`
 * is descriptive only; the instalment rows are the schedule. Writable only
 * while the structure is a draft (database-enforced).
 *
 * @property string $id
 * @property string $school_id
 * @property string $fee_structure_id
 * @property string $fee_head_id
 * @property bool $is_optional
 * @property string $frequency
 * @property string $amount
 * @property string $currency
 */
class FeeStructureLine extends Model
{
    use BelongsToSchool, GeneratesUuidV7;

    public const FREQUENCY_ONE_TIME = 'one_time';

    public const FREQUENCY_TERM = 'term';

    public const FREQUENCY_MONTHLY = 'monthly';

    public const FREQUENCY_CUSTOM = 'custom';

    public const FREQUENCIES = [self::FREQUENCY_ONE_TIME, self::FREQUENCY_TERM, self::FREQUENCY_MONTHLY, self::FREQUENCY_CUSTOM];

    /** The frequencies with an authoring generator (ADR 0062 §7.3, decision C). */
    public const GENERATED_FREQUENCIES = [self::FREQUENCY_ONE_TIME, self::FREQUENCY_TERM, self::FREQUENCY_MONTHLY];

    protected $table = 'fee_structure_lines';

    protected $fillable = ['school_id', 'fee_structure_id', 'fee_head_id', 'is_optional', 'frequency', 'amount', 'currency'];

    protected function casts(): array
    {
        return [
            'is_optional' => 'boolean',
        ];
    }

    /** @return BelongsTo<FeeHead, $this> */
    public function feeHead(): BelongsTo
    {
        return $this->belongsTo(FeeHead::class);
    }

    /** @return HasMany<FeeStructureInstallment, $this> */
    public function installments(): HasMany
    {
        return $this->hasMany(FeeStructureInstallment::class)->orderBy('sequence');
    }
}
