<?php

namespace App\Domain\WorkforceTasks\Application;

use App\Domain\AcademicStructure\Application\AcademicContextReference;
use App\Domain\HR\Application\ActingEmployeeResolver;
use App\Domain\HR\Application\DepartmentReference;
use App\Domain\HR\Application\DutyCandidateReader;
use App\Domain\HR\Application\EmploymentRoster;
use App\Domain\HR\Application\Exceptions\ActingEmployeeUnavailableException;
use App\Domain\HR\Application\PositionReference;
use App\Domain\HR\Application\ReportingLine;
use App\Domain\HR\Application\Workforce\WorkforceAsOfReader;
use App\Domain\WorkforceTasks\Infrastructure\TaskCategory;
use App\Domain\WorkforceTasks\Infrastructure\TaskEvent;
use App\Domain\WorkforceTasks\Infrastructure\TaskTemplate;
use App\Domain\WorkforceTasks\Infrastructure\TaskTemplateVersion;
use App\Domain\WorkforceTasks\Infrastructure\WorkforceTask;
use App\Models\School;
use App\Models\User;
use App\Support\Tenancy\SchoolTimezone;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * HR.13: every staff-task read. Each list is server-filtered and paged
 * (PER_PAGE), and each viewer sees only what their path allows:
 *
 * - mine: the acting employee's CURRENT employment's tasks (hr.tasks.self);
 * - assigned by me: tasks the actor assigned (hr.tasks.manage or .team);
 * - team: tasks of the actor's CURRENT direct reports today (hr.tasks.team;
 *   direct only, never the whole hierarchy);
 * - monitor: the School (hr.tasks.view or .manage), with counts only (no
 *   rates or percentages: ADR 0040).
 *
 * Overdue is derived, never stored: still the assignee's to do (assigned or
 * returned) and due before the School-local today. Attribution (department,
 * position, staff type) is the snapshot taken when the task was created.
 * Labels are directory tier only (name, employee number).
 */
final class TaskReadService
{
    public const int PER_PAGE = 25;

    public const array STATUS_FILTERS = ['open', 'overdue', 'assigned', 'submitted', 'returned', 'completed', 'cancelled', 'all'];

    public function __construct(
        private readonly TenantContext $context,
        private readonly TaskAuthority $authority,
        private readonly EmploymentRoster $roster,
        private readonly ReportingLine $reporting,
        private readonly ActingEmployeeResolver $acting,
        private readonly DepartmentReference $departments,
        private readonly PositionReference $positions,
        private readonly AcademicContextReference $academic,
        private readonly DutyCandidateReader $candidates,
        private readonly WorkforceAsOfReader $workforce,
    ) {}

    /** @return array<string, mixed> */
    public function mine(School $school, User $viewer, string $status, int $page): array
    {
        $this->authority->require($viewer, TaskCapabilities::SELF, $school);
        $employment = $this->authority->actingEmploymentId($viewer, $school);

        return $this->page($school, fn (Builder $q) => $employment === null ? $q->whereRaw('false') : $q->where('employment_record_id', $employment), $status, $page);
    }

    /** @return array<string, mixed> */
    public function assignedByMe(School $school, User $viewer, string $status, int $page): array
    {
        if (! $this->authority->holds($viewer, TaskCapabilities::MANAGE, $school) && ! $this->authority->holds($viewer, TaskCapabilities::TEAM, $school)) {
            $this->authority->require($viewer, TaskCapabilities::MANAGE, $school);
        }

        return $this->page($school, fn (Builder $q) => $q->where('assigned_by_user_id', $viewer->id), $status, $page);
    }

    /** @return array<string, mixed> */
    public function team(School $school, User $viewer, string $status, int $page): array
    {
        $this->authority->require($viewer, TaskCapabilities::TEAM, $school);
        $reports = $this->reportIds($school, $viewer);

        return $this->page($school, fn (Builder $q) => $q->whereIn('employment_record_id', $reports === [] ? ['00000000-0000-0000-0000-000000000000'] : $reports), $status, $page) + ['reportCount' => count($reports)];
    }

