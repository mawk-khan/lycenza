<?php

namespace App\Domain\Payments\Infrastructure;

use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * FEE.5 (ADR 0062 §16.3): the durable link source charge -> late-fee rule
 * -> late-fee charge -> run. One live row per (School, source charge,
 * rule) -- `late_fee_assessments_one_live_per_rule`. Immutable except the
 * one-time void, which cancels the late-fee charge in the same
 * transaction.
 *
 * @property string $id
 * @property string $school_id
 * @property string $source_charge_id
 * @property string $fee_late_fee_rule_id
 * @property string $charge_id
 * @property string $late_fee_run_id
 * @property string|null $created_by_user_id
 * @property Carbon|null $voided_at
 * @property string|null $voided_by_user_id
 * @property string|null $void_reason
 * @property Carbon $created_at
 */
class LateFeeAssessment extends Model
{
    use BelongsToSchool, GeneratesUuidV7;

    protected $table = 'late_fee_assessments';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'voided_at' => 'datetime',
        ];
    }

    public function isLive(): bool
    {
        return $this->voided_at === null;
    }
}
