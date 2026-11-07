<?php

namespace Tests\Feature\WorkforceTasks;

use App\Domain\HR\Application\EmploymentService;
use App\Domain\HR\Application\Separation\SeparationCaseService;
use App\Domain\HR\Application\Separation\SeparationObligations;
use App\Domain\HR\Application\Separation\SeparationReason;
use App\Domain\HR\Infrastructure\Department;
use App\Domain\HR\Infrastructure\Employee;
use App\Domain\HR\Infrastructure\EmploymentRecord;
use App\Domain\HR\Infrastructure\Position;
use App\Domain\WorkforceTasks\Application\TaskAssignmentService;
use App\Domain\WorkforceTasks\Application\TaskCancelReason;
use App\Domain\WorkforceTasks\Application\TaskCatalogueService;
use App\Domain\WorkforceTasks\Application\TaskException;
use App\Domain\WorkforceTasks\Application\TaskFrequency;
use App\Domain\WorkforceTasks\Application\TaskGenerationService;
use App\Domain\WorkforceTasks\Application\TaskReadService;
use App\Domain\WorkforceTasks\Application\TaskWorkflowService;
use App\Domain\WorkforceTasks\Infrastructure\TaskCategory;
use App\Domain\WorkforceTasks\Infrastructure\TaskTemplateVersion;
use App\Domain\WorkforceTasks\Infrastructure\WorkforceTask;
use App\Models\School;
use App\Models\User;
use App\Support\Operations\CheckResult;
use App\Support\Operations\DatabaseRoleVerifier;
use App\Support\Tenancy\SchoolOperationalGuard;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * HR.13 (owner decisions 2026-11-04): staff tasks end to end -- the
 * catalogue and its immutable versions, the calendar-neutral schedule, the
 * idempotent complete-roster generation, assignment (administrative and
 * current-manager paths), the self / reviewed lifecycle, reassignment,
 * reads, separation and the database backstops.
 */
class WorkforceTaskTest extends TestCase
{
    use CreatesTenancyFixtures;

    private School $school;

    private User $hr;

    private TaskCategory $category;

    private Department $department;

    private Position $position;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-06-15 10:00:00'); // a Monday, School-local (Asia/Kolkata)
        $this->school = $this->createSchool();
        app(TenantContext::class)->set($this->school);
        $this->hr = $this->createUserWithCapabilities($this->school, [
            'hr.employees.assignments.manage', 'hr.tasks.view', 'hr.tasks.configure', 'hr.tasks.manage',
        ]);
        $this->category = app(TaskCatalogueService::class)->createCategory($this->school, 'admin', 'Administrative', $this->hr);
        $this->department = $this->createDepartment($this->school);
        $this->position = $this->createPosition($this->school);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** An employment with today's effective facts, optionally linked to a User. */
    private function staff(?string $staffType = 'teaching', array $employment = [], ?User $user = null, ?Department $department = null, ?Position $position = null): EmploymentRecord
    {
        $employee = $this->createEmployee($this->school, $user === null ? [] : ['user_id' => $user->id]);
        $record = $this->createEmploymentRecord($employee, [...['starts_on' => '2024-01-01', 'status' => 'active', 'staff_type' => $staffType], ...$employment]);
        $this->createEmployeeAssignment($record, $position ?? $this->position, ['starts_on' => '2024-01-01', 'ends_on' => null, 'is_primary' => true, 'department_id' => ($department ?? $this->department)->id]);

        return $record;
    }

    private function template(string $code, array $terms = []): void
    {
        app(TaskCatalogueService::class)->createTemplate($this->school, $code, [
            'title' => "Task {$code}", 'category_id' => $this->category->id, 'completion_mode' => 'self', 'frequency' => 'daily', 'weekdays' => 127,
            'due_days' => 0, 'applies_teaching' => true, 'applies_non_teaching' => false, 'applies_outsourced' => false, 'effective_from' => '2026-06-15', ...$terms,
        ], $this->hr);
    }

    private function code(callable $work): string
    {
        try {
            $work();
        } catch (TaskException $e) {
            return $e->errorCode();
        } catch (AuthorizationException) {
            return 'FORBIDDEN';
        }
        $this->fail('Expected a refusal.');
    }

    private function assignTerms(array $override = []): array
    {
        return ['title' => 'Prepare the notice board', 'category_id' => $this->category->id, 'completion_mode' => 'reviewed', 'available_on' => '2026-06-15', 'due_on' => '2026-06-20', ...$override];
    }

