<?php

namespace Tests\Feature\WorkforceTasks;

use App\Domain\HR\Application\EmployeeService;
use App\Domain\HR\Infrastructure\EmploymentRecord;
use App\Domain\WorkforceTasks\Application\TaskAssignmentService;
use App\Domain\WorkforceTasks\Application\TaskCancelReason;
use App\Domain\WorkforceTasks\Application\TaskCatalogueService;
use App\Domain\WorkforceTasks\Application\TaskWorkflowService;
use App\Domain\WorkforceTasks\Infrastructure\TaskCategory;
use App\Domain\WorkforceTasks\Infrastructure\WorkforceTask;
use App\Models\School;
use App\Models\User;
use App\Support\Operations\CheckResult;
use App\Support\Operations\DatabaseRoleVerifier;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * HR.R1-C35 WO-23 (WO22-N1, RF1-N1; owner decisions D-RF2-1, D-RF2-1a; rule 102, ADR 0076 §64).
 *
 * A staff task's `submitted` and `returned` states are evidenced only by their timeline event, which names the
 * task version it documents (`workforce_task_events.task_version`). As the runtime role (`school_os_app`, the
 * default connection), under the School's context:
 * - a change of status to `submitted` or `returned` with no event for that exact version is refused AT COMMIT
 *   (`trg_workforce_tasks_transition_evidence`, DEFERRABLE INITIALLY DEFERRED); the UPDATE itself is accepted,
 *   so the writers' order (task first, event after) keeps working;
 * - an event of either kind is refused unless the task is in that state at that version right now
 *   (`hr_workforce_task_events_guard`), so an event written first, for another version, another task, another
 *   kind or a past transition is refused;
 * - one event per version (`workforce_task_events_one_per_version`); the version only on those two kinds.
 *
 * Each test runs inside the test framework's outer transaction, so the deferred check would only fire at a commit
 * that never comes: `SET CONSTRAINTS ALL IMMEDIATE` fires the pending checks inside the operation. Real commits
 * and two processes: WorkforceTaskTransitionEvidenceCommitTest.
 *
 * Reproduced first at 33166cd3 (these tests, before the migration): the raw return and the raw submission with no
 * event both committed (tests 1 and 2 failed), and so did every other negative case below.
 */
class WorkforceTaskTransitionEvidenceTest extends TestCase
{
    use CreatesTenancyFixtures;

    private const string EVIDENCE = 'hr_workforce_task_transition_evidence';

    private const string EVENT = 'hr_workforce_task_event_transition';

    private School $school;

    private User $hr;

    private User $assignee;

    private User $reviewer;

    private TaskCategory $category;

