<?php

namespace Tests\Feature\WorkforceTasks;

use App\Domain\HR\Application\EmployeeService;
use App\Domain\HR\Infrastructure\Employee;
use App\Domain\Leave\Application\Calendar\StaffCalendarHistory;
use App\Domain\WorkforceTasks\Application\TaskAssignmentService;
use App\Domain\WorkforceTasks\Application\TaskCatalogueService;
use App\Domain\WorkforceTasks\Application\TaskWorkflowService;
use App\Domain\WorkforceTasks\Infrastructure\WorkforceTask;
use App\Models\School;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\Concerns\ForcesConcurrentOverlap;
use Tests\TestCase;

/**
 * HR.R1-C35 WO-22 (TRR-6) on COMMITTED data, in two OS processes, holder held open and contender observed blocked.
 *
 * - **Authority:** the review reads the reviewer's grants FOR SHARE (holdsInSchoolNow) until it commits. A
 *   revocation first refuses the waiting review; a review first commits and the waiting revocation lands after it.
 * - **Identity:** the review holds the assignee's Employee row FOR SHARE; a link of THAT Employee (an UPDATE of
 *   it) is ordered by it. A link of the reviewer first makes the waiting review refuse them; a review first
 *   stands, and the waiting link never rewrites it. (A link of another Employee is read as committed history,
 *   like every rule 152 decision; LeaveKnownSamePersonConcurrencyTest.)
 * - **Competing reviewers:** the task row is locked FOR UPDATE: the second review is stale.
 */
class WorkforceTaskReviewConcurrencyTest extends TestCase
{
    use CreatesTenancyFixtures, ForcesConcurrentOverlap;

    /** @var array<int, string> */
    protected $connectionsToTransact = [];

    private ?School $school = null;

    private User $hr;

    private User $reviewer;

    private string $employeeId;

    private WorkforceTask $task;

    protected function setUp(): void
    {
        parent::setUp();
        $this->school = $this->createSchool();
        $context = app(TenantContext::class);
        $context->set($this->school);
        $this->hr = $this->createUserWithCapabilities($this->school, ['hr.employees.assignments.manage', 'hr.employees.manage', 'hr.employees.view', 'hr.tasks.view', 'hr.tasks.configure', 'hr.tasks.manage']);
        $this->reviewer = $this->createUserWithCapabilities($this->school, ['hr.tasks.view', 'hr.tasks.manage']);
        $assignee = $this->createUserWithCapabilities($this->school, ['hr.tasks.self']);
        $category = app(TaskCatalogueService::class)->createCategory($this->school, 'admin', 'Administrative', $this->hr);
        $employee = $this->createEmployee($this->school, ['user_id' => null]);
        $this->employeeId = $employee->id;
        app(EmployeeService::class)->linkUser($employee->fresh(), $assignee->id, $this->hr);
        $record = $this->createEmploymentRecord($employee, ['starts_on' => '2024-01-01', 'status' => 'active', 'staff_type' => 'teaching']);
        $this->createEmployeeAssignment($record, $this->createPosition($this->school), ['starts_on' => '2024-01-01', 'ends_on' => null, 'is_primary' => true]);
        $today = app(StaffCalendarHistory::class)->today($this->school);
        $task = app(TaskAssignmentService::class)->assign($this->school, $record->id, ['title' => 'Prepare the notice board', 'category_id' => $category->id,
            'completion_mode' => 'reviewed', 'available_on' => $today, 'due_on' => $today], $this->hr);
        $this->task = app(TaskWorkflowService::class)->submit($this->school, $task->id, $task->version, null, $assignee);
        // The assignee's link ends after the submission (still theirs: an ended, uncorrected link), so the Employee may be relinked.
        app(EmployeeService::class)->unlinkUser(Employee::query()->findOrFail($this->employeeId), $this->hr);
        $context->clearAll();
    }

    protected function tearDown(): void
    {
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
    private function review(?User $reviewer = null): array
    {
        return ['php', __DIR__.'/../../Support/task-review-op.php', $this->school->id, ($reviewer ?? $this->reviewer)->id, $this->task->id, (string) $this->task->version, 'complete'];
    }

    /** @return list<string> */
    private function revoke(): array
    {
        $membership = (string) DB::connection('pgsql_admin')->table('school_memberships')->where('school_id', $this->school->id)->where('user_id', $this->reviewer->id)->value('id');

        return ['php', __DIR__.'/../../Support/staff-role-grant-op.php', 'revoke-issuer', $this->school->id, $membership];
    }

    /** @return list<string> */
    private function linkReviewer(): array
    {
        return ['php', __DIR__.'/../../Support/acting-employee-op.php', 'link', $this->school->id, $this->hr->id, $this->employeeId, $this->reviewer->id];
    }

    private function taskStatus(): string
    {
        return (string) DB::connection('pgsql_admin')->table('workforce_tasks')->where('id', $this->task->id)->value('status');
    }

    #[Test]
    public function a_revocation_held_open_refuses_the_waiting_review(): void
    {
        [$holder, $contender] = $this->raceWithHeldHolder($this->revoke(), $this->review());

        $this->assertSame('revoked:1', $holder);
        // Refused either way: 403 when the process still caches a yes, the private "not found" when its cache is
        // empty (a fresh child process). Never a decision.
        $this->assertContains($contender, ['rejected:AuthorizationException', 'refused:TASK_NOT_FOUND']);
        $this->assertSame('submitted', $this->taskStatus());
    }

    #[Test]
    public function a_review_held_open_stands_and_the_waiting_revocation_lands_after_it(): void
    {
        [$holder, $contender] = $this->raceWithHeldHolder($this->review(), $this->revoke());

        $this->assertSame('ok:completed', $holder);
        $this->assertSame('revoked:1', $contender);
        $this->assertSame('completed', $this->taskStatus());
    }

    #[Test]
    public function a_link_of_the_reviewer_held_open_makes_the_waiting_review_refuse_them(): void
    {
        [$holder, $contender] = $this->raceWithHeldHolder($this->linkReviewer(), $this->review());

        $this->assertSame('linked', $holder);
        $this->assertSame('refused:TASK_SAME_PERSON_REVIEW', $contender);
        $this->assertSame('submitted', $this->taskStatus());
    }

    #[Test]
    public function a_review_held_open_stands_and_the_waiting_link_never_rewrites_it(): void
    {
        [$holder, $contender] = $this->raceWithHeldHolder($this->review(), $this->linkReviewer());

        $this->assertSame('ok:completed', $holder);
        $this->assertSame('linked', $contender);
        $this->assertSame([$this->reviewer->id, 'completed'], [
            (string) DB::connection('pgsql_admin')->table('workforce_tasks')->where('id', $this->task->id)->value('completed_by_user_id'), $this->taskStatus(),
        ]);
    }

    #[Test]
    public function two_reviewers_of_one_task_decide_once(): void
    {
        $second = $this->createUserWithCapabilities($this->school, ['hr.tasks.view', 'hr.tasks.manage']);

        [$holder, $contender] = $this->raceWithHeldHolder($this->review(), $this->review($second));

        $this->assertSame('ok:completed', $holder);
        $this->assertSame('refused:TASK_STALE', $contender);
    }
}