    #[Test]
    public function the_schedule_is_calendar_neutral_and_never_invents_a_date(): void
    {
        $v = fn (array $a) => new TaskTemplateVersion(['frequency' => 'monthly', 'weekdays' => null, 'month_day' => null, 'last_day' => false, 'year_month' => null, ...$a]);
        $d = fn (string $date) => CarbonImmutable::parse($date);

        $this->assertTrue(TaskFrequency::occursOn($v(['frequency' => 'daily', 'weekdays' => 1 | 16]), $d('2026-06-15')), 'Monday');
        $this->assertFalse(TaskFrequency::occursOn($v(['frequency' => 'daily', 'weekdays' => 1 | 16]), $d('2026-06-16')), 'Tuesday is not chosen');
        $this->assertTrue(TaskFrequency::occursOn($v(['frequency' => 'weekly', 'weekdays' => 16]), $d('2026-06-19')), 'Friday');
        $this->assertTrue(TaskFrequency::occursOn($v(['last_day' => true]), $d('2027-02-28')), 'last day of a non-leap February');
        $this->assertTrue(TaskFrequency::occursOn($v(['last_day' => true]), $d('2026-04-30')));
        $this->assertFalse(TaskFrequency::occursOn($v(['last_day' => true]), $d('2026-05-30')));
        $this->assertTrue(TaskFrequency::occursOn($v(['frequency' => 'yearly', 'year_month' => 2, 'last_day' => true]), $d('2028-02-29')), 'leap year: the last day');
        $this->assertTrue(TaskFrequency::occursOn($v(['frequency' => 'yearly', 'year_month' => 2, 'last_day' => true]), $d('2027-02-28')));

        foreach ([
            ['frequency' => 'weekly', 'weekdays' => 1 | 2],
            ['frequency' => 'monthly', 'month_day' => 29],
            ['frequency' => 'yearly', 'year_month' => 2, 'month_day' => 29],
            ['frequency' => 'yearly', 'year_month' => 4, 'month_day' => 31],
        ] as $i => $terms) {
            $this->assertSame('TASK_SCHEDULE_INVALID', $this->code(fn () => $this->template("bad{$i}", $terms)), json_encode($terms));
        }
        $this->assertSame('TASK_VERSION_EFFECTIVE_FROM', $this->code(fn () => $this->template('past', ['effective_from' => '2026-06-14'])));
    }

    #[Test]
    public function generation_matches_effective_facts_is_idempotent_and_reconciles(): void
    {
        $other = $this->createDepartment($this->school);
        $match = $this->staff('teaching');
        $this->staff('non_teaching');                                     // wrong staff type
        $this->staff('teaching', department: $other);                     // wrong department
        $this->staff(null);                                               // unclassified: never matches
        $this->staff('teaching', ['ends_on' => '2026-06-14', 'status' => 'separated']); // separated (outside today)
        $this->staff('teaching', ['starts_on' => '2026-07-01', 'status' => 'pre_joining']); // not yet employed
        $notice = $this->staff('teaching', ['status' => 'notice_period']);
        $this->template('dept', ['department_id' => $this->department->id]);
        $this->template('weekly-tue', ['frequency' => 'weekly', 'weekdays' => 2]);  // does not occur today (Monday)

        $run = app(TaskGenerationService::class)->run($this->school);

        $created = WorkforceTask::query()->orderBy('employment_record_id')->pluck('employment_record_id')->all();
        $expected = [$match->id, $notice->id];
        sort($expected);
        $this->assertSame($expected, $created);
        $this->assertSame(['completed', 2, 0], [$run->status, $run->created, $run->existing]);
        $this->assertSame($run->examined * 1, $run->created + $run->existing + $run->not_matched, 'reconciled: one occurring template');

        $again = app(TaskGenerationService::class)->generateNow($this->school, $this->hr);
        $this->assertSame(2, WorkforceTask::query()->count(), 'a re-run never duplicates');
        $this->assertSame(2, $again->existing);
        $task = WorkforceTask::query()->where('employment_record_id', $match->id)->firstOrFail();
        $this->assertSame(['generated', '2026-06-15', '2026-06-15', $this->department->id, 'teaching'], [$task->assignment_path, $task->occurrence_on->toDateString(), $task->due_on->toDateString(), $task->department_id, $task->staff_type]);
    }

