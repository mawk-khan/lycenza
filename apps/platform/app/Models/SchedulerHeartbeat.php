<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Central/platform data. See the migration's docblock and
 * App\Support\Observability\SchedulerHeartbeatRecorder.
 *
 * @property string $name
 * @property Carbon|null $last_run_at
 * @property Carbon|null $last_success_at
 */
class SchedulerHeartbeat extends Model
{
    protected $primaryKey = 'name';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'last_run_at' => 'datetime',
            'last_success_at' => 'datetime',
        ];
    }
}
