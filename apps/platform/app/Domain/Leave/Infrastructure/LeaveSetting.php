<?php

namespace App\Domain\Leave\Infrastructure;

use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;

/**
 * HRX.1 (ADR 0065 §22.1): the School's BASE leave-year start month (1..12,
 * default April). A product default, not a statutory claim, and independent
 * of Finance's financial year. The base changes directly only while the
 * School has no leave year and no scheduled change (database trigger
 * `trg_leave_settings_lock`); afterwards the start month changes only
 * prospectively, through a LeaveYearStartChange.
 *
 * @property string $id
 * @property string $school_id
 */
class LeaveSetting extends Model
{
    use BelongsToSchool, GeneratesUuidV7;

    public const DEFAULT_START_MONTH = 4;

    protected $table = 'leave_settings';

    protected $fillable = ['school_id', 'leave_year_start_month', 'updated_by_user_id'];

    protected function casts(): array
    {
        return ['leave_year_start_month' => 'integer'];
    }
}
