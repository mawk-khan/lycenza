<?php

namespace App\Domain\WorkforceTasks\Application;

use App\Domain\WorkforceTasks\Infrastructure\TaskEvent;
use App\Domain\WorkforceTasks\Infrastructure\WorkforceTask;
use App\Models\School;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Tenancy\SchoolOperationalGuard;
use App\Support\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
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
        private readonly SchoolOperationalGuard $operational,
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
            $task = $this->lockReviewable($school, $taskId, $actor);
            $this->assertVersion($task, $expectedVersion);
            if ($task->status !== 'submitted') {
                throw TaskException::conflict('TASK_NOT_SUBMITTED', 'Only a submitted task is reviewed.');
            }
            if ($decision === 'return' && $task->return_count >= 1) {
                throw TaskException::conflict('TASK_ALREADY_RETURNED', 'This task was already returned once; complete or cancel it.');
            }

            try {
                $task->forceFill($decision === 'complete'
                    ? ['status' => 'completed', 'completed_by_user_id' => $actor->id, 'completed_at' => now(), 'version' => $task->version + 1]
                    : ['status' => 'returned', 'return_count' => $task->return_count + 1, 'version' => $task->version + 1])->save();
                TaskEvent::query()->create(['task_id' => $task->id, 'kind' => $decision === 'complete' ? 'completed' : 'returned', 'actor_user_id' => $actor->id, 'note' => $feedback, 'occurred_at' => now()]);
            } catch (QueryException $e) {
                // WO-22: the database's own refusal of a reviewer known to be the assignee (a link that changed after the read).
                // The database's own message (review L4: never Laravel's, which carries the bound feedback text).
                if ($e->getCode() === '23514' && str_contains((string) ($e->errorInfo[2] ?? ''), 'hr_workforce_task_review_same_person')) {
                    throw self::samePerson();
                }
                throw $e;
            }
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

    /**
     * HR.R1-C35 WO-22 (TRR-6, D-C35-5; ApprovalWorkflowRegistry `workforce_task_reviews`): a review is a second
     * person's decision. Under the task lock it takes the reviewer's CURRENT authority (TaskAuthority::currentPathFor,
     * fresh, held to commit) -- not the cached answer -- and refuses one's own task (the current link, as before),
     * a reviewer KNOWN to be the assignee (rule 152: an uncorrected link of the reviewer or of an account known to
     * be the same person) and the account that submitted it or one known to be the same person (review L1); the
     * database backs both (`hr_workforce_task_review_same_person`).
     */
    private function lockReviewable(School $school, string $taskId, User $actor): WorkforceTask
    {
        $task = $this->lock($school, $taskId);
        if ($this->authority->currentPathFor($actor, $school, $task->employment_record_id) === null) {
            // Never on any path: the private "not found", as before. On one only by the cached answer: the
            // authority is gone (revoked, suspended, disabled, module off) -- refused.
            if ($this->authority->pathFor($actor, $school, $task->employment_record_id) === null) {
                throw TaskException::notFound();
            }
            throw new AuthorizationException('This action is unauthorized.');
        }
        if ($this->authority->ownsEmployment($actor, $school, $task->employment_record_id)) {
            throw TaskException::forbidden('TASK_SELF_ADMINISTRATION', 'You cannot review your own task.');
        }
        $submitters = TaskEvent::query()->where('task_id', $task->id)->where('kind', 'submitted')->distinct()->pluck('actor_user_id')->filter()->map(fn ($id) => (string) $id)->values()->all();
        if ($this->authority->isKnownToBeAssignee($actor, $school, $task->employment_record_id)
            || $this->authority->isKnownToBeSubmitter($actor, $school, $submitters)) {
            throw self::samePerson();
        }

        return $task;
    }

    private static function samePerson(): TaskException
    {
        return TaskException::forbidden('TASK_SAME_PERSON_REVIEW', 'One of your accounts is known to belong to the person this task is assigned to; another person must review it.');
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
     * HR.R1-C13 (C12-N1, rule 86): every path, the administrative one included, asks the School first.
     *
     * @template T
     *
     * @param  callable(): T  $work
     * @return T
     */
    private function inSchool(School $school, callable $work): mixed
    {
        return $this->context->withSchool($school, fn () => DB::transaction(function () use ($school, $work): mixed {
            $this->operational->requireOperational($school->id);

            return $work();
        }));
    }
}
