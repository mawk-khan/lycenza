<?php

namespace App\Domain\WorkforceTasks\Infrastructure;

use App\Support\Identifiers\GeneratesUuidV7;
use App\Support\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * HR.13: one immutable version of a template's terms (the runtime role holds
 * no UPDATE or DELETE). A change is a new version with a later effective date.
 *
 * @property string $id
 * @property string $template_id
 * @property int $version_no
 * @property string $title
 * @property string|null $description
 * @property string $category_id
 * @property string $completion_mode
 * @property string $frequency
 * @property int|null $weekdays
 * @property int|null $month_day
 * @property bool $last_day
 * @property int|null $year_month
 * @property int $due_days
 * @property bool $applies_teaching
 * @property bool $applies_non_teaching
 * @property bool $applies_outsourced
 * @property string|null $department_id
 * @property string|null $position_id
 * @property Carbon $effective_from
 */
class TaskTemplateVersion extends Model
{
    use BelongsToSchool, GeneratesUuidV7;

    public const UPDATED_AT = null;

    protected $table = 'workforce_task_template_versions';

    protected $fillable = ['template_id', 'version_no', 'title', 'description', 'category_id', 'completion_mode', 'frequency', 'weekdays', 'month_day', 'last_day',
        'year_month', 'due_days', 'applies_teaching', 'applies_non_teaching', 'applies_outsourced', 'department_id', 'position_id', 'effective_from', 'created_by_user_id'];

    protected function casts(): array
    {
        return ['version_no' => 'integer', 'weekdays' => 'integer', 'month_day' => 'integer', 'last_day' => 'boolean', 'year_month' => 'integer', 'due_days' => 'integer',
            'applies_teaching' => 'boolean', 'applies_non_teaching' => 'boolean', 'applies_outsourced' => 'boolean', 'effective_from' => 'date'];
    }

    /** Whether an employment with these facts on the occurrence date is in this version's cohort (every set condition must match). */
    public function appliesTo(?string $staffType, ?string $departmentId, ?string $positionId): bool
    {
        $staffMatch = match ($staffType) {
            'teaching' => $this->applies_teaching,
            'non_teaching' => $this->applies_non_teaching,
            'outsourced' => $this->applies_outsourced,
            default => false, // an unclassified staff type never matches (explicit rule)
        };

        return $staffMatch
            && ($this->department_id === null || $this->department_id === $departmentId)
            && ($this->position_id === null || $this->position_id === $positionId);
    }
}
