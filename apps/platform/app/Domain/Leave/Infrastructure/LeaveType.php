<?php

namespace App\Domain\Leave\Infrastructure;

use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\NormalizesCode;
use App\Support\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;

/**
 * HRX.1 (ADR 0065 §4.1): a School-configured leave type. No statutory
 * catalogue and no meaning assumed from its name: a type called "Sick"
 * collects no health detail. `is_paid`, `tracks_balance` and
 * `allows_half_day` are independent and freeze once the type is used.
 * Deactivated, never deleted.
 *
 * @property string $id
 * @property string $school_id
 */
class LeaveType extends Model
{
    use BelongsToSchool, GeneratesUuidV7, NormalizesCode;

    public const STATUSES = ['active', 'inactive'];

    protected $table = 'leave_types';

    protected $fillable = ['school_id', 'code', 'name', 'is_paid', 'tracks_balance', 'allows_half_day', 'status'];

    protected function casts(): array
    {
        return ['is_paid' => 'boolean', 'tracks_balance' => 'boolean', 'allows_half_day' => 'boolean'];
    }
}
