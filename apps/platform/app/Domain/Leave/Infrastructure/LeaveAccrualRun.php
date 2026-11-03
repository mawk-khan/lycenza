<?php

namespace App\Domain\Leave\Infrastructure;

use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * HR.10 (ADR 0065 §29.6): one accrual run of one month -- processing, then
 * completed (final) or failed (resumable). Its identity never changes and
 * its cursor only moves forward (`trg_leave_accrual_runs_guard`); at most
 * one open run per month. No per-Employee amount lives here.
 *
 * @property string $id
 * @property string $school_id
 * @property string $leave_type_id
 * @property string $leave_year_id
 * @property Carbon $period_start
 * @property string $status
 * @property string|null $cursor_employment_id
 * @property int $batch_count
 * @property string|null $failure_code
 * @property Carbon $started_at
 * @property Carbon|null $completed_at
 */
class LeaveAccrualRun extends Model
{
    use BelongsToSchool, GeneratesUuidV7;

    protected $table = 'leave_accrual_runs';

    protected $fillable = ['school_id', 'leave_type_id', 'leave_year_id', 'period_start', 'status', 'cursor_employment_id', 'batch_count', 'failure_code', 'started_by_user_id', 'started_at', 'completed_at'];

    protected function casts(): array
    {
        return ['period_start' => 'date', 'batch_count' => 'integer', 'started_at' => 'datetime', 'completed_at' => 'datetime'];
    }
}
