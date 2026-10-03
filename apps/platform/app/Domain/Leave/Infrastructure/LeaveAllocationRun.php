<?php

namespace App\Domain\Leave\Infrastructure;

use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;

/**
 * HRX.1 (ADR 0065 §4.5): the header of one annual allocation run, at most
 * one per School x leave year x leave type (database-unique). It holds no
 * per-employee amount; the grants are ledger entries.
 *
 * @property string $id
 * @property string $school_id
 */
class LeaveAllocationRun extends Model
{
    use BelongsToSchool, GeneratesUuidV7;

    public $timestamps = false;

    protected $table = 'leave_allocation_runs';

    protected $fillable = ['school_id', 'leave_year_id', 'leave_type_id', 'executed_by_user_id', 'allocated_count', 'executed_at'];

    protected function casts(): array
    {
        return ['allocated_count' => 'integer', 'executed_at' => 'datetime'];
    }
}
