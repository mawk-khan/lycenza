<?php

namespace App\Domain\StaffAttendance\Infrastructure;

use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * HR.R1-C18 (S-6): a locked School-local calendar month. Append-only: never updated, never deleted,
 * never reopened. Written only by StaffAttendanceService, which forces its columns.
 *
 * @property string $id
 * @property string $school_id
 * @property Carbon $month
 * @property string $locked_by_user_id
 * @property Carbon $locked_at
 */
class StaffAttendanceMonthLock extends Model
{
    use BelongsToSchool, GeneratesUuidV7;

    public $timestamps = false;

    protected $table = 'staff_attendance_month_locks';

    /** Nothing: the month, the locker and the time are the owner's (rule 97). */
    protected $fillable = [];

    protected function casts(): array
    {
        return ['month' => 'date:Y-m-d', 'locked_at' => 'datetime'];
    }
}