    /**
     * @param  array{status?: string, category?: string|null, frequency?: string|null, department?: string|null, position?: string|null, staffType?: string|null}  $filters
     * @return array<string, mixed>
     */
    public function monitor(School $school, User $viewer, array $filters, int $page): array
    {
        if (! $this->authority->holds($viewer, TaskCapabilities::MANAGE, $school)) {
            $this->authority->require($viewer, TaskCapabilities::VIEW, $school);
        }
        $narrow = function (Builder $q) use ($filters): Builder {
            return $q
                ->when($filters['category'] ?? null, fn ($w, $v) => $w->where('category_id', $v))
                ->when($filters['department'] ?? null, fn ($w, $v) => $w->where('department_id', $v))
                ->when($filters['position'] ?? null, fn ($w, $v) => $w->where('position_id', $v))
                ->when($filters['staffType'] ?? null, fn ($w, $v) => $w->where('staff_type', $v))
                ->when($filters['frequency'] ?? null, fn ($w, $v) => $w->whereIn('template_version_id', TaskTemplateVersion::query()->select('id')->where('frequency', $v)));
        };

        return $this->page($school, $narrow, $filters['status'] ?? 'open', $page) + ['counts' => $this->counts($school, $narrow)];
    }

    /** @return array<string, mixed> */
    public function detail(School $school, User $viewer, string $taskId): array
    {
        return $this->context->withSchool($school, function () use ($school, $viewer, $taskId): array {
            $task = WorkforceTask::query()->where('school_id', $school->id)->whereKey($taskId)->first() ?? throw TaskException::notFound();
            $isAssignee = $this->authority->holds($viewer, TaskCapabilities::SELF, $school)
                && $this->authority->actingEmploymentId($viewer, $school) === $task->employment_record_id;
            $path = $this->authority->pathFor($viewer, $school, $task->employment_record_id);
            $own = $this->authority->ownsEmployment($viewer, $school, $task->employment_record_id);
            $visible = $isAssignee || $task->assigned_by_user_id === $viewer->id || ($path !== null && ! $own)
                || $this->authority->holds($viewer, TaskCapabilities::VIEW, $school);
            if (! $visible) {
                throw TaskException::notFound();
            }

            $events = TaskEvent::query()->where('task_id', $task->id)->orderBy('occurred_at')->orderBy('id')->get();
            $names = User::query()->whereIn('id', array_filter([...$events->pluck('actor_user_id')->all(), $task->assigned_by_user_id]))->pluck('name', 'id');
            $actable = $path !== null && ! $own;
            // A former assigner (no current path) still sees the task, never its remarks or feedback.
            $seesNotes = $isAssignee || $actable || $this->authority->holds($viewer, TaskCapabilities::VIEW, $school);
            $row = $this->rows($school, collect([$task]))[0];

            return $row + [
                'version' => $task->version,
                'description' => $task->description,
                'availableOn' => $task->available_on->toDateString(),
                'assignedBy' => $task->assigned_by_user_id === null ? null : ($names[$task->assigned_by_user_id] ?? null),
                'returnCount' => $task->return_count,
                'cancelReason' => $task->cancel_reason === null ? null : TaskCancelReason::from($task->cancel_reason)->label(),
                'reassignedFromTaskId' => $task->reassigned_from_task_id,
                'timeline' => $events->map(fn (TaskEvent $e) => [
                    'id' => $e->id, 'kind' => $e->kind, 'note' => $seesNotes ? $e->note : null, 'occurredAt' => $e->occurred_at->toIso8601String(),
                    'actor' => $e->actor_user_id === null ? null : ($names[$e->actor_user_id] ?? null),
                ])->all(),
                'actions' => [
                    'submit' => $isAssignee && in_array($task->status, WorkforceTask::ASSIGNEE_OPEN_STATUSES, true),
                    'review' => $actable && $task->status === 'submitted',
                    'canReturn' => $actable && $task->status === 'submitted' && $task->return_count === 0,
                    'cancel' => $actable && in_array($task->status, WorkforceTask::OPEN_STATUSES, true),
                    'reassign' => $actable && in_array($task->status, WorkforceTask::OPEN_STATUSES, true),
                ],
                'cancelReasons' => TaskCancelReason::choosable(),
            ];
        });
    }

