<?php

namespace App\Domain\Leave\Infrastructure;

use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;

/**
 * HRX.1 correction (ADR 0065 §22.1): an explicit, PROSPECTIVE change of the
 * School's leave-year start month. It takes effect on `effective_from` (the
 * first day of the new start month), strictly after every materialized
 * leave year and every earlier change, so no existing year or evidence is
 * reinterpreted. Append-only; created only by LeaveYearService.
 *
 * @property string $id
 * @property string $school_id
 */
class LeaveYearStartChange extends Model
{
    use BelongsToSchool, GeneratesUuidV7;

    public const UPDATED_AT = null;

    protected $table = 'leave_year_start_changes';

    protected $fillable = ['school_id', 'previous_start_month', 'start_month', 'effective_from', 'created_by_user_id'];

    protected function casts(): array
    {
        return ['previous_start_month' => 'integer', 'start_month' => 'integer', 'effective_from' => 'date'];
    }
}
