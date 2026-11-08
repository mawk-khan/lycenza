<?php

namespace App\Domain\StaffAttendance\Infrastructure;

use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * HR.R1-C18 (S-6): a request to correct a record of a locked month, and its final decision.
 * Born `requested`; the terms are frozen and the decision final (database). An approval is
 * applied by the database, from these values, in the same statement.
 *
 * @property string $id
 * @property string $school_id
 * @property string $staff_attendance_record_id
 * @property string $employment_record_id
 * @property Carbon $month
 * @property int $from_version
 * @property ?string $before_first_half_status
 * @property ?string $before_second_half_status
 * @property ?string $after_first_half_status
 * @property ?string $after_second_half_status
 * @property string $reason_code
 * @property string $form_key
 * @property string $status
 * @property string $requested_by_user_id
 * @property ?string $decided_by_user_id
 * @property Carbon $requested_at
 * @property ?Carbon $decided_at
 */
class StaffAttendanceLockedCorrection extends Model
{
    use BelongsToSchool, GeneratesUuidV7;

    public const REQUESTED = 'requested';

    public const APPROVED = 'approved';

    public const REFUSED = 'refused';

    public $timestamps = false;

    protected $table = 'staff_attendance_locked_corrections';

    /** Nothing: every column is written by StaffAttendanceService on a fixed array (rule 97). */
    protected $fillable = [];

    protected function casts(): array
    {
        return ['month' => 'date:Y-m-d', 'from_version' => 'integer', 'requested_at' => 'datetime', 'decided_at' => 'datetime'];
    }
}
