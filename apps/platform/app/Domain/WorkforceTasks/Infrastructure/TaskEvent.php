<?php

namespace App\Domain\WorkforceTasks\Infrastructure;

use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * HR.13: one append-only entry of a task's timeline (no runtime UPDATE or
 * DELETE). The assignee's remarks and the reviewer's feedback live here,
 * bounded plain text, so a later submission never overwrites an earlier one.
 *
 * @property string $id
 * @property string $task_id
 * @property string $kind
 * @property string|null $actor_user_id
 * @property string|null $note
 * @property Carbon $occurred_at
 */
class TaskEvent extends Model
{
    use BelongsToSchool, GeneratesUuidV7;

    public $timestamps = false;

    protected $table = 'workforce_task_events';

    protected $fillable = ['task_id', 'kind', 'actor_user_id', 'note', 'occurred_at'];

    protected function casts(): array
    {
        return ['occurred_at' => 'datetime'];
    }
}
