<?php

namespace Tests\Feature\WorkforceTasks;

use App\Domain\HR\Application\EmployeeService;
use App\Domain\HR\Infrastructure\Employee;
use App\Domain\HR\Infrastructure\EmployeeAccountLink;
use App\Domain\HR\Infrastructure\EmployeeAssignment;
use App\Domain\HR\Infrastructure\EmploymentRecord;
use App\Domain\WorkforceTasks\Application\TaskAssignmentService;
use App\Domain\WorkforceTasks\Application\TaskCatalogueService;
use App\Domain\WorkforceTasks\Application\TaskException;
use App\Domain\WorkforceTasks\Application\TaskWorkflowService;
use App\Domain\WorkforceTasks\Infrastructure\TaskCategory;
use App\Domain\WorkforceTasks\Infrastructure\WorkforceTask;
use App\Models\School;
use App\Models\User;
use App\Support\Approval\ApprovalWorkflowRegistry;
use App\Support\Tenancy\SchoolNotOperationalException;
use App\Support\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\Feature\HR\Concerns\CreatesLinkedHrStaff;
use Tests\TestCase;

/**
 * HR.R1-C35 WO-22 (TRR-6; owner decision D-C35-5; rule 152).
 *
 * A reviewed staff task's review (submitted -> completed | returned) is a governed, domain-owned decision
 * (`ApprovalWorkflowRegistry::WORKFLOWS['workforce_task_reviews']`):
 * - the reviewer's authority is read FRESH under the task lock -- a cached yes never decides; a reviewer never on
 *   a path keeps the private "not found";
 * - never the assignee: by the current account (as before), nor by an account KNOWN to be the assignee (an
 *   uncorrected link, current or ended, of the reviewer or of an account known to be the same person); a corrected
 *   link no longer counts, and an unknown relationship is not a refusal (C33);
 * - the database backs the known-same rule for a completion (the task row) and a return (its event);
 * - completed reviews are never rewritten; a self-completed task is unchanged.
 *
 * Reproduced first at cb57c399 (a throwaway test, deleted): an ended, uncorrected account of the assignee
 * completed the review, and so did a reviewer whose grant was revoked behind the cache.
 */
class WorkforceTaskReviewGovernanceTest extends TestCase
{
    use CreatesLinkedHrStaff, CreatesTenancyFixtures;

    private School $school;

    private User $hr;

    private TaskCategory $category;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-06-15 10:00:00');
        $this->school = $this->createSchool();
        app(TenantContext::class)->set($this->school);
        $this->hr = $this->createUserWithCapabilities($this->school, ['hr.employees.assignments.manage', 'hr.employees.manage', 'hr.employees.view', 'hr.tasks.view', 'hr.tasks.configure', 'hr.tasks.manage']);
        $this->category = app(TaskCatalogueService::class)->createCategory($this->school, 'admin', 'Administrative', $this->hr);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function links(): EmployeeService
    {
        return app(EmployeeService::class);
    }

    private function link(string $employeeId, ?string $userId): void
    {
        $employee = Employee::query()->findOrFail($employeeId);
        $userId === null ? $this->links()->unlinkUser($employee, $this->hr) : $this->links()->linkUser($employee, $userId, $this->hr);
    }

    /** An Employee with a current employment, linked to $assignee (and first to each of $earlier, then unlinked). */
    private function employment(User $assignee, User ...$earlier): EmploymentRecord
    {
        $employee = $this->createEmployee($this->school, ['user_id' => null]);
        foreach ($earlier as $user) {
            $this->link($employee->id, $user->id);
            $this->link($employee->id, null);
        }
        $this->link($employee->id, $assignee->id);
        $record = $this->createEmploymentRecord($employee, ['starts_on' => '2024-01-01', 'status' => 'active', 'staff_type' => 'teaching']);
        $this->createEmployeeAssignment($record, $this->createPosition($this->school), ['starts_on' => '2024-01-01', 'ends_on' => null, 'is_primary' => true]);

        return $record;
    }

    private function submitted(EmploymentRecord $record, User $assignee, string $mode = 'reviewed'): WorkforceTask
    {
        $task = app(TaskAssignmentService::class)->assign($this->school, $record->id, ['title' => 'Prepare the notice board', 'category_id' => $this->category->id,
            'completion_mode' => $mode, 'available_on' => '2026-06-15', 'due_on' => '2026-06-20'], $this->hr);

        return app(TaskWorkflowService::class)->submit($this->school, $task->id, $task->version, null, $assignee);
    }

