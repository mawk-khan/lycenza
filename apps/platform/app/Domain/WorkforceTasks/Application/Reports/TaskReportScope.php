<?php

namespace App\Domain\WorkforceTasks\Application\Reports;

use App\Domain\HR\Application\Reports\HrReportLabels;
use App\Domain\HR\Application\Workforce\StaffType;
use App\Domain\WorkforceTasks\Application\TaskFrequency;
use App\Domain\WorkforceTasks\Infrastructure\TaskCategory;
use App\Domain\WorkforceTasks\Infrastructure\WorkforceTask;
use App\Models\School;
use App\Support\Tenancy\SchoolTimezone;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * HR.14: the ONE filter set the task reports share, applied in SQL to
 * `workforce_tasks` (alias `t`). Department, Designation and staff type are
 * the attribution RECORDED ON THE TASK when it was created (HR.13), so a
 * later transfer or promotion never re-attributes old tasks. Overdue is
 * HR.13's definition exactly (WorkforceTask::ASSIGNEE_OPEN_STATUSES, due
 * before the School-local today); a submitted task is never overdue and a
 * future task never is. Every method MUST run inside the School's
 * TenantContext.
 */
final class TaskReportScope
{
    public const array STATUS_LABELS = [
        'assigned' => 'Assigned', 'submitted' => 'Submitted for review', 'returned' => 'Returned',
        'completed' => 'Completed', 'cancelled' => 'Cancelled',
    ];

    /** @param  array<string, string>  $filters */
    public static function base(School $school, array $filters): Builder
    {
        $query = DB::table('workforce_tasks as t')->where('t.school_id', $school->id)
            ->whereBetween('t.due_on', [$filters['from'], $filters['to']]);
        foreach (['task_category_id' => 't.category_id', 'department_id' => 't.department_id', 'position_id' => 't.position_id', 'staff_type' => 't.staff_type'] as $key => $column) {
            if (($filters[$key] ?? '') !== '') {
                $query->where($column, $filters[$key]);
            }
        }
        if (($filters['frequency'] ?? '') === 'one_off') {
            $query->whereNull('t.template_version_id');
        } elseif (($filters['frequency'] ?? '') !== '') {
            $query->whereIn('t.template_version_id', fn ($q) => $q->select('id')->from('workforce_task_template_versions')->where('frequency', $filters['frequency']));
        }
        HrReportLabels::searchEmployment($query, 't.employment_record_id', $filters['q'] ?? '');

        return $query;
    }

    /** A status filter value: a closed status, `open` (not completed or cancelled) or `overdue`. */
    public static function status(Builder $query, School $school, string $status): Builder
    {
        return match ($status) {
            '' => $query,
            'open' => $query->whereIn('t.status', WorkforceTask::OPEN_STATUSES),
            'overdue' => $query->whereIn('t.status', WorkforceTask::ASSIGNEE_OPEN_STATUSES)->where('t.due_on', '<', SchoolTimezone::today($school)),
            default => $query->where('t.status', $status),
        };
    }

    /** SQL: whether a row is overdue on the School-local today (bound). */
    public static function overdueSql(): string
    {
        return "t.status IN ('".implode("', '", WorkforceTask::ASSIGNEE_OPEN_STATUSES)."') AND t.due_on < ?::date";
    }

    /** @return array<string, list<array{value: string, label: string}>> */
    public static function options(School $school, HrReportLabels $labels): array
    {
        $placement = $labels->placementOptions($school);

        return [
            'status' => [
                ['value' => 'open', 'label' => 'Open'], ['value' => 'overdue', 'label' => 'Overdue'],
                ...array_map(fn (string $k, string $l) => ['value' => $k, 'label' => $l], array_keys(self::STATUS_LABELS), self::STATUS_LABELS),
            ],
            'task_category_id' => TaskCategory::query()->where('school_id', $school->id)->orderBy('label')->limit(200)->get(['id', 'label'])
                ->map(fn (TaskCategory $c) => ['value' => (string) $c->id, 'label' => (string) $c->label])->values()->all(),
            'department_id' => $placement['department_id'],
            'position_id' => $placement['position_id'],
            'staff_type' => StaffType::options(),
            'frequency' => [...TaskFrequency::options(), ['value' => 'one_off', 'label' => 'One-off']],
        ];
    }
}
