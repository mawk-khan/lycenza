<?php

namespace App\Domain\Payroll\Infrastructure;

use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;

/**
 * ORG.8B (ADR 0076 §60): one human calculation of a payroll run -- who ran it
 * and how many adjustments the run held then (database-checked). Append-only:
 * a recalculation is a new row, so every calculator stays a participant of the
 * run (maker/checker), and the latest one tells whether the results are
 * complete and include every recorded adjustment.
 *
 * @property string $id
 * @property string $school_id
 * @property string $payroll_run_id
 * @property string $calculated_by_user_id
 * @property int $ordinal 1, 2, ... per run (the latest decides)
 * @property int $adjustment_count
 * @property bool $complete every employment resolved, at least one result
 */
class PayrollRunCalculation extends Model
{
    use BelongsToSchool, GeneratesUuidV7;

    public const UPDATED_AT = null;

    protected $fillable = ['school_id', 'payroll_run_id', 'calculated_by_user_id', 'ordinal', 'adjustment_count', 'complete'];

    protected function casts(): array
    {
        return ['ordinal' => 'integer', 'adjustment_count' => 'integer', 'complete' => 'boolean'];
    }
}
