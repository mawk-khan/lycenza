<?php

namespace App\Domain\WorkforceTasks\Application\Reports;

use App\Domain\HR\Application\Reports\HrReportLabels;
use App\Domain\HR\Application\Workforce\StaffType;
use App\Domain\WorkforceTasks\Application\TaskCapabilities;
use App\Domain\WorkforceTasks\Application\TaskFrequency;
use App\Domain\WorkforceTasks\Infrastructure\TaskCategory;
use App\Domain\WorkforceTasks\Infrastructure\WorkforceTask;
use App\Models\School;
use App\Support\HrReports\HrReport;
use App\Support\HrReports\HrReportColumn as C;
use App\Support\HrReports\HrReportDefinition as D;
use App\Support\HrReports\HrReportRows;
use App\Support\HrReports\HrReportViewer;
use App\Support\Tenancy\SchoolTimezone;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * HR.14 (D19-25, D20-26): the detailed Staff Task report -- every task
 * occurrence due in a period, with its status, derived overdue, recurrence
 * and the Department / Designation / staff type RECORDED ON THE TASK (never
 * today's placement). Never a remark, feedback, description, rating, rate
 * or percentage (ADR 0040, owner decision 2026-11-05): an occurrence list,
 * not an appraisal. A day the generator never ran has no occurrences here
 * (missed days are not back-filled, R-HR13-3; see the generation run report).
 * Whole-School `hr.tasks.view`; a row opens the task, which re-authorizes it.
 */
final class TaskOccurrenceReport implements HrReport
{
    public const string KEY = 'hr.task_occurrences';

    public const int MAX_RANGE_DAYS = 366;

    public function __construct(private readonly TenantContext $context, private readonly HrReportLabels $labels) {}

    public function definition(): D
    {
        return new D(
            key: self::KEY,
            title: 'Staff task occurrences',
            description: 'Every staff task due in the period with its status and whether it is overdue, attributed to the Department and Designation recorded when it was created. No remarks or rates.',
            category: 'tasks',
            owner: 'WorkforceTasks',
            authority: [TaskCapabilities::VIEW],
            route: D::RENDERED,
            basis: D::PERIOD,
            classification: D::EMPLOYMENT_OPERATIONS,
            reference: ['D19-25', 'D20-26', 'D19-24'],
            filters: ['from', 'to', 'status', 'task_category_id', 'frequency', 'department_id', 'position_id', 'staff_type', 'q'],
            columns: [
                new C('due_on', 'Due', 'date'),
                new C('task', 'Task'),
                new C('employee', 'Assigned to', 'employee'),
                new C('category', 'Category'),
                new C('recurrence', 'Recurrence'),
                new C('status', 'Status'),
                new C('department', 'Department (when assigned)'),
                new C('position', 'Designation (when assigned)'),
                new C('staff_type', 'Staff type (when assigned)'),
            ],
            sorts: ['due_asc' => 'Due date, oldest first', 'due_desc' => 'Due date, newest first', 'name' => 'Assigned to'],
            perPage: 25,
            maxRangeDays: self::MAX_RANGE_DAYS,
        );
    }

    public function options(School $school, HrReportViewer $viewer): array
    {
        return $this->context->withSchool($school, fn () => TaskReportScope::options($school, $this->labels));
    }

    public function defaults(School $school, array $options): array
    {
        $today = CarbonImmutable::parse(SchoolTimezone::today($school));

        return ['from' => $today->startOfMonth()->toDateString(), 'to' => $today->endOfMonth()->toDateString()];
    }

    public function page(School $school, HrReportViewer $viewer, array $filters, string $sort, int $page, array $columns): LengthAwarePaginator
    {
        return $this->context->withSchool($school, function () use ($school, $filters, $sort, $page) {
            $query = TaskReportScope::status(TaskReportScope::base($school, $filters), $school, $filters['status'] ?? '');
            match ($sort) {
                'due_desc' => $query->orderByDesc('t.due_on'),
                'name' => HrReportLabels::orderByEmploymentName($query, 't.employment_record_id')->orderBy('t.due_on'),
                default => $query->orderBy('t.due_on'),
            };
            $paginator = $query->orderBy('t.id')->paginate(25, ['t.id', 't.title', 't.employment_record_id', 't.category_id', 't.template_version_id', 't.status', 't.due_on', 't.department_id', 't.position_id', 't.staff_type'], 'page', $page);

            $rows = $paginator->getCollection();
            $today = SchoolTimezone::today($school);
            $employments = $this->labels->employments($school, $rows->pluck('employment_record_id')->map(fn ($id) => (string) $id)->unique()->values()->all());
            $categories = TaskCategory::query()->where('school_id', $school->id)->whereIn('id', $rows->pluck('category_id')->unique()->values())->pluck('label', 'id');
            $frequencies = DB::table('workforce_task_template_versions')->where('school_id', $school->id)->whereIn('id', $rows->pluck('template_version_id')->filter()->unique()->values())->pluck('frequency', 'id');
            $departments = $this->labels->departments($school, $rows->pluck('department_id')->all());
            $positions = $this->labels->positions($school, $rows->pluck('position_id')->all());

            return HrReportRows::map($paginator, function ($t) use ($today, $employments, $categories, $frequencies, $departments, $positions) {
                $overdue = in_array($t->status, WorkforceTask::ASSIGNEE_OPEN_STATUSES, true) && (string) $t->due_on < $today;
                $status = TaskReportScope::STATUS_LABELS[$t->status] ?? $t->status;

                return [
                    'key' => $t->id,
                    'due_on' => (string) $t->due_on,
                    'task' => $t->title,
                    'employee' => $employments[strtolower((string) $t->employment_record_id)]['employee'] ?? null,
                    'category' => $categories[$t->category_id] ?? null,
                    'recurrence' => $t->template_version_id === null ? 'One-off' : (TaskFrequency::tryFrom((string) ($frequencies[$t->template_version_id] ?? ''))?->label()),
                    'status' => $overdue ? $status.' · overdue' : $status,
                    'department' => $t->department_id === null ? null : ($departments[$t->department_id] ?? null),
                    'position' => $t->position_id === null ? null : ($positions[$t->position_id] ?? null),
                    'staff_type' => $t->staff_type === null ? null : StaffType::tryFrom($t->staff_type)?->label(),
                ];
            });
        });
    }

    public function hasAny(School $school, HrReportViewer $viewer): bool
    {
        return $this->context->withSchool($school, fn () => WorkforceTask::query()->where('school_id', $school->id)->exists());
    }
}
