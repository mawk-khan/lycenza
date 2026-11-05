<?php

namespace App\Domain\WorkforceTasks\Application\Reports;

use App\Domain\HR\Application\Reports\HrReportLabels;
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
 * HR.14 (D19-25, "Staff Task Report"): task OCCURRENCES due in a period,
 * counted by status per recurring task (one-off tasks per category). Counts
 * of task rows, never of people, never a rate, percentage or score (ADR 0040,
 * owner decision 2026-11-05). Each row reconciles: occurrences = assigned +
 * submitted + returned + completed + cancelled; overdue is a subset of
 * assigned + returned (HR.13's definition). Uses the same filters as the
 * occurrence report (TaskReportScope), so the two agree. Whole-School
 * `hr.tasks.view`.
 */
final class TaskStatusSummaryReport implements HrReport
{
    public const string KEY = 'hr.task_summary';

    public function __construct(private readonly TenantContext $context, private readonly HrReportLabels $labels) {}

    public function definition(): D
    {
        return new D(
            key: self::KEY,
            title: 'Staff task status counts',
            description: 'Task occurrences due in the period, counted by status for each recurring task and for one-off tasks by category. Counts of tasks only; no rates or percentages.',
            category: 'tasks',
            owner: 'WorkforceTasks',
            authority: [TaskCapabilities::VIEW],
            route: D::RENDERED,
            basis: D::PERIOD,
            classification: D::EMPLOYMENT_OPERATIONS,
            reference: ['D19-25', 'D20-26'],
            filters: ['from', 'to', 'task_category_id', 'frequency', 'department_id', 'position_id', 'staff_type'],
            columns: [
                new C('task', 'Recurring task or category'),
                new C('recurrence', 'Recurrence'),
                new C('occurrences', 'Occurrences', 'number'),
                new C('assigned', 'Assigned', 'number'),
                new C('submitted', 'Submitted', 'number'),
                new C('returned', 'Returned', 'number'),
                new C('completed', 'Completed', 'number'),
                new C('cancelled', 'Cancelled', 'number'),
                new C('overdue', 'Overdue', 'number'),
            ],
            sorts: ['task' => 'Task', 'occurrences' => 'Most occurrences'],
            perPage: 50,
            maxRangeDays: TaskOccurrenceReport::MAX_RANGE_DAYS,
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
            $today = SchoolTimezone::today($school);
            $counts = [];
            foreach (array_keys(TaskReportScope::STATUS_LABELS) as $status) {
                $counts[] = "count(*) FILTER (WHERE t.status = '{$status}') AS {$status}";
            }
            $grouped = TaskReportScope::base($school, $filters)
                ->selectRaw("coalesce(t.template_id::text, 'category:' || t.category_id::text) AS bucket, t.template_id, CASE WHEN t.template_id IS NULL THEN t.category_id END AS category_id, count(*) AS occurrences, "
                    .implode(', ', $counts).', count(*) FILTER (WHERE '.TaskReportScope::overdueSql().') AS overdue', [$today])
                ->groupByRaw("coalesce(t.template_id::text, 'category:' || t.category_id::text), t.template_id, CASE WHEN t.template_id IS NULL THEN t.category_id END");
            $query = DB::query()->fromSub($grouped, 'g');
            $sort === 'occurrences' ? $query->orderByDesc('g.occurrences') : $query->orderByRaw('g.template_id IS NULL');
            $paginator = $query->orderBy('g.bucket')->paginate(50, ['g.*'], 'page', $page);

            $rows = $paginator->getCollection();
            $templateIds = $rows->pluck('template_id')->filter()->values()->all();
            $latest = $templateIds === [] ? collect() : DB::table('workforce_task_template_versions as v')->where('v.school_id', $school->id)->whereIn('v.template_id', $templateIds)
                ->orderBy('v.template_id')->orderByDesc('v.version_no')->get(['v.template_id', 'v.title', 'v.frequency'])->unique('template_id')->keyBy('template_id');
            $categories = TaskCategory::query()->where('school_id', $school->id)->whereIn('id', $rows->pluck('category_id')->filter()->values())->pluck('label', 'id');

            return HrReportRows::map($paginator, function ($g) use ($latest, $categories) {
                $v = $g->template_id === null ? null : ($latest[$g->template_id] ?? null);

                return [
                    'key' => $g->bucket,
                    'task' => $g->template_id === null ? 'One-off tasks · '.($categories[$g->category_id] ?? '') : $v?->title,
                    'recurrence' => $v === null ? 'One-off' : TaskFrequency::tryFrom((string) $v->frequency)?->label(),
                    'occurrences' => (int) $g->occurrences,
                    'assigned' => (int) $g->assigned,
                    'submitted' => (int) $g->submitted,
                    'returned' => (int) $g->returned,
                    'completed' => (int) $g->completed,
                    'cancelled' => (int) $g->cancelled,
                    'overdue' => (int) $g->overdue,
                ];
            });
        });
    }

    public function hasAny(School $school, HrReportViewer $viewer): bool
    {
        return $this->context->withSchool($school, fn () => WorkforceTask::query()->where('school_id', $school->id)->exists());
    }
}
