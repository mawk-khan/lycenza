<?php

namespace App\Domain\Leave\Infrastructure;

use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;

/**
 * HRX.2 (ADR 0065 §5, §23.1): one leave request of one EmploymentRecord for
 * one leave type. Its dates, portions and type never change after
 * submission; its status moves only along the closed graph, each step with
 * its decision evidence (database-enforced). `employee_id` is derived from
 * the EmploymentRecord by the database.
 *
 * @property string $id
 * @property string $school_id
 * @property string $employee_id
 * @property string $employment_record_id
 * @property string $leave_type_id
 * @property string $status
 */
class LeaveRequest extends Model
{
    use BelongsToSchool, GeneratesUuidV7;

    public const STATUSES = ['submitted', 'approved', 'rejected', 'withdrawn', 'cancelled'];

    public const LIVE_STATUSES = ['submitted', 'approved'];

    /** ADR 0065 §23.3: optional, neutral, never health-related. */
    public const REASONS = ['personal', 'family', 'official_duty', 'other'];

    protected $table = 'leave_requests';

    protected $fillable = ['school_id', 'employment_record_id', 'leave_type_id', 'starts_on', 'start_portion', 'ends_on', 'end_portion', 'reason_code', 'submitted_units', 'status', 'submitted_by_user_id'];

    protected function casts(): array
    {
        return ['starts_on' => 'date', 'ends_on' => 'date', 'submitted_units' => 'integer', 'first_half_index' => 'integer', 'last_half_index' => 'integer'];
    }
}
