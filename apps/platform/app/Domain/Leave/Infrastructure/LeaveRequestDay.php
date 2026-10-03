<?php

namespace App\Domain\Leave\Infrastructure;

use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;

/**
 * HRX.2 (ADR 0065 §22.6, §23.4): the IMMUTABLE approval snapshot -- one
 * chargeable date of an approved request with its portion, integer units,
 * leave year and (balance-tracked types) the policy assignment and policy
 * version in force that day. Written once at approval; never recomputed.
 *
 * @property string $id
 * @property string $school_id
 */
class LeaveRequestDay extends Model
{
    use BelongsToSchool, GeneratesUuidV7;

    public const UPDATED_AT = null;

    protected $table = 'leave_request_days';

    protected $fillable = ['school_id', 'leave_request_id', 'leave_date', 'portion', 'units', 'leave_year_id', 'leave_policy_assignment_id', 'leave_policy_id'];

    protected function casts(): array
    {
        return ['leave_date' => 'date', 'units' => 'integer'];
    }
}
