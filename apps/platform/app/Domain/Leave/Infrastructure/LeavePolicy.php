<?php

namespace App\Domain\Leave\Infrastructure;

use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;

/**
 * HRX.1 (ADR 0065 §4.2): one leave type's terms, in integer half-day units.
 * Immutable once created; the only change is retiring it. A new version
 * supersedes it, so evidence always resolves to the terms it was made under.
 *
 * @property string $id
 * @property string $school_id
 */
class LeavePolicy extends Model
{
    use BelongsToSchool, GeneratesUuidV7;

    protected $table = 'leave_policies';

    protected $fillable = ['school_id', 'leave_type_id', 'name', 'annual_allocation_units', 'carry_forward_allowed', 'carry_forward_cap_units', 'carry_forward_expiry_days', 'status', 'supersedes_policy_id', 'created_by_user_id', 'retired_at'];

    protected function casts(): array
    {
        return ['annual_allocation_units' => 'integer', 'carry_forward_allowed' => 'boolean', 'carry_forward_cap_units' => 'integer', 'carry_forward_expiry_days' => 'integer', 'retired_at' => 'datetime'];
    }
}
