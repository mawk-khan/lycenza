<?php

namespace App\Domain\Leave\Infrastructure;

use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;

/**
 * HRX.2 (ADR 0065 §23.8): one employment x leave type at a year close --
 * the ledger-derived closing balance, the policy terms that governed it
 * (snapshotted), carried and lapsed units and the carried units' recorded
 * expiry. Append-only; the basis of every later reconciliation.
 *
 * @property string $id
 * @property string $school_id
 */
class LeaveYearCloseItem extends Model
{
    use BelongsToSchool, GeneratesUuidV7;

    public const UPDATED_AT = null;

    protected $table = 'leave_year_close_items';

    protected $fillable = ['school_id', 'year_close_id', 'employment_record_id', 'leave_type_id', 'leave_policy_id', 'carry_forward_allowed', 'carry_forward_cap_units', 'closing_units', 'carried_units', 'lapsed_units', 'carried_expires_on'];

    protected function casts(): array
    {
        return ['carry_forward_allowed' => 'boolean', 'carry_forward_cap_units' => 'integer', 'closing_units' => 'integer', 'carried_units' => 'integer', 'lapsed_units' => 'integer', 'carried_expires_on' => 'date'];
    }
}
