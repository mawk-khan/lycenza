<?php

namespace App\Domain\StaffAttendance\Infrastructure;

use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * HRX.3 (ADR 0065 §24.2): one EmploymentRecord's Staff Attendance on one
 * date -- exact per-half evidence, `present`, `absent` or null (no evidence
 * for that half). Leave, holidays and weekly offs are never stored here.
 *
 * The halves change only through a correction (StaffAttendanceCorrection);
 * the database refuses any other UPDATE and every DELETE. `employee_id` is
 * derived from the EmploymentRecord by the database.
 *
 * @property string $id
 * @property string $school_id
 * @property string $employment_record_id
 * @property string $employee_id
 * @property Carbon $attendance_date
 * @property string|null $first_half_status
 * @property string|null $second_half_status
 * @property int $version
 * @property string $recorded_by_user_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class StaffAttendanceRecord extends Model
{
    use BelongsToSchool, GeneratesUuidV7;

    /** ADR 0065 §24.2: the closed list of stored half values (null = no evidence). */
    public const HALF_STATUSES = ['present', 'absent'];

    protected $table = 'staff_attendance_records';

    protected $fillable = ['school_id', 'employment_record_id', 'attendance_date', 'first_half_status', 'second_half_status', 'recorded_by_user_id'];

    protected function casts(): array
    {
        return ['attendance_date' => 'date', 'version' => 'integer'];
    }

    /** @return array{1: string|null, 2: string|null} half (1 = first, 2 = second) => stored value */
    public function halves(): array
    {
        return [1 => $this->first_half_status, 2 => $this->second_half_status];
    }
}
