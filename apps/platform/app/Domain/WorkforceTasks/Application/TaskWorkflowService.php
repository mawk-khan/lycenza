<?php

namespace App\Domain\WorkforceTasks\Application;

use App\Domain\WorkforceTasks\Infrastructure\TaskEvent;
use App\Domain\WorkforceTasks\Infrastructure\WorkforceTask;
use App\Models\School;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * HR.13 (owner decision 2026-11-04, "per template: self or reviewed"): the
 * task lifecycle.
 *
 *   assigned | returned --submit-->  completed (self) | submitted (reviewed)
 *   submitted --review: complete--> completed
 *             --review: return-->   returned (once; then it must be completed or cancelled)
 *   any open --cancel--> cancelled (closed reason)
 *
 * Only the assignee submits (hr.tasks.self + acting employee = the task's
 * employment). Review and cancel need the administrative path or the current
 * manager path (TaskAuthority) and never one's own task; the database backs
 * the graph and "never the assignee" (trg_workforce_tasks_guard). Remarks and
 * feedback are bounded plain text kept on the timeline, never a rating
 * (OD-HR-7) and never a health, disciplinary or student record.
 */
final class TaskWorkflowService
{
    public const int NOTE_MAX = 500;

    public function __construct(
        private readonly TenantContext $context,
        private readonly AuditRecorder $audit,
        private readonly TaskAuthority $authority,
    ) {}

    public function submit(School $school, string $taskId, int $expectedVersion, ?string $remarks, User $actor): WorkforceTask
    {
        $this->authority->require($actor, TaskCapabilities::SELF, $school);
        $remarks = self::note($remarks);

        return $this->inSchool($school, function () use ($school, $taskId, $expectedVersion, $remarks, $actor): WorkforceTask {
            $task = $this->lock($school, $taskId);
            if ($this->authority->actingEmploymentId($actor, $school, lock: true) !== $task->employment_record_id) {
                throw TaskException::notFound();
            }
            $this->assertVersion($task, $expectedVersion);
            if (! in_array($task->status, WorkforceTask::ASSIGNEE_OPEN_STATUSES, true)) {
                throw TaskException::conflict('TASK_NOT_SUBMITTABLE', 'This task is not waiting for you.');
            }

            $self = $task->completion_mode === 'self';
            $task->forceFill($self
                ? ['status' => 'completed', 'completed_by_user_id' => $actor->id, 'completed_at' => now(), 'version' => $task->version + 1]
                : ['status' => 'submitted', 'version' => $task->version + 1])->save();
            TaskEvent::query()->create(['task_id' => $task->id, 'kind' => $self ? 'completed' : 'submitted', 'actor_user_id' => $actor->id, 'note' => $remarks, 'occurred_at' => now()]);
            $this->audit->school($school, $self ? 'hr.task.completed' : 'hr.task.submitted', actor: $actor, subject: $task, metadata: ['hasRemarks' => $remarks !== null]);

            return $task->refresh();
        });
    }

    /** @param  string  $decision  complete | return */
    public function review(School $school, string $taskId, int $expectedVersion, string $decision, ?string $feedback, User $actor): WorkforceTask
    {
        if (! in_array($decision, ['complete', 'return'], true)) {
            throw TaskException::invalid('TASK_DECISION', 'Choose to complete or return the task.');
        }
        $feedback = self::note($feedback);
        if ($decision === 'return' && $feedback === null) {
            throw TaskException::invalid('TASK_FEEDBACK_REQUIRED', 'Say what needs to change before returning the task.');
        }

        return $this->inSchool($school, function () use ($school, $taskId, $expectedVersion, $decision, $feedback, $actor): WorkforceTask {
            $task = $this->lockActable($school, $taskId, $actor, 'review');
            $this->assertVersion($task, $expectedVersion);
            if ($task->status !== 'submitted') {
                throw TaskException::conflict('TASK_NOT_SUBMITTED', 'Only a submitted task is reviewed.');
            }
            if ($decision === 'return' && $task->return_count >= 1) {
                throw TaskException::conflict('TASK_ALREADY_RETURNED', 'This task was already returned once; complete or cancel it.');
            }

            $task->forceFill($decision === 'complete'
                ? ['status' => 'completed', 'completed_by_user_id' => $actor->id, 'completed_at' => now(), 'version' => $task->version + 1]
                : ['status' => 'returned', 'return_count' => $task->return_count + 1, 'version' => $task->version + 1])->save();
            TaskEvent::query()->create(['task_id' => $task->id, 'kind' => $decision === 'complete' ? 'completed' : 'returned', 'actor_user_id' => $actor->id, 'note' => $feedback, 'occurred_at' => now()]);
            $this->audit->school($school, $decision === 'complete' ? 'hr.task.completed' : 'hr.task.returned', actor: $actor, subject: $task, metadata: ['hasFeedback' => $feedback !== null]);

            return $task->refresh();
        });
    }

    public function cancel(School $school, string $taskId, int $expectedVersion, TaskCancelReason $reason, User $actor): WorkforceTask
    {
        if ($reason === TaskCancelReason::REASSIGNED) {
            throw TaskException::invalid('TASK_CANCEL_REASON', 'Use reassignment to move a task to someone else.');
        }

        return $this->inSchool($school, function () use ($school, $taskId, $expectedVersion, $reason, $actor): WorkforceTask {
            $task = $this->lockActable($school, $taskId, $actor, 'cancel');
            $this->assertVersion($task, $expectedVersion);
            if (! in_array($task->status, WorkforceTask::OPEN_STATUSES, true)) {
                throw TaskException::conflict('TASK_NOT_OPEN', 'Only an open task can be cancelled.');
            }
            $task->forceFill(['status' => 'cancelled', 'cancelled_by_user_id' => $actor->id, 'cancelled_at' => now(), 'cancel_reason' => $reason->value, 'version' => $task->version + 1])->save();
            TaskEvent::query()->create(['task_id' => $task->id, 'kind' => 'cancelled', 'actor_user_id' => $actor->id, 'occurred_at' => now()]);
            $this->audit->school($school, 'hr.task.cancelled', actor: $actor, subject: $task, metadata: ['reason' => $reason->value]);

            return $task->refresh();
        });
    }

    private function lock(School $school, string $taskId): WorkforceTask
    {
        return WorkforceTask::query()->where('school_id', $school->id)->whereKey($taskId)->lockForUpdate()->first() ?? throw TaskException::notFound();
    }

    private function lockActable(School $school, string $taskId, User $actor, string $operation): WorkforceTask
    {
        $task = $this->lock($school, $taskId);
        if ($this->authority->pathFor($actor, $school, $task->employment_record_id, lock: true) === null) {
            throw TaskException::notFound();
        }
        if ($this->authority->ownsEmployment($actor, $school, $task->employment_record_id)) {
            throw TaskException::forbidden('TASK_SELF_ADMINISTRATION', "You cannot {$operation} your own task.");
        }

        return $task;
    }

    private function assertVersion(WorkforceTask $task, int $expected): void
    {
        if ($task->version !== $expected) {
            throw TaskException::stale();
        }
    }

    private static function note(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }
        $value = trim($value);
        if (mb_strlen($value) > self::NOTE_MAX) {
            throw TaskException::invalid('TASK_NOTE_TOO_LONG', 'Keep it to '.self::NOTE_MAX.' characters.');
        }

        return $value;
    }

    /**
     * @template T
     *
     * @param  callable(): T  $work
     * @return T
     */
    private function inSchool(School $school, callable $work): mixed
    {
        return $this->context->withSchool($school, fn () => DB::transaction($work));
    }
}