    /**
     * Who the viewer may assign to, searched server-side and bounded: any
     * current employment (manage, through HR's DutyCandidateReader) or the
     * viewer's current direct reports (team). Never the viewer's own.
     *
     * @return list<array{employmentRecordId: string, label: string}>
     */
    public function assignees(School $school, User $viewer, ?string $term = null): array
    {
        $today = SchoolTimezone::today($school);
        $own = $this->authority->actingEmploymentId($viewer, $school);
        if ($this->authority->holds($viewer, TaskCapabilities::MANAGE, $school)) {
            $people = $this->candidates->search($school, $term, DutyCandidateReader::MAX_LIMIT, matchNumber: true);
            $placements = $this->workforce->placementsOn($school, array_column($people, 'id'), $today);
            $rows = [];
            foreach ($people as $person) {
                $placement = $placements[strtolower($person['id'])] ?? null;
                if ($placement !== null && in_array($placement->employmentStatus, ['active', 'notice_period'], true) && $placement->employmentRecordId !== $own) {
                    $rows[] = ['employmentRecordId' => $placement->employmentRecordId, 'label' => "{$person['fullName']} ({$person['employeeNumber']})"];
                }
            }

            return $rows;
        }
        if (! $this->authority->holds($viewer, TaskCapabilities::TEAM, $school)) {
            return [];
        }

        return array_values(array_map(fn (array $r) => ['employmentRecordId' => $r['employmentRecordId'], 'label' => trim(($r['fullName'] ?? '').' ('.($r['employeeNumber'] ?? '').')')],
            array_filter($this->roster->labels($school, $this->reportIds($school, $viewer)), fn (array $r) => $r['current'] && $r['employeeActive'] && $r['employmentRecordId'] !== $own
                && ($term === null || $term === '' || str_contains(mb_strtolower(($r['fullName'] ?? '').' '.($r['employeeNumber'] ?? '')), mb_strtolower($term))))));
    }

    /** @return array<string, mixed> */
    public function catalogue(School $school, User $viewer): array
    {
        $this->authority->require($viewer, TaskCapabilities::CONFIGURE, $school);

        return $this->context->withSchool($school, function () use ($school): array {
            $categories = TaskCategory::query()->where('school_id', $school->id)->orderByRaw("status = 'retired'")->orderBy('code')->get();
            $versions = TaskTemplateVersion::query()->where('school_id', $school->id)->orderByDesc('version_no')->get()->groupBy('template_id');

            return [
                'categories' => $categories->map(fn (TaskCategory $c) => ['id' => $c->id, 'code' => $c->code, 'label' => $c->label, 'status' => $c->status])->all(),
                'templates' => TaskTemplate::query()->where('school_id', $school->id)->orderByRaw("status = 'retired'")->orderBy('code')->get()
                    ->map(fn (TaskTemplate $t) => ['id' => $t->id, 'code' => $t->code, 'status' => $t->status,
                        'versions' => ($versions[$t->id] ?? collect())->map(fn (TaskTemplateVersion $v) => self::versionRow($v, $categories))->values()->all()])->all(),
                'departments' => array_map(fn (array $d) => ['id' => $d['id'], 'label' => $d['name']], $this->departments->options($school)),
                'positions' => $this->positions->options($school),
            ];
        });
    }

    /** @return array<string, mixed> */
    public function assignmentOptions(School $school, User $viewer): array
    {
        return $this->context->withSchool($school, fn (): array => [
            'categories' => TaskCategory::query()->where('school_id', $school->id)->where('status', 'active')->orderBy('label')->get()
                ->map(fn (TaskCategory $c) => ['id' => $c->id, 'label' => $c->label])->all(),
            'academic' => $this->academic->options($school),
            'today' => SchoolTimezone::today($school),
        ]);
    }

    /** @return list<string> */
    private function reportIds(School $school, User $viewer): array
    {
        try {
            $acting = $this->acting->resolve($viewer, $school);
        } catch (ActingEmployeeUnavailableException) {
            return [];
        }

        return $this->reporting->reportsOf($school, $acting->employeeId, SchoolTimezone::today($school));
    }

    /**
     * @param  callable(Builder): Builder  $scope
     * @return array<string, mixed>
     */
    private function page(School $school, callable $scope, string $status, int $page): array
    {
        return $this->context->withSchool($school, function () use ($school, $scope, $status, $page): array {
            $page = max(1, $page);
            $query = $scope(WorkforceTask::query()->where('school_id', $school->id));
            $today = SchoolTimezone::today($school);
            match (in_array($status, self::STATUS_FILTERS, true) ? $status : 'open') {
                'open' => $query->whereIn('status', WorkforceTask::OPEN_STATUSES),
                'overdue' => $query->whereIn('status', WorkforceTask::ASSIGNEE_OPEN_STATUSES)->where('due_on', '<', $today),
                'all' => $query,
                default => $query->where('status', $status),
            };
            $total = (clone $query)->count();
            /** @var Collection<int, WorkforceTask> $tasks */
            $tasks = $query->orderBy('due_on')->orderBy('id')->offset(($page - 1) * self::PER_PAGE)->limit(self::PER_PAGE)->get();

            return ['rows' => $this->rows($school, $tasks), 'total' => $total, 'page' => $page, 'perPage' => self::PER_PAGE, 'status' => $status];
        });
    }