    private function review(WorkforceTask $task, User $reviewer, string $decision = 'complete'): string
    {
        try {
            return app(TaskWorkflowService::class)->review($this->school, $task->id, $task->refresh()->version, $decision, $decision === 'return' ? 'Add the dates.' : null, $reviewer)->status;
        } catch (TaskException $e) {
            return $e->errorCode();
        } catch (AuthorizationException) {
            return 'FORBIDDEN';
        } catch (SchoolNotOperationalException) {
            return 'NOT_OPERATIONAL';
        }
    }

    private function reviewer(): User
    {
        return $this->createUserWithCapabilities($this->school, ['hr.tasks.view', 'hr.tasks.manage']);
    }

    /** @return array{0: string, 1: int} the task's status and its number of timeline events */
    private function state(WorkforceTask $task): array
    {
        return [$task->refresh()->status, DB::table('workforce_task_events')->where('task_id', $task->id)->count()];
    }

    #[Test]
    public function an_independent_current_reviewer_completes_or_returns(): void
    {
        $assignee = $this->createUserWithCapabilities($this->school, ['hr.tasks.self']);
        $record = $this->employment($assignee);
        $this->assertSame('completed', $this->review($this->submitted($record, $assignee), $this->reviewer()));
        $this->assertSame('returned', $this->review($this->submitted($record, $assignee), $this->reviewer(), 'return'));
    }

    /** @return array<string, array{0: string}> */
    public static function decisions(): array
    {
        return ['complete' => ['complete'], 'return' => ['return']];
    }

    #[Test]
    #[DataProvider('decisions')]
    public function an_account_known_to_be_the_assignee_never_reviews(string $decision): void
    {
        // The assignee may review others' tasks too, so the own-account refusal is reached.
        $assignee = $this->createUserWithCapabilities($this->school, ['hr.tasks.self', 'hr.tasks.view', 'hr.tasks.manage']);
        $former = $this->reviewer();          // once linked to the assignee's Employee (ended, uncorrected)
        $alias = $this->reviewer();           // linked with the assignee's account to ANOTHER Employee: known to be the same person
        $other = $this->createEmployee($this->school, ['user_id' => null]);
        $this->link($other->id, $assignee->id);
        $this->link($other->id, null);
        $this->link($other->id, $alias->id);
        $record = $this->employment($assignee, $former);
        $task = $this->submitted($record, $assignee);
        $before = $this->state($task);

        $this->assertSame('TASK_SELF_ADMINISTRATION', $this->review($task, $assignee, $decision), 'one\'s own account, as before');
        $this->assertSame('TASK_SAME_PERSON_REVIEW', $this->review($task, $former, $decision), 'an ended, uncorrected link');
        $this->assertSame('TASK_SAME_PERSON_REVIEW', $this->review($task, $alias, $decision), 'an account known to be the same person');
        $this->assertSame($before, $this->state($task), 'nothing recorded');
        $this->assertSame(0, DB::table('school_audit_events')->whereIn('event_type', ['hr.task.completed', 'hr.task.returned'])->where('subject_id', $task->id)->count());
    }

    #[Test]
    public function the_service_refuses_a_known_same_person_before_any_write_is_attempted(): void
    {
        $assignee = $this->createUserWithCapabilities($this->school, ['hr.tasks.self']);
        $former = $this->reviewer();
        $task = $this->submitted($this->employment($assignee, $former), $assignee);
        $writes = [];
        DB::connection()->beforeExecuting(function (string $sql) use (&$writes): void {
            if (preg_match('/^\s*(update|insert)\b.*\bworkforce_task/i', $sql) === 1) {
                $writes[] = $sql;
            }
        });

        $this->assertSame('TASK_SAME_PERSON_REVIEW', $this->review($task, $former));
        $this->assertSame([], $writes, 'refused by the service itself, not only by the database guard (review L3)');
    }

