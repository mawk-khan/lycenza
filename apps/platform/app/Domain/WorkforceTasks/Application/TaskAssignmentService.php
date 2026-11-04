<?php

namespace App\Domain\WorkforceTasks\Application;

use App\Domain\HR\Application\EmploymentCoverage;
use App\Domain\HR\Application\Workforce\WorkforceAsOfReader;
use App\Domain\HR\Infrastructure\EmploymentRecord;
use App\Domain\WorkforceTasks\Infrastructure\TaskCategory;
use App\Domain\WorkforceTasks\Infrastructure\TaskEvent;
use App\Domain\WorkforceTasks\Infrastructure\WorkforceTask;
use App\Models\School;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Tenancy\SchoolTimezone;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * HR.13: a one-off task assigned to one CURRENT employment -- by an
 * administrator (hr.tasks.manage, any current employment) or by the
 * assignee's current manager (hr.tasks.team + HR.3A line, never anyone
 * else). The assignee must hold a current (`active` / `notice_period`)
 * employment today (EmploymentCoverage, read FOR SHARE, so a concurrent
 * separation either commits first and refuses this, or waits). Never one's
 * own employment. The assignee's staff type, department, position and Campus
 * today are recorded on the task as its attribution.
 *
 * A reassignment never edits the assignee: it cancels the task (reason
 * `reassigned`) and creates a new one for the new employment with the same
 * terms, linked back, so who was responsible when stays answerable.
 */