    #[Test]
    public function generation_uses_the_schools_own_date(): void
    {
        $this->staff('teaching');
        $this->template('daily', ['weekdays' => 2]); // Tuesday only
        Carbon::setTestNow('2026-06-15 20:00:00'); // UTC Monday evening = Tuesday 01:30 in Asia/Kolkata

        $run = app(TaskGenerationService::class)->run($this->school);

        $this->assertSame('2026-06-16', $run->occurrence_on->toDateString());
        $this->assertSame(1, $run->created);
    }

    #[Test]
    public function a_large_roster_is_generated_completely_in_bounded_batches_and_resumes_without_duplicates(): void
    {
        $now = now();
        $employees = [];
        $employments = [];
        $assignments = [];
        foreach (range(1, 2100) as $i) {
            $employee = (string) Str::uuid7();
            $employment = (string) Str::uuid7();
            $employees[] = ['id' => $employee, 'school_id' => $this->school->id, 'employee_number' => sprintf('BULK-%05d', $i), 'full_name' => "Bulk {$i}", 'record_status' => 'active', 'created_at' => $now, 'updated_at' => $now];
            $employments[] = ['id' => $employment, 'school_id' => $this->school->id, 'employee_id' => $employee, 'employment_type' => 'permanent', 'staff_type' => 'teaching', 'starts_on' => '2024-01-01', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now];
            $assignments[] = ['id' => (string) Str::uuid7(), 'school_id' => $this->school->id, 'employment_record_id' => $employment, 'position_id' => $this->position->id, 'department_id' => $this->department->id, 'starts_on' => '2024-01-01', 'is_primary' => true, 'created_at' => $now, 'updated_at' => $now];
        }
        foreach ([['employees', $employees], ['employment_records', $employments], ['employee_assignments', $assignments]] as [$table, $rows]) {
            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table($table)->insert($chunk);
            }
        }
        $this->template('bulk');

        // An interrupted run: the first batches committed, then the worker stopped.
        $run = app(TaskGenerationService::class)->run($this->school, batch: 700);
        $this->assertSame([2100, 2100, 0], [$run->examined, $run->created, $run->not_matched], 'no silent cap: every employment examined (batches of 700)');
        DB::table('workforce_task_generation_runs')->where('id', $run->id)->update(['status' => 'running', 'finished_at' => null, 'cursor' => null]);

