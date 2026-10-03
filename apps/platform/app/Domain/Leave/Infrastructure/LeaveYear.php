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
 * A TRANSITION year (`is_transition`) is the explicit, shorter year that
 * bridges the old schedule to a scheduled start-month change; it ends the
 * day before the change takes effect (ADR 0065 §22.1).
 *
 * @property string $id
 * @property string $school_id
 * @property bool $is_transition
 */
class LeaveYear extends Model
{
    use BelongsToSchool, GeneratesUuidV7;

    public const UPDATED_AT = null;

    protected $table = 'leave_years';

    protected $fillable = ['school_id', 'label', 'starts_on', 'ends_on', 'start_month', 'is_transition'];

    protected function casts(): array
    {
        return ['starts_on' => 'date', 'ends_on' => 'date', 'start_month' => 'integer', 'is_transition' => 'boolean'];
    }
}
