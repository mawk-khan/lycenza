<?php

namespace App\Domain\WorkforceTasks\Application\Reports;

use App\Domain\WorkforceTasks\Application\TaskCapabilities;
use App\Domain\WorkforceTasks\Infrastructure\TaskGenerationRun;
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
 * HR.14: recurring-task generation coverage -- ONE row per School-local
 * calendar day in the range, with that day's run record if one exists
 * (`workforce_task_generation_runs`) and "No run" if none. The generator
 * creates today's occurrences only and never back-fills (R-HR13-3), so a day
 * without a completed run has no recurring occurrences at all: they were
 * never generated, which is neither completed nor missed nor overdue. Counts
 * reconcile per run: examined x occurring templates = created + existing +
 * not matched. Whole-School `hr.tasks.view`.
 */
final class TaskGenerationRunReport implements HrReport
{
    public const string KEY = 'hr.task_generation_runs';

    public const int MAX_RANGE_DAYS = 93;

    public function __construct(private readonly TenantContext $context) {}

    public function definition(): D
    {
        return new D(
            key: self::KEY,
            title: 'Recurring task generation',
            description: 'For each day, whether recurring tasks were generated and with what counts. A day with no run has no recurring tasks: missed days are never back-filled.',
            category: 'tasks',
            owner: 'WorkforceTasks',
            authority: [TaskCapabilities::VIEW],
            route: D::RENDERED,
            basis: D::PERIOD,
            classification: D::EMPLOYMENT_OPERATIONS,
            reference: ['D19-25', 'D19-10'],
            filters: ['from', 'to'],
            columns: [
                new C('date', 'Day', 'date'),
                new C('status', 'Generation'),
                new C('examined', 'Employments examined', 'number'),
                new C('created', 'Tasks created', 'number'),
                new C('existing', 'Already existed', 'number'),
                new C('not_matched', 'Not applicable', 'number'),
                new C('finished_at', 'Finished', 'datetime'),
            ],
            sorts: ['date_desc' => 'Newest first', 'date_asc' => 'Oldest first'],
            perPage: 31,
            maxRangeDays: self::MAX_RANGE_DAYS,
        );
    }

    public function options(School $school, HrReportViewer $viewer): array
    {
        return [];
    }

    public function defaults(School $school, array $options): array
    {
        $today = SchoolTimezone::today($school);

        return ['from' => CarbonImmutable::parse($today)->subDays(13)->toDateString(), 'to' => $today];
    }

    public function page(School $school, HrReportViewer $viewer, array $filters, string $sort, int $page, array $columns): LengthAwarePaginator
    {
        $to = min($filters['to'], SchoolTimezone::today($school));

        return $this->context->withSchool($school, function () use ($school, $filters, $sort, $page, $to) {
            $days = DB::query()->fromRaw('generate_series(?::date, ?::date, interval \'1 day\') AS d(day)', [$filters['from'], $to])
                ->leftJoin('workforce_task_generation_runs as g', fn ($j) => $j->whereRaw('g.occurrence_on = d.day::date')->where('g.school_id', '=', $school->id))
                ->selectRaw('d.day::date AS day, g.status, g.examined, g.created, g.existing, g.not_matched, g.finished_at');
            $paginator = $days->orderBy('day', $sort === 'date_asc' ? 'asc' : 'desc')->paginate(31, ['*'], 'page', $page);

            return HrReportRows::map($paginator, fn ($r) => [
                'key' => (string) $r->day,
                'date' => (string) $r->day,
                'status' => match ($r->status) {
                    null => 'No run',
                    'completed' => 'Completed',
                    default => 'Started, not finished (resumes)',
                },
                'examined' => $r->status === null ? null : (int) $r->examined,
                'created' => $r->status === null ? null : (int) $r->created,
                'existing' => $r->status === null ? null : (int) $r->existing,
                'not_matched' => $r->status === null ? null : (int) $r->not_matched,
                'finished_at' => $r->finished_at === null ? null : CarbonImmutable::parse($r->finished_at)->toIso8601String(),
            ]);
        });
    }

    public function hasAny(School $school, HrReportViewer $viewer): bool
    {
        return $this->context->withSchool($school, fn () => TaskGenerationRun::query()->where('school_id', $school->id)->exists());
    }
}
