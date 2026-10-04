<?php

namespace App\Domain\Leave\Infrastructure;

use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;

/**
 * HRX.2 (ADR 0065 §23.5): append-only decision evidence. The decider's and
 * the requester's Employees are derived by the database, which also refuses
 * approving or rejecting one's own request.
 *
 * @property string $id
 * @property string $school_id
 * @property string|null $decider_employee_id
 * @property string $requester_employee_id
 */
class LeaveDecision extends Model
{
    use BelongsToSchool, GeneratesUuidV7;

    /** HRX.4 (ADR 0065 §25.4): `self` -- the requester's own withdrawal or cancellation. */
    public const PATHS = ['manager', 'administrative', 'self'];

    /** ADR 0065 §23.3. */
    public const REJECTION_REASONS = ['staffing_need', 'policy_not_met', 'duplicate_request', 'entered_in_error', 'other'];

    /** ADR 0065 §23.3: withdrawal and cancellation. */
    public const CLOSING_REASONS = ['plans_changed', 'entered_in_error', 'administrative_correction', 'other'];

    public const UPDATED_AT = null;

    protected $table = 'leave_decisions';

    protected $fillable = ['school_id', 'leave_request_id', 'requester_employee_id', 'decision', 'path', 'decided_by_user_id', 'reason_code'];

    protected function casts(): array
    {
        return [];
    }
}