    private EmploymentRecord $record;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-06-15 10:00:00');
        $this->school = $this->createSchool();
        app(TenantContext::class)->set($this->school);
        $this->hr = $this->createUserWithCapabilities($this->school, ['hr.employees.assignments.manage', 'hr.employees.manage', 'hr.employees.view', 'hr.tasks.view', 'hr.tasks.configure', 'hr.tasks.manage']);
        $this->reviewer = $this->createUserWithCapabilities($this->school, ['hr.tasks.view', 'hr.tasks.manage']);
        $this->assignee = $this->createUserWithCapabilities($this->school, ['hr.tasks.self']);
        $this->category = app(TaskCatalogueService::class)->createCategory($this->school, 'admin', 'Administrative', $this->hr);
        $employee = $this->createEmployee($this->school, ['user_id' => null]);
        app(EmployeeService::class)->linkUser($employee, $this->assignee->id, $this->hr);
        $this->record = $this->createEmploymentRecord($employee, ['starts_on' => '2024-01-01', 'status' => 'active', 'staff_type' => 'teaching']);
        $this->createEmployeeAssignment($this->record, $this->createPosition($this->school), ['starts_on' => '2024-01-01', 'ends_on' => null, 'is_primary' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function assigned(string $mode = 'reviewed'): WorkforceTask
    {
        return app(TaskAssignmentService::class)->assign($this->school, $this->record->id, ['title' => 'Prepare the notice board', 'category_id' => $this->category->id,
            'completion_mode' => $mode, 'available_on' => '2026-06-15', 'due_on' => '2026-06-20'], $this->hr);
    }

    private function submitted(): WorkforceTask
    {
        $task = $this->assigned();

        return app(TaskWorkflowService::class)->submit($this->school, $task->id, $task->version, null, $this->assignee);
    }

    /**
     * Runs $write in a transaction whose pending evidence checks fire before it ends: "ok", or the refusal's marker.
     * The mode is set back to DEFERRED afterwards: it lasts for the whole outer transaction, and an IMMEDIATE check
     * would refuse the writers' own task-first order (which is why the trigger is deferred).
     */
    private function commit(callable $write): string
    {
        try {
            DB::transaction(function () use ($write): void {
                $write();
                DB::statement('SET CONSTRAINTS trg_workforce_tasks_transition_evidence IMMEDIATE');
            });

            return 'ok';
        } catch (QueryException $e) {
            if (preg_match('/\((hr_[a-z_]+)\)/', $e->getMessage(), $m) === 1) {
                return $m[1];
            }

            return match ((string) $e->getCode()) {
                '23505' => 'unique', '23514' => 'check', '23503' => 'foreign_key', '42501' => 'denied',
                default => substr($e->getMessage(), 0, 200),
            };
        } finally {
            DB::statement('SET CONSTRAINTS trg_workforce_tasks_transition_evidence DEFERRED');
        }
    }

    private function move(string $taskId, string $status): void
    {
        DB::table('workforce_tasks')->where('id', $taskId)->update(['status' => $status, 'version' => DB::raw('version + 1'),
            'return_count' => $status === 'returned' ? DB::raw('return_count + 1') : DB::raw('return_count')]);
    }

    private function event(string $taskId, string $kind, ?int $version, ?User $actor = null, ?string $schoolId = null): void
    {
        DB::table('workforce_task_events')->insert(['id' => (string) Str::uuid7(), 'school_id' => $schoolId ?? $this->school->id, 'task_id' => $taskId,
            'kind' => $kind, 'task_version' => $version, 'actor_user_id' => ($actor ?? $this->reviewer)->id, 'note' => null, 'occurred_at' => now()]);
    }

    /** @return array{0: string, 1: int, 2: int} status, version, return_count */
    private function state(string $taskId): array
    {
        $row = DB::table('workforce_tasks')->where('id', $taskId)->first(['status', 'version', 'return_count']);

        return [(string) $row->status, (int) $row->version, (int) $row->return_count];
    }

    /** @return list<array{0: string, 1: int|null}> kind and version of every event, in order */
    private function events(string $taskId): array
    {
        return DB::table('workforce_task_events')->where('task_id', $taskId)->orderBy('occurred_at')->orderBy('id')->get(['kind', 'task_version'])
            ->map(fn ($e) => [(string) $e->kind, $e->task_version === null ? null : (int) $e->task_version])->all();
    }

    #[Test]
    public function a_raw_return_with_no_event_is_refused_at_commit_and_the_task_stays_submitted(): void
    {
        $task = $this->submitted();

        $this->assertSame(self::EVIDENCE, $this->commit(fn () => $this->move($task->id, 'returned')));
        $this->assertSame(['submitted', 2, 0], $this->state($task->id));
        $this->assertSame([['assigned', null], ['submitted', 2]], $this->events($task->id));
    }

    #[Test]
    public function a_raw_submission_with_no_event_is_refused_at_commit(): void
    {
        $task = $this->assigned();

        $this->assertSame(self::EVIDENCE, $this->commit(fn () => $this->move($task->id, 'submitted')));
        $this->assertSame(['assigned', 1, 0], $this->state($task->id));
    }

    #[Test]
    public function the_check_runs_at_commit_so_the_update_alone_is_accepted(): void
    {
        $task = $this->submitted();
        $outcome = $this->commit(function () use ($task): void {
            $this->move($task->id, 'returned'); // accepted: nothing refuses the statement itself
            $this->assertSame('returned', $this->state($task->id)[0]);
        });

        $this->assertSame(self::EVIDENCE, $outcome, 'refused only when the pending check runs');
    }

    #[Test]
    public function the_canonical_submission_and_return_commit_with_versioned_events(): void
    {
        $task = $this->submitted();
        $this->assertSame('ok', $this->commit(fn () => null), 'the canonical submission satisfies its pending check');
        $returned = app(TaskWorkflowService::class)->review($this->school, $task->id, $task->version, 'return', 'Add the dates.', $this->reviewer);
        $this->assertSame('ok', $this->commit(fn () => null));
        $resubmitted = app(TaskWorkflowService::class)->submit($this->school, $task->id, $returned->version, 'Dates added.', $this->assignee);
        $done = app(TaskWorkflowService::class)->review($this->school, $task->id, $resubmitted->version, 'complete', null, $this->reviewer);

        $this->assertSame('ok', $this->commit(fn () => null));
        $this->assertSame(['completed', 5, 1], $this->state($task->id));
        $this->assertSame([['assigned', null], ['submitted', 2], ['returned', 3], ['submitted', 4], ['completed', null]], $this->events($task->id));
        $this->assertSame($done->completed_by_user_id, $this->reviewer->id);
    }

    #[Test]
    public function a_self_completion_and_a_cancellation_carry_no_version_and_need_none(): void
    {
        $self = $this->assigned('self');
        app(TaskWorkflowService::class)->submit($this->school, $self->id, $self->version, null, $this->assignee);
        $this->assertSame([['assigned', null], ['completed', null]], $this->events($self->id));

        $this->assertSame('check', $this->commit(fn () => DB::table('workforce_task_events')->insert(['id' => (string) Str::uuid7(), 'school_id' => $this->school->id,
            'task_id' => $self->id, 'kind' => 'completed', 'task_version' => 2, 'actor_user_id' => $this->reviewer->id, 'occurred_at' => now()])), 'a version only on a submission or return');
        $this->assertSame('ok', $this->commit(fn () => null));
    }

    #[Test]
    public function a_raw_transition_with_its_matching_event_is_accepted(): void
    {
        $task = $this->submitted();

        $this->assertSame('ok', $this->commit(function () use ($task): void {
            $this->move($task->id, 'returned');
            $this->event($task->id, 'returned', 3);
        }), 'the positive control: the same evidence the service writes');
        $this->assertSame(['returned', 3, 1], $this->state($task->id));
    }

    #[Test]
    public function an_event_written_before_its_transition_is_refused(): void
    {
        $task = $this->submitted();

        $this->assertSame(self::EVENT, $this->commit(function () use ($task): void {
            $this->event($task->id, 'returned', 3);
            $this->move($task->id, 'returned');
        }));
        $this->assertSame(['submitted', 2, 0], $this->state($task->id));
    }

    #[Test]
    public function an_event_for_another_version_never_documents_the_transition(): void
    {
        $task = $this->submitted();

        foreach ([2, 4, null] as $version) {
            $this->assertSame(self::EVENT, $this->commit(function () use ($task, $version): void {
                $this->move($task->id, 'returned');
                $this->event($task->id, 'returned', $version);
            }), 'version '.var_export($version, true));
        }
        $this->assertSame(['submitted', 2, 0], $this->state($task->id));
    }

    #[Test]
    public function an_event_of_another_kind_never_documents_the_transition(): void
    {
        $task = $this->submitted();

        $this->assertSame(self::EVENT, $this->commit(function () use ($task): void {
            $this->move($task->id, 'returned');
            $this->event($task->id, 'submitted', 3);
        }));
        $this->assertSame(self::EVIDENCE, $this->commit(function () use ($task): void {
            $this->move($task->id, 'returned');
            DB::table('workforce_task_events')->insert(['id' => (string) Str::uuid7(), 'school_id' => $this->school->id, 'task_id' => $task->id,
                'kind' => 'cancelled', 'actor_user_id' => $this->reviewer->id, 'occurred_at' => now()]); // a generic timeline entry
        }));
        $this->assertSame(['submitted', 2, 0], $this->state($task->id));
    }

    #[Test]
    public function another_tasks_event_never_documents_this_one(): void
    {
        $a = $this->assigned();
        $b = $this->assigned();

        $this->assertSame(self::EVIDENCE, $this->commit(function () use ($a, $b): void {
            $this->move($a->id, 'submitted');
            $this->move($b->id, 'submitted');
            $this->event($b->id, 'submitted', 2); // the same version number, the other task
        }));
        $this->assertSame([['assigned', 1, 0], ['assigned', 1, 0]], [$this->state($a->id), $this->state($b->id)]);
    }

    #[Test]
    public function another_schools_event_is_refused_and_never_documents_the_transition(): void
    {
        $task = $this->submitted();
        $other = $this->createSchool();

        // The event guard runs before RLS's WITH CHECK: the task is not in that School.
        $this->assertSame(self::EVENT, $this->commit(function () use ($task, $other): void {
            $this->move($task->id, 'returned');
            $this->event($task->id, 'returned', 3, schoolId: $other->id);
        }));
        $this->assertSame(['submitted', 2, 0], $this->state($task->id));

        // Rule 28, raw layer: under School B's context, School A's task can be neither moved nor documented.
        $context = app(TenantContext::class);
        $context->set($other);
        try {
            $this->assertSame(0, DB::table('workforce_tasks')->where('id', $task->id)->update(['status' => 'returned', 'version' => 3, 'return_count' => 1]));
            $this->assertSame(self::EVENT, $this->commit(fn () => $this->event($task->id, 'returned', 3)), 'an event naming School A');
            $this->assertSame(self::EVENT, $this->commit(fn () => $this->event($task->id, 'returned', 3, schoolId: $other->id)), 'a School B event: the guard cannot see A\'s task, before RLS would refuse it');
        } finally {
            $context->set($this->school);
        }
        $this->assertSame(['submitted', 2, 0], $this->state($task->id));
    }

    #[Test]
    public function an_earlier_events_version_never_satisfies_a_later_transition(): void
    {
        $task = $this->submitted();
        $returned = app(TaskWorkflowService::class)->review($this->school, $task->id, $task->version, 'return', 'Add the dates.', $this->reviewer);
        $this->assertSame(3, $returned->version);

        // A resubmission (v4) with no event: the earlier `submitted` event (v2) is not its evidence.
        $this->assertSame(self::EVIDENCE, $this->commit(fn () => $this->move($task->id, 'submitted')));
        $this->assertSame(['returned', 3, 1], $this->state($task->id));
        // Nor can a past transition be documented afterwards: the task is no longer `submitted` at v2.
        $this->assertSame(self::EVENT, $this->commit(fn () => $this->event($task->id, 'submitted', 2)), 'v2 is past (and already has its event)');
        $this->assertSame(self::EVENT, $this->commit(fn () => $this->event($task->id, 'returned', 2)));
    }

    #[Test]
    public function one_event_per_version(): void
    {
        $task = $this->submitted();

        $this->assertSame('unique', $this->commit(fn () => $this->event($task->id, 'submitted', 2)));
        $this->assertSame([['assigned', null], ['submitted', 2]], $this->events($task->id));
    }

    #[Test]
    public function two_transitions_in_one_transaction_each_need_their_own_event(): void
    {
        $task = $this->assigned();

        $this->assertSame(self::EVIDENCE, $this->commit(function () use ($task): void {
            $this->move($task->id, 'submitted');            // v2, undocumented
            $this->move($task->id, 'returned');             // v3
            $this->event($task->id, 'returned', 3);         // documents v3 only
        }), 'the undocumented intermediate submission is refused');
        $this->assertSame(['assigned', 1, 0], $this->state($task->id));

        $this->assertSame('ok', $this->commit(function () use ($task): void {
            $this->move($task->id, 'submitted');
            $this->event($task->id, 'submitted', 2, $this->assignee);
            $this->move($task->id, 'returned');
            $this->event($task->id, 'returned', 3);
        }), 'both documented');
    }

    #[Test]
    public function a_task_the_commit_cannot_see_is_refused_rather_than_passed(): void
    {
        $task = $this->submitted();
        $context = app(TenantContext::class);
        $context->clear();

        try {
            // A caller wrapping the service in an outer transaction that commits after the context is restored.
            $outcome = $this->commit(fn () => app(TaskWorkflowService::class)->review($this->school, $task->id, $task->version, 'return', 'Add the dates.', $this->reviewer));
        } finally {
            $context->set($this->school);
        }

        $this->assertSame('hr_workforce_task_transition_context', $outcome, 'fails closed, never open');
        $this->assertSame(['submitted', 2, 0], $this->state($task->id));
    }

    #[Test]
    public function a_version_never_advances_without_a_transition(): void
    {
        $task = $this->submitted();

        // Review L2: a bare version bump would mint a "current" version an extra event could then claim.
        $this->assertSame('hr_workforce_task_version_transition', $this->commit(fn () => DB::table('workforce_tasks')->where('id', $task->id)->update(['version' => 3])));
        $this->assertSame(['submitted', 2, 0], $this->state($task->id));
        $this->assertSame([['assigned', null], ['submitted', 2]], $this->events($task->id));
    }

    #[Test]
    public function the_reassignment_link_clearing_never_carries_another_change(): void
    {
        $other = $this->createEmploymentRecord($this->createEmployee($this->school, ['user_id' => null]), ['starts_on' => '2024-01-01', 'status' => 'active', 'staff_type' => 'teaching']);
        $this->createEmployeeAssignment($other, $this->createPosition($this->school), ['starts_on' => '2024-01-01', 'ends_on' => null, 'is_primary' => true]);
        $original = $this->assigned();
        $moved = app(TaskAssignmentService::class)->reassign($this->school, $original->id, $original->version, $other->id, $this->hr);
        $this->assertSame($original->id, $moved->reassigned_from_task_id);
        $clear = fn (array $more) => fn () => DB::table('workforce_tasks')->where('id', $moved->id)->update(['reassigned_from_task_id' => null] + $more);

        // WO23-N1: the FK SET NULL shape (only the link cleared) was an early exit that skipped every other check.
        foreach ([['employment_record_id' => $this->record->id], ['title' => 'Rewritten'], ['due_on' => '2026-12-31'], ['completion_mode' => 'self'],
            ['status' => 'cancelled', 'cancelled_by_user_id' => $this->hr->id, 'cancelled_at' => now(), 'cancel_reason' => 'no_longer_needed']] as $more) {
            $this->assertSame('hr_workforce_tasks_guard', $this->commit($clear($more)), json_encode(array_keys($more)));
        }
        $this->assertSame([$other->id, 'Prepare the notice board', $original->id], [
            (string) DB::table('workforce_tasks')->where('id', $moved->id)->value('employment_record_id'),
            (string) DB::table('workforce_tasks')->where('id', $moved->id)->value('title'),
            (string) DB::table('workforce_tasks')->where('id', $moved->id)->value('reassigned_from_task_id'),
        ]);
        // The link alone may still be cleared, exactly as the FK action does when the original is purged.
        $this->assertSame('ok', $this->commit($clear([])));
        $this->assertNull(DB::table('workforce_tasks')->where('id', $moved->id)->value('reassigned_from_task_id'));
    }

    #[Test]
    public function a_finished_task_is_not_rewritten_through_the_link_clearing_either(): void
    {
        $other = $this->createEmploymentRecord($this->createEmployee($this->school, ['user_id' => null]), ['starts_on' => '2024-01-01', 'status' => 'active', 'staff_type' => 'teaching']);
        $this->createEmployeeAssignment($other, $this->createPosition($this->school), ['starts_on' => '2024-01-01', 'ends_on' => null, 'is_primary' => true]);
        $original = $this->assigned('self');
        $moved = app(TaskAssignmentService::class)->reassign($this->school, $original->id, $original->version, $other->id, $this->hr);
        $done = app(TaskWorkflowService::class)->cancel($this->school, $moved->id, $moved->version, TaskCancelReason::NO_LONGER_NEEDED, $this->hr);
        $this->assertSame('cancelled', $done->status);

        // Status and version kept, so the published branch let a final task's terms be rewritten (WO23-N1).
        $this->assertSame('hr_workforce_tasks_guard', $this->commit(fn () => DB::table('workforce_tasks')->where('id', $moved->id)
            ->update(['reassigned_from_task_id' => null, 'title' => 'Rewritten after the fact', 'cancel_reason' => 'assigned_in_error'])));
        $this->assertSame(['cancelled', 'Prepare the notice board', 'no_longer_needed'], [
            (string) DB::table('workforce_tasks')->where('id', $moved->id)->value('status'),
            (string) DB::table('workforce_tasks')->where('id', $moved->id)->value('title'),
            (string) DB::table('workforce_tasks')->where('id', $moved->id)->value('cancel_reason'),
        ]);
    }

    #[Test]
    public function events_stay_append_only_for_the_runtime_role(): void
    {
        $task = $this->submitted();

        $this->assertSame('denied', $this->commit(fn () => DB::table('workforce_task_events')->where('task_id', $task->id)->update(['task_version' => 9])));
        $this->assertSame('denied', $this->commit(fn () => DB::table('workforce_task_events')->where('task_id', $task->id)->delete()));
        $this->assertSame('denied', $this->commit(fn () => DB::table('workforce_tasks')->where('id', $task->id)->delete()));
        $this->assertSame([['assigned', null], ['submitted', 2]], $this->events($task->id));
    }

    #[Test]
    public function the_recorded_actor_stays_the_writers_claim_and_the_review_rules_still_apply(): void
    {
        $task = $this->submitted();
        $unrelated = $this->createUserWithCapabilities($this->school, []);

        // Rule 102: the database checks that evidence exists, not which human wrote it.
        $this->assertSame('ok', $this->commit(function () use ($task, $unrelated): void {
            $this->move($task->id, 'returned');
            $this->event($task->id, 'returned', 3, $unrelated);
        }));
        // The assignee still never returns it, and the existing refusal still comes first (HR.13's current-account
        // clause here; WO-22's known-same clause before WO-23's for another account: WorkforceTaskReviewGovernanceTest).
        $second = $this->submitted();
        $this->assertSame('hr_workforce_task_events_guard', $this->commit(function () use ($second): void {
            $this->move($second->id, 'returned');
            $this->event($second->id, 'returned', 3, $this->assignee);
        }));
    }

    #[Test]
    public function the_verifier_pins_the_evidence_backstop(): void
    {
        $checks = collect(app(DatabaseRoleVerifier::class)->verify())->keyBy('code');

        foreach (['hr_task_invariants', 'hr_family_trigger_functions', 'known_same_person_guards'] as $code) {
            $this->assertSame(CheckResult::PASS, $checks[$code]->status, $code.': '.$checks[$code]->note);
        }
    }
}