    /** @return array<string, int> */
    private function counts(School $school, callable $scope): array
    {
        return $this->context->withSchool($school, function () use ($school, $scope): array {
            $base = $scope(WorkforceTask::query()->where('school_id', $school->id));
            $counts = (clone $base)->toBase()->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status')->map(fn ($n) => (int) $n)->all();
            $counts['overdue'] = (clone $base)->whereIn('status', WorkforceTask::ASSIGNEE_OPEN_STATUSES)->where('due_on', '<', SchoolTimezone::today($school))->count();

            return $counts;
        });
    }

    /**
     * @param  Collection<int, WorkforceTask>  $tasks
     * @return list<array<string, mixed>>
     */
    private function rows(School $school, $tasks): array
    {
        if ($tasks->isEmpty()) {
            return [];
        }
        $today = SchoolTimezone::today($school);
        $labels = collect($this->roster->labels($school, $tasks->pluck('employment_record_id')->unique()->values()->all()))->keyBy('employmentRecordId');
        $categories = TaskCategory::query()->whereIn('id', $tasks->pluck('category_id')->unique())->pluck('label', 'id');
        $departments = $this->departments->names($school, $tasks->pluck('department_id')->filter()->unique()->values()->all());
        $positions = $this->positions->names($school, $tasks->pluck('position_id')->filter()->unique()->values()->all());
        $academic = $this->academic->labels($school, $tasks->pluck('section_id')->filter()->unique()->values()->all(), $tasks->pluck('subject_offering_id')->filter()->unique()->values()->all());

        return $tasks->map(fn (WorkforceTask $t) => [
            'id' => $t->id,
            'title' => $t->title,
            'category' => $categories[$t->category_id] ?? null,
            'assignee' => isset($labels[$t->employment_record_id]) ? trim(($labels[$t->employment_record_id]['fullName'] ?? '').' ('.($labels[$t->employment_record_id]['employeeNumber'] ?? '').')') : null,
            'employmentRecordId' => $t->employment_record_id,
            'dueOn' => $t->due_on->toDateString(),
            'status' => $t->status,
            'overdue' => in_array($t->status, WorkforceTask::ASSIGNEE_OPEN_STATUSES, true) && $t->due_on->toDateString() < $today,
            'source' => $t->assignment_path,
            'completionMode' => $t->completion_mode,
            'department' => $t->department_id === null ? null : ($departments[$t->department_id] ?? null),
            'position' => $t->position_id === null ? null : ($positions[$t->position_id] ?? null),
            'staffType' => $t->staff_type,
            'section' => $t->section_id === null ? null : ($academic['sections'][$t->section_id] ?? null),
            'subjectOffering' => $t->subject_offering_id === null ? null : ($academic['subjectOfferings'][$t->subject_offering_id] ?? null),
        ])->values()->all();
    }

    /** @param  Collection<int, TaskCategory>  $categories */
    private static function versionRow(TaskTemplateVersion $v, $categories): array
    {
        return [
            'id' => $v->id, 'versionNo' => $v->version_no, 'title' => $v->title, 'category' => $categories->firstWhere('id', $v->category_id)?->label,
            'completionMode' => $v->completion_mode, 'frequency' => $v->frequency, 'weekdays' => $v->weekdays, 'monthDay' => $v->month_day,
            'lastDay' => $v->last_day, 'yearMonth' => $v->year_month, 'dueDays' => $v->due_days,
            'staffTypes' => array_keys(array_filter(['teaching' => $v->applies_teaching, 'non_teaching' => $v->applies_non_teaching, 'outsourced' => $v->applies_outsourced])),
            'departmentId' => $v->department_id, 'positionId' => $v->position_id, 'effectiveFrom' => $v->effective_from->toDateString(),
        ];
    }
}
