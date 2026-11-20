<?php

namespace Tests\Feature\WorkforceTasks;

use App\Domain\HR\Application\EmployeeService;
use App\Domain\Leave\Application\Calendar\StaffCalendarHistory;
use App\Domain\WorkforceTasks\Application\TaskAssignmentService;
use App\Domain\WorkforceTasks\Application\TaskCatalogueService;
use App\Domain\WorkforceTasks\Application\TaskWorkflowService;
use App\Domain\WorkforceTasks\Infrastructure\WorkforceTask;
use App\Models\School;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\Concerns\ForcesConcurrentOverlap;
use Tests\TestCase;

/**
 * HR.R1-C35 WO-23 (WO22-N1, RF1-N1) on COMMITTED data: the evidence check runs at a real COMMIT, not only under
 * `SET CONSTRAINTS ALL IMMEDIATE` (WorkforceTaskTransitionEvidenceTest).
 *
 * - The canonical submission and return commit, each with the event for its version.
 * - A raw return with no event, as the runtime role under the School's context, fails at COMMIT and leaves the
 *   task submitted.
 * - Two reviewers in two OS processes, the first held open before its commit and the second observed blocked on
 *   the task row: one decision commits, the other is stale, and exactly one event documents each version.
 */
class WorkforceTaskTransitionEvidenceCommitTest extends TestCase
{
    use CreatesTenancyFixtures, ForcesConcurrentOverlap;

    /** @var array<int, string> */
    protected $connectionsToTransact = [];

    private ?School $school = null;

    private User $reviewer;

    private WorkforceTask $task;

    protected function setUp(): void
    {
        parent::setUp();
        $this->school = $this->createSchool();
        $context = app(TenantContext::class);
        $context->set($this->school);
        $hr = $this->createUserWithCapabilities($this->school, ['hr.employees.assignments.manage', 'hr.employees.manage', 'hr.employees.view', 'hr.tasks.view', 'hr.tasks.configure', 'hr.tasks.manage']);
        $this->reviewer = $this->createUserWithCapabilities($this->school, ['hr.tasks.view', 'hr.tasks.manage']);
        $assignee = $this->createUserWithCapabilities($this->school, ['hr.tasks.self']);
        $category = app(TaskCatalogueService::class)->createCategory($this->school, 'admin', 'Administrative', $hr);
        $employee = $this->createEmployee($this->school, ['user_id' => null]);
        app(EmployeeService::class)->linkUser($employee->fresh(), $assignee->id, $hr);
        $record = $this->createEmploymentRecord($employee, ['starts_on' => '2024-01-01', 'status' => 'active', 'staff_type' => 'teaching']);
        $this->createEmployeeAssignment($record, $this->createPosition($this->school), ['starts_on' => '2024-01-01', 'ends_on' => null, 'is_primary' => true]);
        $today = app(StaffCalendarHistory::class)->today($this->school);
        $task = app(TaskAssignmentService::class)->assign($this->school, $record->id, ['title' => 'Prepare the notice board', 'category_id' => $category->id,
            'completion_mode' => 'reviewed', 'available_on' => $today, 'due_on' => $today], $hr);
        // A canonical submission, committed for real: its deferred check ran at that COMMIT.
        $this->task = app(TaskWorkflowService::class)->submit($this->school, $task->id, $task->version, null, $assignee);
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->clearAll();
        if ($this->school !== null) {
            try {
                $this->purgeSchoolsAsAdmin([$this->school->id]);
            } catch (\Throwable) {
                // Append-only evidence makes the School hard to delete; residue is reset between sessions.
            }
        }
        parent::tearDown();
    }

    /** @return list<string> */
    private function review(string $decision, ?User $reviewer = null): array
    {
        return ['php', __DIR__.'/../../Support/task-review-op.php', $this->school->id, ($reviewer ?? $this->reviewer)->id, $this->task->id, (string) $this->task->version, $decision];
    }

    /** @return array{0: string, 1: int} */
    private function committedState(): array
    {
        $row = DB::connection('pgsql_admin')->table('workforce_tasks')->where('id', $this->task->id)->first(['status', 'version']);

        return [(string) $row->status, (int) $row->version];
    }

    /** @return list<string> "kind@version" of the committed events */
    private function committedEvents(): array
    {
        return DB::connection('pgsql_admin')->table('workforce_task_events')->where('task_id', $this->task->id)->orderBy('occurred_at')->orderBy('id')
            ->get(['kind', 'task_version'])->map(fn ($e) => $e->kind.'@'.($e->task_version ?? '-'))->all();
    }

    #[Test]
    public function the_canonical_submission_and_return_commit_for_real(): void
    {
        $this->assertSame(['submitted', 2], $this->committedState());
        app(TaskWorkflowService::class)->review($this->school, $this->task->id, $this->task->version, 'return', 'Add the dates.', $this->reviewer);

        $this->assertSame(['returned', 3], $this->committedState());
        $this->assertSame(['assigned@-', 'submitted@2', 'returned@3'], $this->committedEvents());
    }

    #[Test]
    public function a_raw_return_with_no_event_fails_at_the_real_commit(): void
    {
        $refusal = null;
        try {
            DB::connection('pgsql')->transaction(function (): void {
                DB::table('workforce_tasks')->where('id', $this->task->id)
                    ->update(['status' => 'returned', 'return_count' => 1, 'version' => DB::raw('version + 1')]);
            });
        } catch (QueryException|\PDOException $e) { // a refusal AT COMMIT surfaces as the driver's exception
            $refusal = $e->getMessage();
        }

        $this->assertNotNull($refusal, 'the commit must not succeed');
        $this->assertStringContainsString('(hr_workforce_task_transition_evidence)', (string) $refusal);
        $this->assertSame(['submitted', 2], $this->committedState());
        $this->assertSame(['assigned@-', 'submitted@2'], $this->committedEvents());
    }

    #[Test]
    public function two_reviewers_returning_one_task_decide_once_with_one_event(): void
    {
        $second = $this->createUserWithCapabilities($this->school, ['hr.tasks.view', 'hr.tasks.manage']);

        [$holder, $contender] = $this->raceWithHeldHolder($this->review('return'), $this->review('return', $second));

        $this->assertSame('ok:returned', $holder);
        $this->assertSame('refused:TASK_STALE', $contender);
        $this->assertSame(['returned', 3], $this->committedState());
        $this->assertSame(['assigned@-', 'submitted@2', 'returned@3'], $this->committedEvents());
    }

    #[Test]
    public function a_return_held_open_leaves_the_waiting_completion_stale(): void
    {
        $second = $this->createUserWithCapabilities($this->school, ['hr.tasks.view', 'hr.tasks.manage']);

        [$holder, $contender] = $this->raceWithHeldHolder($this->review('return'), $this->review('complete', $second));

        $this->assertSame('ok:returned', $holder);
        $this->assertSame('refused:TASK_STALE', $contender);
        $this->assertSame(['assigned@-', 'submitted@2', 'returned@3'], $this->committedEvents());
    }
}
