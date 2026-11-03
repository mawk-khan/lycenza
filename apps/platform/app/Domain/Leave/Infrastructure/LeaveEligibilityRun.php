<?php

namespace App\Domain\Leave\Infrastructure;

use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Tenancy\BelongsToSchool;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * HR.9 (ADR 0065 §28.4): the append-only header of one eligibility
 * resolution run -- School, leave type, the as-of date and its final counts.
 * Each assignment it created names it (and the matched rule) as provenance.
 *
 * @property string $id
 * @property string $school_id
 * @property string $leave_type_id
 * @property CarbonImmutable|Carbon $as_of
 * @property int $assigned_count
 * @property int $skipped_count
 * @property Carbon $executed_at
 */
class LeaveEligibilityRun extends Model
{
    use BelongsToSchool, GeneratesUuidV7;

    public $timestamps = false;

    protected $table = 'leave_eligibility_runs';

    protected $fillable = ['school_id', 'leave_type_id', 'as_of', 'executed_by_user_id', 'assigned_count', 'skipped_count', 'executed_at'];

    protected function casts(): array
    {
        return ['as_of' => 'date', 'assigned_count' => 'integer', 'skipped_count' => 'integer', 'executed_at' => 'datetime'];
    }
}
