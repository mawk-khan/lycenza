<?php

namespace App\Domain\WorkforceTasks\Infrastructure;

use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;

/** HR.13: see the migration `2027_01_31_090000_create_workforce_tasks`. Status columns are written by the services only. */
class TaskGenerationRun extends Model
{
    use BelongsToSchool, GeneratesUuidV7;

    protected $table = 'workforce_task_generation_runs';

    protected $fillable = ['occurrence_on', 'started_by_user_id', 'started_at'];

    protected function casts(): array
    {
        return ['retired_at' => 'datetime', 'occurrence_on' => 'date', 'started_at' => 'datetime', 'finished_at' => 'datetime',
            'examined' => 'integer', 'created' => 'integer', 'existing' => 'integer', 'not_matched' => 'integer'];
    }
}
