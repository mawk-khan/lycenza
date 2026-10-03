<?php

namespace App\Domain\Leave\Infrastructure;

use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;

/**
 * HRX.1 (ADR 0065 §4.3): a MATERIALIZED leave year. Its bounds and the start
 * month it was cut from are frozen when it is created, so ledger evidence
 * always resolves to the year it was recorded in. Append-only; never
 * overlapping (database-enforced). Created only by LeaveYearService.
 *
 * @property string $id
 * @property string $school_id
 */
class LeaveYear extends Model
{
    use BelongsToSchool, GeneratesUuidV7;

    public const UPDATED_AT = null;

    protected $table = 'leave_years';

    protected $fillable = ['school_id', 'label', 'starts_on', 'ends_on', 'start_month'];

    protected function casts(): array
    {
        return ['starts_on' => 'date', 'ends_on' => 'date', 'start_month' => 'integer'];
    }
}