        $resumed = app(TaskGenerationService::class)->run($this->school, batch: 500);
        $this->assertSame(2100, WorkforceTask::query()->count(), 'resuming never duplicates');
        $this->assertSame(2100, $resumed->existing - 0, 'the second pass finds every task already there');
    }

    #[Test]
    public function a_run_interrupted_by_a_failed_batch_resumes_from_its_cursor(): void
    {
        // HR.R1-C10 (TRR-7): the test above resets the cursor, so it proves idempotency, not a resume. The third
        // batch fails here, so two committed and the third rolled back, and the same run then finishes from there.
        foreach (range(1, 7) as $i) {
            $this->staff('teaching');
        }
        $this->template('resume');
        $real = app(SchoolOperationalGuard::class);
        $calls = 0;
        $failing = new class($real, $calls) extends SchoolOperationalGuard
        {
            public function __construct(private SchoolOperationalGuard $real, private int &$calls) {}

            public function requireOperational(string $schoolId): void
            {
                // The first call opens the run, so the fourth is the third batch.
                if (++$this->calls === 4) {
                    throw new \RuntimeException('the worker stopped');
                }
                $this->real->requireOperational($schoolId);
            }
        };
        $this->app->instance(SchoolOperationalGuard::class, $failing);
        $this->app->forgetInstance(TaskGenerationService::class);

        try {
            app(TaskGenerationService::class)->run($this->school, batch: 2);
            $this->fail('the third batch must fail');
        } catch (\RuntimeException $e) {
            $this->assertSame('the worker stopped', $e->getMessage());
        }
        $this->assertSame(4, WorkforceTask::query()->count(), 'two batches committed, the third left nothing');
        $interrupted = DB::table('workforce_task_generation_runs')->where('school_id', $this->school->id)->sole();
        $this->assertSame('running', $interrupted->status);
        $this->assertNotNull($interrupted->cursor);
        $this->assertSame(4, (int) $interrupted->examined);

        $this->app->instance(SchoolOperationalGuard::class, $real);
        $this->app->forgetInstance(TaskGenerationService::class);
        $resumed = app(TaskGenerationService::class)->run($this->school, batch: 2);

        $this->assertSame([$interrupted->id, 'completed'], [$resumed->id, $resumed->status], 'the same run finished');
        $this->assertSame([7, 7, 0], [$resumed->examined, $resumed->created, $resumed->existing], 'it continued from the cursor: nothing was examined twice');
        $this->assertSame(7, WorkforceTask::query()->count());
    }

    #[Test]
    public function an_administrator_assigns_to_anyone_current_and_a_team_manager_only_to_current_reports(): void
    {
        $managerUser = $this->createUserWithCapabilities($this->school, ['hr.tasks.team']);
        $manager = $this->staff('teaching', user: $managerUser);
        $report = $this->staff('teaching');
        $stranger = $this->staff('teaching');
        $this->setReportingLineFixture($report, $manager, '2025-01-01');
        $assign = app(TaskAssignmentService::class);

        $task = $assign->assign($this->school, $stranger->id, $this->assignTerms(), $this->hr);
        $this->assertSame('administrative', $task->assignment_path);

        $mine = $assign->assign($this->school, $report->id, $this->assignTerms(), $managerUser);
        $this->assertSame(['manager', $managerUser->id], [$mine->assignment_path, $mine->assigned_by_user_id]);
        $this->assertSame('TASK_NOT_FOUND', $this->code(fn () => $assign->assign($this->school, $stranger->id, $this->assignTerms(), $managerUser)), 'not their report');

        $leaveOnly = $this->createUserWithCapabilities($this->school, ['hr.leave.approve']);
        $this->setReportingLineFixture($stranger, $this->staff('teaching', user: $leaveOnly), '2025-01-01');
        $this->assertSame('TASK_NOT_FOUND', $this->code(fn () => $assign->assign($this->school, $stranger->id, $this->assignTerms(), $leaveOnly)), 'the Leave line_manager key grants no task authority');

        $admin = $this->ownAdmin($this->staff('teaching'));
        $ownEmployment = EmploymentRecord::query()->whereIn('employee_id', Employee::query()->where('user_id', $admin->id)->select('id'))->value('id');
        $this->assertSame('TASK_SELF_ADMINISTRATION', $this->code(fn () => $assign->assign($this->school, $ownEmployment, $this->assignTerms(), $admin)));
    }

    private function ownAdmin(EmploymentRecord $employment): User
    {
        $user = $this->createUserWithCapabilities($this->school, ['hr.tasks.manage']);
        DB::table('employees')->where('id', $employment->employee_id)->update(['user_id' => $user->id]);

        return $user;
    }

    #[Test]
    public function nobody_is_assigned_after_separation_or_from_another_school(): void
    {
        $leaver = $this->staff('teaching');
        app(EmploymentService::class)->end($leaver, '2026-06-14', $this->createUserWithCapabilities($this->school, ['hr.employees.assignments.manage']));
        $this->assertSame('TASK_ASSIGNEE_NOT_CURRENT', $this->code(fn () => app(TaskAssignmentService::class)->assign($this->school, $leaver->id, $this->assignTerms(), $this->hr)));

        $other = $this->createSchool();
        $foreign = app(TenantContext::class)->withSchool($other, fn () => $this->createEmploymentRecord($this->createEmployee($other), ['starts_on' => '2024-01-01', 'status' => 'active']));
        $this->assertSame('TASK_NOT_FOUND', $this->code(fn () => app(TaskAssignmentService::class)->assign($this->school, $foreign->id, $this->assignTerms(), $this->hr)));
    }

    #[Test]
    public function self_tasks_complete_on_submission_and_reviewed_tasks_wait_for_another_person(): void
    {
        $user = $this->createUserWithCapabilities($this->school, ['hr.tasks.self']);
        $employment = $this->staff('teaching', user: $user);
        $assign = app(TaskAssignmentService::class);
        $flow = app(TaskWorkflowService::class);

        $self = $assign->assign($this->school, $employment->id, $this->assignTerms(['completion_mode' => 'self']), $this->hr);
        $done = $flow->submit($this->school, $self->id, $self->version, 'Done and checked.', $user);
        $this->assertSame(['completed', $user->id], [$done->status, $done->completed_by_user_id]);

        $reviewed = $assign->assign($this->school, $employment->id, $this->assignTerms(), $this->hr);
        $submitted = $flow->submit($this->school, $reviewed->id, $reviewed->version, null, $user);
        $this->assertSame('submitted', $submitted->status);
        $this->assertSame('TASK_NOT_FOUND', $this->code(fn () => $flow->review($this->school, $submitted->id, $submitted->version, 'complete', null, $user)), 'the assignee never reviews');

        $this->assertSame('TASK_FEEDBACK_REQUIRED', $this->code(fn () => $flow->review($this->school, $submitted->id, $submitted->version, 'return', null, $this->hr)));
        $returned = $flow->review($this->school, $submitted->id, $submitted->version, 'return', 'Add the June dates.', $this->hr);
        $this->assertSame(['returned', 1], [$returned->status, $returned->return_count]);
        $again = $flow->submit($this->school, $returned->id, $returned->version, 'Dates added.', $user);
        $this->assertSame('TASK_ALREADY_RETURNED', $this->code(fn () => $flow->review($this->school, $again->id, $again->version, 'return', 'Again', $this->hr)));
        $this->assertSame('TASK_STALE', $this->code(fn () => $flow->review($this->school, $again->id, $returned->version, 'complete', null, $this->hr)));
        $final = $flow->review($this->school, $again->id, $again->version, 'complete', 'Thanks.', $this->hr);
        $this->assertSame(['completed', $this->hr->id], [$final->status, $final->completed_by_user_id]);

        $notes = DB::table('workforce_task_events')->where('task_id', $reviewed->id)->orderBy('occurred_at')->orderBy('id')->pluck('note', 'kind');
        $this->assertSame('Add the June dates.', $notes['returned'], 'history is kept, never overwritten');
        $this->assertSame('TASK_NOT_SUBMITTABLE', $this->code(fn () => $flow->submit($this->school, $final->id, $final->version, null, $user)));
    }

    #[Test]
    public function reassignment_and_cancellation_keep_history_and_never_one_own_task(): void
    {
        $first = $this->staff('teaching');
        $second = $this->staff('teaching');
        $task = app(TaskAssignmentService::class)->assign($this->school, $first->id, $this->assignTerms(), $this->hr);

        $moved = app(TaskAssignmentService::class)->reassign($this->school, $task->id, $task->version, $second->id, $this->hr);
        $old = $task->refresh();
        $this->assertSame(['cancelled', 'reassigned'], [$old->status, $old->cancel_reason]);
        $this->assertSame([$second->id, $task->id, $task->title], [$moved->employment_record_id, $moved->reassigned_from_task_id, $moved->title]);

        $this->assertSame('TASK_CANCEL_REASON', $this->code(fn () => app(TaskWorkflowService::class)->cancel($this->school, $moved->id, $moved->version, TaskCancelReason::REASSIGNED, $this->hr)));
        $cancelled = app(TaskWorkflowService::class)->cancel($this->school, $moved->id, $moved->version, TaskCancelReason::NO_LONGER_NEEDED, $this->hr);
        $this->assertSame('cancelled', $cancelled->status);
        $this->assertSame('TASK_NOT_OPEN', $this->code(fn () => app(TaskWorkflowService::class)->cancel($this->school, $moved->id, $cancelled->version, TaskCancelReason::NO_LONGER_NEEDED, $this->hr)));
    }

    #[Test]
    public function reads_show_each_viewer_only_their_own_part(): void
    {
        $managerUser = $this->createUserWithCapabilities($this->school, ['hr.tasks.team', 'hr.tasks.self']);
        $manager = $this->staff('teaching', user: $managerUser);
        $reportUser = $this->createUserWithCapabilities($this->school, ['hr.tasks.self']);
        $report = $this->staff('teaching', user: $reportUser);
        $this->setReportingLineFixture($report, $manager, '2025-01-01');
        $other = $this->staff('teaching');
        $assign = app(TaskAssignmentService::class);
        $assign->assign($this->school, $report->id, $this->assignTerms(['due_on' => '2026-06-15']), $managerUser);
        $assign->assign($this->school, $other->id, $this->assignTerms(), $this->hr);
        $reads = app(TaskReadService::class);

        $this->assertSame(1, $reads->mine($this->school, $reportUser, 'open', 1)['total']);
        $this->assertSame(1, $reads->team($this->school, $managerUser, 'open', 1)['total'], 'direct reports only');
        $this->assertSame(1, $reads->assignedByMe($this->school, $managerUser, 'open', 1)['total']);
        $this->assertSame('TASK_NOT_FOUND', $this->code(fn () => $reads->detail($this->school, $reportUser, WorkforceTask::query()->where('employment_record_id', $other->id)->value('id'))));

        Carbon::setTestNow('2026-06-16 10:00:00');
        $monitor = $reads->monitor($this->school, $this->hr, ['status' => 'overdue'], 1);
        $this->assertSame([1, 1], [$monitor['total'], $monitor['counts']['overdue']], 'overdue is derived from the due date, never stored');
        $this->assertSame(2, $monitor['counts']['assigned']);
        $this->assertSame('FORBIDDEN', $this->code(fn () => $reads->monitor($this->school, $reportUser, [], 1)));
    }

    #[Test]
    public function a_rehire_never_brings_back_the_old_employments_tasks(): void
    {
        $user = $this->createUserWithCapabilities($this->school, ['hr.tasks.self']);
        $old = $this->staff('teaching', user: $user);
        app(TaskAssignmentService::class)->assign($this->school, $old->id, $this->assignTerms(), $this->hr);
        app(EmploymentService::class)->end($old, '2026-06-15', $this->createUserWithCapabilities($this->school, ['hr.employees.assignments.manage']));
        Carbon::setTestNow('2026-06-20 10:00:00');
        $new = $this->createEmploymentRecord(Employee::query()->findOrFail($old->employee_id), ['starts_on' => '2026-06-20', 'status' => 'active', 'staff_type' => 'teaching']);

        $this->assertSame(0, app(TaskReadService::class)->mine($this->school, $user, 'all', 1)['total'], 'the new employment starts with no tasks');
        $this->assertSame('assigned', WorkforceTask::query()->where('employment_record_id', $old->id)->value('status'), 'the old task is kept, untouched');
        $this->assertNotSame($old->id, $new->id);
    }

    #[Test]
    public function a_separation_case_shows_open_tasks_for_review(): void
    {
        $employment = $this->staff('teaching');
        app(TaskAssignmentService::class)->assign($this->school, $employment->id, $this->assignTerms(), $this->hr);
        $case = app(SeparationCaseService::class)->record($this->school, $employment->id,
            SeparationReason::RESIGNATION, '2026-06-15', '2026-07-31', $this->createUserWithCapabilities($this->school, ['hr.separations.manage']));

        $tasks = collect(app(SeparationObligations::class)->forCase($this->school, $case))->firstWhere('code', 'staff_tasks_open');

        $this->assertSame(['attention', 1], [$tasks->state, $tasks->count]);
    }

    #[Test]
    public function the_database_refuses_rewritten_terms_skipped_review_and_runtime_deletion(): void
    {
        $user = $this->createUserWithCapabilities($this->school, ['hr.tasks.self']);
        $employment = $this->staff('teaching', user: $user);
        $task = app(TaskAssignmentService::class)->assign($this->school, $employment->id, $this->assignTerms(), $this->hr);

        foreach ([
            "UPDATE workforce_tasks SET title = 'Changed', version = version + 1 WHERE id = ?",
            "UPDATE workforce_tasks SET status = 'completed', completed_by_user_id = '{$user->id}', completed_at = now(), version = version + 1 WHERE id = ?",
            "UPDATE workforce_tasks SET status = 'submitted' WHERE id = ?",
        ] as $sql) {
            try {
                DB::transaction(fn () => DB::update($sql, [$task->id]));
                $this->fail("accepted: {$sql}");
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        }
        try {
            DB::transaction(fn () => DB::delete('DELETE FROM workforce_tasks WHERE id = ?', [$task->id]));
            $this->fail('runtime deletion');
        } catch (QueryException $e) {
            $this->assertStringContainsString('permission denied', $e->getMessage());
        }
        try {
            DB::transaction(fn () => DB::update("UPDATE workforce_task_template_versions SET title = 'x'"));
            $this->fail('a version was edited');
        } catch (QueryException $e) {
            $this->assertStringContainsString('permission denied', $e->getMessage());
        }

        try {
            DB::transaction(fn () => DB::table('workforce_task_events')->insert(['id' => (string) Str::uuid7(), 'school_id' => $this->school->id,
                'task_id' => $task->id, 'kind' => 'returned', 'actor_user_id' => $user->id, 'note' => 'mine', 'occurred_at' => now()]));
            $this->fail('the assignee recorded a return of their own task');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }

        $checks = collect(app(DatabaseRoleVerifier::class)->verify())->keyBy('code');
        $this->assertSame(CheckResult::PASS, $checks['hr_task_invariants']->status);
    }
}
