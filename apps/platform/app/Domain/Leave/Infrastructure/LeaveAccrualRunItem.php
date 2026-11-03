<?php

namespace App\Domain\Leave\Infrastructure;

use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;

/**
 * HR.10 (ADR 0065 §29.6): one examined employment of an accrual run and its
 * closed outcome -- the run's reconciliation evidence. Append-only; goes
 * with its employment.
 *
 * @property string $id
 * @property string $accrual_run_id
 * @property string $employment_record_id
 * @property string $outcome
 * @property int|null $units
 * @property bool $eligibility_drift
 */
class LeaveAccrualRunItem extends Model
{
    use BelongsToSchool, GeneratesUuidV7;

    public const UPDATED_AT = null;

    protected $table = 'leave_accrual_run_items';

    protected $fillable = ['school_id', 'accrual_run_id', 'employment_record_id', 'outcome', 'units', 'eligibility_drift'];

    protected function casts(): array
    {
        return ['units' => 'integer', 'eligibility_drift' => 'boolean'];
    }
}