    #[Test]
    public function the_account_that_submitted_never_reviews_even_after_its_link_is_corrected(): void
    {
        // A wrong account linked to the Employee submits the task; the link is then corrected (voided).
        $wrong = $this->createUserWithCapabilities($this->school, ['hr.tasks.self', 'hr.tasks.view', 'hr.tasks.manage']);
        $assignee = $this->createUserWithCapabilities($this->school, ['hr.tasks.self']);
        $record = $this->employment($wrong);
        $task = $this->submitted($record, $wrong);
        $this->link($record->employee_id, null);
        $this->link($record->employee_id, $assignee->id);
        $link = EmployeeAccountLink::query()->where('employee_id', $record->employee_id)->where('user_id', $wrong->id)->sole();
        $this->approveCorrection($this->school, $this->requestCorrection(Employee::query()->findOrFail($record->employee_id), $link->id, $this->linkedHrStaff($this->school)), $this->linkedHrStaff($this->school));
        app(TenantContext::class)->set($this->school);

        $writes = [];
        DB::connection()->beforeExecuting(function (string $sql) use (&$writes): void {
            if (preg_match('/^\s*(update|insert)\b.*\bworkforce_task/i', $sql) === 1) {
                $writes[] = $sql;
            }
        });
        $this->assertSame('TASK_SAME_PERSON_REVIEW', $this->review($task, $wrong), 'the submitter is the maker (review L1)');
        $this->assertSame([], $writes, 'refused by the service before any write, not only by the database');
        $raw = function (callable $write): string {
            try {
                DB::transaction($write);

                return 'ok';
            } catch (QueryException $e) {
                return str_contains($e->getMessage(), '(hr_workforce_task_review_same_person)') ? 'refused' : substr($e->getMessage(), 0, 200);
            }
        };
        $this->assertSame('refused', $raw(fn () => DB::table('workforce_tasks')->where('id', $task->id)
            ->update(['status' => 'completed', 'completed_by_user_id' => $wrong->id, 'completed_at' => now(), 'version' => DB::raw('version + 1')])));
        $this->assertSame('refused', $raw(fn () => DB::table('workforce_task_events')->insert([
            'id' => (string) Str::uuid7(), 'school_id' => $this->school->id, 'task_id' => $task->id, 'kind' => 'returned', 'actor_user_id' => $wrong->id, 'note' => 'x', 'occurred_at' => now()])));
        $this->assertSame('completed', $this->review($task, $this->reviewer()), 'an independent reviewer decides');
    }