final class TaskAssignmentService
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly AuditRecorder $audit,
        private readonly TaskAuthority $authority,
        private readonly EmploymentCoverage $coverage,
        private readonly WorkforceAsOfReader $workforce,
    ) {}

    /**
     * @param  array{title: string, description?: string|null, category_id: string, completion_mode: string, available_on: string, due_on: string, section_id?: string|null, subject_offering_id?: string|null}  $terms
     */
    public function assign(School $school, string $employmentRecordId, array $terms, User $actor): WorkforceTask
    {
        return $this->context->withSchool($school, fn () => DB::transaction(function () use ($school, $employmentRecordId, $terms, $actor): WorkforceTask {
            $path = $this->lockAssignee($school, $employmentRecordId, $actor);
            $task = $this->create($school, $employmentRecordId, self::terms($school, $terms), $path, $actor, null);
            $this->audit->school($school, 'hr.task.assigned', actor: $actor, subject: $task, metadata: ['employmentRecordId' => $employmentRecordId, 'path' => $path, 'dueOn' => $task->due_on->toDateString()]);

            return $task;
        }));
    }

    public function reassign(School $school, string $taskId, int $expectedVersion, string $newEmploymentRecordId, User $actor): WorkforceTask
    {
        return $this->context->withSchool($school, fn () => DB::transaction(function () use ($school, $taskId, $expectedVersion, $newEmploymentRecordId, $actor): WorkforceTask {
            $task = WorkforceTask::query()->where('school_id', $school->id)->whereKey($taskId)->lockForUpdate()->first() ?? throw TaskException::notFound();
            if ($this->authority->pathFor($actor, $school, $task->employment_record_id, lock: true) === null) {
                throw TaskException::notFound();
            }
            if ($this->authority->ownsEmployment($actor, $school, $task->employment_record_id)) {
                throw TaskException::forbidden('TASK_SELF_ADMINISTRATION', 'You cannot reassign your own task.');
            }
            if ($task->version !== $expectedVersion) {
                throw TaskException::stale();
            }
            if (! in_array($task->status, WorkforceTask::OPEN_STATUSES, true)) {
                throw TaskException::conflict('TASK_NOT_OPEN', 'Only an open task can be reassigned.');
            }
            if ($newEmploymentRecordId === $task->employment_record_id) {
                throw TaskException::invalid('TASK_REASSIGN_SAME', 'Choose someone else.');
            }
            $path = $this->lockAssignee($school, $newEmploymentRecordId, $actor);

            $task->forceFill(['status' => 'cancelled', 'cancelled_by_user_id' => $actor->id, 'cancelled_at' => now(), 'cancel_reason' => TaskCancelReason::REASSIGNED->value, 'version' => $task->version + 1])->save();
            TaskEvent::query()->create(['task_id' => $task->id, 'kind' => 'reassigned', 'actor_user_id' => $actor->id, 'occurred_at' => now()]);

            $today = SchoolTimezone::today($school);
            $new = $this->create($school, $newEmploymentRecordId, [
                'title' => $task->title, 'description' => $task->description, 'category_id' => $task->category_id, 'completion_mode' => $task->completion_mode,
                'available_on' => max($today, $task->available_on->toDateString()), 'due_on' => max($today, $task->due_on->toDateString()),
                'section_id' => $task->section_id, 'subject_offering_id' => $task->subject_offering_id,
            ], $path, $actor, $task->id);
            $this->audit->school($school, 'hr.task.reassigned', actor: $actor, subject: $new, metadata: ['fromTaskId' => $task->id, 'fromEmploymentRecordId' => $task->employment_record_id, 'toEmploymentRecordId' => $newEmploymentRecordId, 'path' => $path]);

            return $new;
        }));
    }

    /** The path the actor assigns through, after locking the assignee's current employment. */
    private function lockAssignee(School $school, string $employmentRecordId, User $actor): string
    {
        $exists = EmploymentRecord::query()->where('school_id', $school->id)->whereKey($employmentRecordId)->exists();
        $path = $exists ? $this->authority->pathFor($actor, $school, $employmentRecordId, lock: true) : null;
        if ($path === null) {
            // Not a manageable employment of this School: the same "not found" either way.
            throw TaskException::notFound();
        }
        if ($this->authority->ownsEmployment($actor, $school, $employmentRecordId)) {
            throw TaskException::forbidden('TASK_SELF_ADMINISTRATION', 'You cannot assign a task to yourself.');
        }
        if ($this->coverage->holdRecordAttendable($school, $employmentRecordId, SchoolTimezone::today($school)) !== EmploymentCoverage::COVERED) {
            throw TaskException::conflict('TASK_ASSIGNEE_NOT_CURRENT', 'Tasks are assigned only to someone currently employed.');
        }

        return $path;
    }

    /** @param  array<string, mixed>  $terms */
    private function create(School $school, string $employmentRecordId, array $terms, string $path, User $actor, ?string $fromTaskId): WorkforceTask
    {
        $employeeId = (string) EmploymentRecord::query()->whereKey($employmentRecordId)->value('employee_id');
        $placement = $this->workforce->placementOn($school, $employeeId, SchoolTimezone::today($school));

        try {
            $task = DB::transaction(fn () => WorkforceTask::query()->create([...$terms,
                'employment_record_id' => $employmentRecordId, 'assignment_path' => $path, 'assigned_by_user_id' => $actor->id,
                'staff_type' => $placement?->staffType, 'department_id' => $placement?->departmentId, 'position_id' => $placement?->positionId,
                'campus_id' => $placement?->campusId, 'reassigned_from_task_id' => $fromTaskId,
            ]));
        } catch (QueryException $e) {
            if (str_contains($e->getMessage(), 'foreign key')) {
                throw TaskException::invalid('TASK_REFERENCE_INVALID', 'Choose a category, section and subject offering of this School.');
            }
            throw $e;
        }
        TaskEvent::query()->create(['task_id' => $task->id, 'kind' => 'assigned', 'actor_user_id' => $actor->id, 'occurred_at' => now()]);

        return $task->refresh();
    }

    /**
     * @param  array<string, mixed>  $terms
     * @return array<string, mixed>
     */
    private static function terms(School $school, array $terms): array
    {
        $today = SchoolTimezone::today($school);
        $available = self::date((string) ($terms['available_on'] ?? $today));
        $due = self::date((string) ($terms['due_on'] ?? ''));
        if ($available < $today) {
            throw TaskException::invalid('TASK_AVAILABLE_PAST', 'A task starts today or later.');
        }
        if ($due < $available || CarbonImmutable::parse($available)->diffInDays(CarbonImmutable::parse($due)) > 366) {
            throw TaskException::invalid('TASK_DUE_INVALID', 'The due date is on or after the start date, within a year.');
        }
        if (! in_array($terms['completion_mode'] ?? null, ['self', 'reviewed'], true)) {
            throw TaskException::invalid('TASK_MODE', 'Choose how the task is completed.');
        }
        $category = TaskCategory::query()->where('school_id', $school->id)->whereKey((string) ($terms['category_id'] ?? ''))->first();
        if ($category === null || $category->status !== 'active') {
            throw TaskException::invalid('TASK_CATEGORY_UNAVAILABLE', 'Choose an active category.');
        }

        return [
            'title' => TaskCatalogueService::text((string) ($terms['title'] ?? ''), 120, 'TASK_TITLE', 'Enter a title of at most 120 characters.'),
            'description' => ($terms['description'] ?? null) === null || trim((string) $terms['description']) === '' ? null
                : TaskCatalogueService::text((string) $terms['description'], 1000, 'TASK_DESCRIPTION', 'The description is at most 1000 characters.'),
            'category_id' => $category->id,
            'completion_mode' => $terms['completion_mode'],
            'available_on' => $available,
            'due_on' => $due,
            'section_id' => ($terms['section_id'] ?? null) ?: null,
            'subject_offering_id' => ($terms['subject_offering_id'] ?? null) ?: null,
        ];
    }

    private static function date(string $value): string
    {
        $date = CarbonImmutable::createFromFormat('!Y-m-d', $value);
        if ($date === null || $date->toDateString() !== $value) {
            throw TaskException::invalid('TASK_DATE', 'Enter a valid date.');
        }

        return $value;
    }
}
