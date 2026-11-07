<?php

namespace App\Domain\WorkforceTasks\Infrastructure;

use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * HR.13: one staff task of one employment. Written only by the WorkforceTasks
 * services; terms, responsibility and attribution are immutable and the
 * lifecycle graph is database-enforced (trg_workforce_tasks_guard). Nothing
 * but the term columns is mass assignable.
 *
 * @property string $id
 * @property string $school_id
 * @property string $employment_record_id
 * @property string|null $template_id
 * @property string|null $template_version_id
 * @property Carbon|null $occurrence_on
 * @property string $title
 * @property string|null $description
 * @property string $category_id
 * @property string $completion_mode
 * @property string $assignment_path
 * @property string|null $assigned_by_user_id
 * @property Carbon $available_on
 * @property Carbon $due_on
 * @property string $status
 * @property int $return_count
 * @property int $version
 * @property string|null $section_id
 * @property string|null $subject_offering_id
 * @property string|null $staff_type
 * @property string|null $department_id
 * @property string|null $position_id
 * @property string|null $campus_id
 * @property string|null $reassigned_from_task_id
 * @property string|null $completed_by_user_id
 * @property Carbon|null $completed_at
 * @property string|null $cancelled_by_user_id
 * @property Carbon|null $cancelled_at
 * @property string|null $cancel_reason
 */
class WorkforceTask extends Model
{
    use BelongsToSchool, GeneratesUuidV7;

    public const array OPEN_STATUSES = ['assigned', 'submitted', 'returned'];

    /** Open for the ASSIGNEE: something they still have to do. */
    public const array ASSIGNEE_OPEN_STATUSES = ['assigned', 'returned'];

    protected $table = 'workforce_tasks';

    protected $fillable = ['title', 'description', 'category_id', 'completion_mode', 'available_on', 'due_on', 'section_id', 'subject_offering_id'];

    protected function casts(): array
    {
        return ['occurrence_on' => 'date', 'available_on' => 'date', 'due_on' => 'date', 'return_count' => 'integer', 'version' => 'integer',
            'completed_at' => 'datetime', 'cancelled_at' => 'datetime'];
    }
}