    #[Test]
    public function a_corrected_link_no_longer_counts_and_an_unknown_relationship_is_not_a_refusal(): void
    {
        $assignee = $this->createUserWithCapabilities($this->school, ['hr.tasks.self']);
        $wrong = $this->reviewer();
        $record = $this->employment($assignee, $wrong);
        $task = $this->submitted($record, $assignee);
        $this->assertSame('TASK_SAME_PERSON_REVIEW', $this->review($task, $wrong));

        $link = EmployeeAccountLink::query()->where('employee_id', $record->employee_id)->where('user_id', $wrong->id)->sole();
        $this->approveCorrection($this->school, $this->requestCorrection(Employee::query()->findOrFail($record->employee_id), $link->id, $this->linkedHrStaff($this->school)), $this->linkedHrStaff($this->school));
        app(TenantContext::class)->set($this->school);
        $this->assertSame('completed', $this->review($task, $wrong), 'corrected as someone else');

        $this->assertSame('completed', $this->review($this->submitted($record, $assignee), $this->reviewer()), 'never linked: unknown, not refused (C33)');
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function lostAuthority(): array
    {
        return [
            'grant revoked behind the cache' => ['revoked', 'FORBIDDEN'],
            'membership suspended' => ['membership', 'FORBIDDEN'],
            'account disabled' => ['disabled', 'FORBIDDEN'],
            'School suspended' => ['school', 'NOT_OPERATIONAL'],
        ];
    }

    #[Test]
    #[DataProvider('lostAuthority')]
    public function the_reviewers_authority_is_read_fresh(string $loss, string $expected): void
    {
        $assignee = $this->createUserWithCapabilities($this->school, ['hr.tasks.self']);
        $reviewer = $this->reviewer();
        $task = $this->submitted($this->employment($assignee), $assignee);
        $this->assertTrue(Gate::forUser($reviewer)->allows('capability', ['hr.tasks.manage', $this->school]), 'cache warmed');
        $membership = DB::table('school_memberships')->where('school_id', $this->school->id)->where('user_id', $reviewer->id);

        match ($loss) {
            'revoked' => DB::table('membership_role_assignments')->whereIn('school_membership_id', $membership->pluck('id'))->update(['revoked_at' => now(), 'revocation_reason' => 'revoked']),
            'membership' => $membership->update(['status' => 'suspended']),
            'disabled' => DB::table('users')->where('id', $reviewer->id)->update(['is_disabled' => true]),
            'school' => DB::table('schools')->where('id', $this->school->id)->update(['status' => 'suspended']),
        };
        $this->assertTrue(Gate::forUser($reviewer)->allows('capability', ['hr.tasks.manage', $this->school]), 'the cached floor still says yes');

        $this->assertSame($expected, $this->review($task, $reviewer));
        $this->assertSame('submitted', $task->refresh()->status);
    }

    #[Test]
    public function a_manager_reviews_only_while_the_line_is_current_and_a_stranger_finds_nothing(): void
    {
        $assignee = $this->createUserWithCapabilities($this->school, ['hr.tasks.self']);
        $record = $this->employment($assignee);
        $manager = $this->createUserWithCapabilities($this->school, ['hr.tasks.view', 'hr.tasks.team']);
        $managerEmployment = $this->employment($manager);
        $this->setReportingLineFixture(
            EmployeeAssignment::query()->where('employment_record_id', $record->id)->sole(),
            EmployeeAssignment::query()->where('employment_record_id', $managerEmployment->id)->sole(),
        );
        $this->assertSame('completed', $this->review($this->submitted($record, $assignee), $manager));

        $stranger = $this->createUserWithCapabilities($this->school, ['hr.tasks.view', 'hr.tasks.team']);
        $this->employment($stranger);
        $this->assertSame('TASK_NOT_FOUND', $this->review($this->submitted($record, $assignee), $stranger), 'never on a path: the private not found');
    }

    #[Test]
    public function a_completed_review_is_never_rewritten_by_a_later_link(): void
    {
        $assignee = $this->createUserWithCapabilities($this->school, ['hr.tasks.self']);
        $reviewer = $this->reviewer();
        $record = $this->employment($assignee);
        $task = $this->submitted($record, $assignee);
        $this->assertSame('completed', $this->review($task, $reviewer));
        $row = DB::table('workforce_tasks')->where('id', $task->id)->first(['status', 'completed_by_user_id', 'completed_at', 'version']);

        $this->link($record->employee_id, null);
        $this->link($record->employee_id, $reviewer->id); // the reviewer is later linked to the assignee's Employee
        $this->assertEquals($row, DB::table('workforce_tasks')->where('id', $task->id)->first(['status', 'completed_by_user_id', 'completed_at', 'version']));
    }

    #[Test]
    public function a_self_completed_task_is_unchanged(): void
    {
        $assignee = $this->createUserWithCapabilities($this->school, ['hr.tasks.self']);
        $task = $this->submitted($this->employment($assignee), $assignee, 'self');
        $this->assertSame(['completed', $assignee->id], [$task->status, $task->completed_by_user_id]);
    }

    #[Test]
    public function the_runtime_role_cannot_record_a_review_by_an_account_known_to_be_the_assignee(): void
    {
        $assignee = $this->createUserWithCapabilities($this->school, ['hr.tasks.self']);
        $former = $this->reviewer();
        $independent = $this->reviewer();
        $record = $this->employment($assignee, $former);
        $raw = function (callable $write): string {
            try {
                DB::transaction($write);

                return 'ok';
            } catch (QueryException $e) {
                return str_contains($e->getMessage(), '(hr_workforce_task_review_same_person)') ? 'refused' : substr($e->getMessage(), 0, 200);
            }
        };
        $complete = fn (WorkforceTask $t, User $u) => fn () => DB::table('workforce_tasks')->where('id', $t->id)
            ->update(['status' => 'completed', 'completed_by_user_id' => $u->id, 'completed_at' => now(), 'version' => DB::raw('version + 1')]);
        $returnEvent = fn (WorkforceTask $t, User $u) => fn () => DB::table('workforce_task_events')->insert([
            'id' => (string) Str::uuid7(), 'school_id' => $this->school->id, 'task_id' => $t->id, 'kind' => 'returned', 'actor_user_id' => $u->id, 'note' => 'x', 'occurred_at' => now(),
        ]);

        $task = $this->submitted($record, $assignee);
        $this->assertSame('refused', $raw($complete($task, $former)));
        $this->assertSame('refused', $raw($returnEvent($task, $former)));
        $this->assertSame('ok', $raw($returnEvent($task, $independent)), 'an independent return event is the positive control');
        $this->assertSame('ok', $raw($complete($task, $independent)), 'an independent completion is the positive control');
    }

    #[Test]
    public function task_review_is_a_registered_domain_workflow_never_exempt(): void
    {
        $workflow = ApprovalWorkflowRegistry::WORKFLOWS['workforce_task_reviews'];
        $this->assertSame(['WorkforceTasks', 'domain', TaskWorkflowService::class, ['review'], 'trg_workforce_tasks_guard'],
            [$workflow['domain'], $workflow['makerChecker'], $workflow['service'], $workflow['decisions'], $workflow['dbBackstop']]);
        $this->assertSame('workforce_tasks.completed_by_user_id', $workflow['decider']);
        foreach (array_keys(ApprovalWorkflowRegistry::NOT_WORKFLOWS) as $column) {
            $this->assertStringStartsNotWith('workforce_task', $column, 'never declared exempt');
        }

        $source = (string) file_get_contents((string) (new \ReflectionClass(TaskWorkflowService::class))->getFileName());
        $review = substr($source, (int) strpos($source, 'public function review('), 2500);
        $this->assertStringContainsString('$this->lockReviewable(', $review, 'review decides through lockReviewable');
        $lock = substr($source, (int) strpos($source, 'private function lockReviewable('), 1500);
        $this->assertStringContainsString('->currentPathFor(', $lock, 'the fresh authority read');
        $this->assertStringContainsString('->isKnownToBeAssignee(', $lock, 'the known-same-person refusal');
    }
}
