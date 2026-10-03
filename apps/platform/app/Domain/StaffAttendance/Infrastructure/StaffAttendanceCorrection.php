<?php

namespace App\Domain\StaffAttendance\Infrastructure;

use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * HRX.3 (ADR 0065 §24.8): append-only correction evidence -- from/to
 * version, both halves before and after, a closed neutral reason code and
 * the actor. The database requires the before-values and from-version to
 * be the record's current ones, keeps the chain unique and refuses, at
 * commit, a correction that was never applied to its record.
 *
 * @property string $id
 * @property string $school_id
 * @property string $staff_attendance_record_id
 * @property string $employment_record_id
 * @property int $from_version
 * @property int $to_version
 * @property string|null $before_first_half_status
 * @property string|null $before_second_half_status
 * @property string|null $after_first_half_status
 * @property string|null $after_second_half_status
 * @property string $reason_code
 * @property string $corrected_by_user_id
 * @property Carbon $created_at
 */
class StaffAttendanceCorrection extends Model
{
    use BelongsToSchool, GeneratesUuidV7;

    /** ADR 0065 §24.8: administrative provenance only -- never a health or medical reason. */
    public const REASONS = ['entered_in_error', 'late_information', 'administrative_review', 'other'];

    public const UPDATED_AT = null;

    protected $table = 'staff_attendance_corrections';

    protected $fillable = [
        'school_id', 'staff_attendance_record_id', 'employment_record_id', 'from_version', 'to_version',
        'before_first_half_status', 'before_second_half_status', 'after_first_half_status', 'after_second_half_status',
        'reason_code', 'corrected_by_user_id',
    ];

    protected function casts(): array
    {
        return ['from_version' => 'integer', 'to_version' => 'integer', 'created_at' => 'datetime'];
    }
}
