<?php

namespace Tests\Feature\HR;

use App\Domain\HR\Infrastructure\Employee;
use App\Domain\HR\Infrastructure\EmployeeNote;
use App\Domain\WorkforceTasks\Application\TaskAssignmentService;
use App\Domain\WorkforceTasks\Application\TaskCatalogueService;
use App\Domain\WorkforceTasks\Infrastructure\WorkforceTask;
use App\Models\School;
use App\Models\User;
use App\Support\Tenancy\SchoolNotOperationalException;
use App\Support\Tenancy\SchoolTimezone;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\Concerns\ForcesConcurrentOverlap;
use Tests\TestCase;

/**
 * HR.R1-C13 (WO-1, C11-N1, C12-N1): the race rule 86 exists for, on the writers C11 left
 * out. Two real sessions, the holder held open and the contender observed blocked, on
 * committed data. The School's FOR SHARE orders a writer against a suspension whichever
 * comes first, and a writer waiting on the School holds none of its own rows yet.
 */
class HrProfileSchoolRaceTest extends TestCase
{
    use CreatesTenancyFixtures, ForcesConcurrentOverlap;

    /** @var array<int, string> */
    protected $connectionsToTransact = [];

    private School $school;

    private User $hr;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->school = $this->createSchool();
        $this->hr = $this->createUserWithCapabilities($this->school, [
            'hr.employees.notes.view', 'hr.employees.notes.manage', 'hr.employees.assignments.manage', 'hr.tasks.view', 'hr.tasks.configure', 'hr.tasks.manage',
        ]);
        $this->employee = $this->createEmployee($this->school);
    }

    protected function tearDown(): void
    {
        $this->purgeSchoolsAsAdmin([$this->school->id]);
        parent::tearDown();
    }

    /** @return list<string> */
    private function suspend(): array
    {
        return ['php', __DIR__.'/../../Support/archived-identity-op.php', 'suspend', $this->school->id];
    }

    /** @return list<string> */
    private function op(string ...$arguments): array
    {
        return ['php', __DIR__.'/../../Support/hr-lifecycle-op.php', $this->school->id, ...$arguments];
    }

    private function schoolStatus(): string
    {
        return (string) DB::connection('pgsql_admin')->table('schools')->where('id', $this->school->id)->value('status');
    }

    private function rows(string $table): int
    {
        return DB::connection('pgsql_admin')->table($table)->where('school_id', $this->school->id)->count();
    }

    private function note(): EmployeeNote
    {
        return app(TenantContext::class)->withSchool($this->school, fn () => $this->createEmployeeNote($this->employee, ['author_user_id' => $this->hr->id]));
    }

    private function task(): WorkforceTask
    {
        $category = app(TaskCatalogueService::class)->createCategory($this->school, 'admin', 'Administrative', $this->hr);
        $employment = $this->createEmploymentRecord($this->employee, ['status' => 'active', 'starts_on' => '2024-01-01', 'ends_on' => null, 'staff_type' => 'teaching']);
        $this->createEmployeeAssignment($employment, $this->createPosition($this->school), ['starts_on' => '2024-01-01', 'ends_on' => null, 'is_primary' => true]);
        $today = SchoolTimezone::today($this->school);

        return app(TaskAssignmentService::class)->assign($this->school, $employment->id, [
            'title' => 'Prepare the board', 'category_id' => $category->id, 'completion_mode' => 'reviewed', 'available_on' => $today, 'due_on' => $today,
        ], $this->hr);
    }

    /** A row the contender would lock takes a NOWAIT lock while it waits: it holds nothing of its own yet. */
    private function rowIsFree(string $table, string $id): void
    {
        DB::connection('pgsql_admin')->transaction(function () use ($table, $id): void {
            $this->assertNotNull(DB::connection('pgsql_admin')->selectOne("select id from {$table} where id = ? for update nowait", [$id]));
        });
    }

    #[Test]
    public function a_note_waiting_on_a_suspension_is_refused_once_it_commits(): void
    {
        $notes = $this->rows('employee_notes');

        [$holder, $contender] = $this->raceWithHeldHolder($this->suspend(), $this->op('note', $this->employee->id, $this->hr->id));

        $this->assertSame('ok:1', $holder);
        $this->assertSame('rejected:'.SchoolNotOperationalException::class, $contender);
        $this->assertSame('suspended', $this->schoolStatus());
        $this->assertSame($notes, $this->rows('employee_notes'));
    }

    #[Test]
    public function a_suspension_waiting_on_a_note_lands_after_it(): void
    {
        $notes = $this->rows('employee_notes');

        [$holder, $contender] = $this->raceWithHeldHolder($this->op('note', $this->employee->id, $this->hr->id), $this->suspend());

        $this->assertStringStartsWith('ok:', $holder);
        $this->assertSame('ok:1', $contender);
        $this->assertSame('suspended', $this->schoolStatus());
        $this->assertSame($notes + 1, $this->rows('employee_notes'));
    }

    #[Test]
    public function an_archive_waiting_on_a_suspension_is_refused_and_holds_no_row_while_it_waits(): void
    {
        $note = $this->note();

        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->suspend(),
            $this->op('note-archive', $this->employee->id, $note->id, $this->hr->id),
            fn () => $this->rowIsFree('employee_notes', $note->id),
        );

        $this->assertSame('ok:1', $holder);
        $this->assertSame('rejected:'.SchoolNotOperationalException::class, $contender);
        $this->assertNull(DB::connection('pgsql_admin')->table('employee_notes')->where('id', $note->id)->value('archived_at'));
    }

    #[Test]
    public function a_suspension_waiting_on_an_archive_lands_after_it(): void
    {
        $note = $this->note();

        [$holder, $contender] = $this->raceWithHeldHolder($this->op('note-archive', $this->employee->id, $note->id, $this->hr->id), $this->suspend());

        $this->assertStringStartsWith('ok:', $holder);
        $this->assertSame('ok:1', $contender);
        $this->assertNotNull(DB::connection('pgsql_admin')->table('employee_notes')->where('id', $note->id)->value('archived_at'));
    }

    #[Test]
    public function a_catalogue_write_waiting_on_a_suspension_is_refused_once_it_commits(): void
    {
        $categories = $this->rows('workforce_task_categories');

        [$holder, $contender] = $this->raceWithHeldHolder($this->suspend(), $this->op('task-category', $this->hr->id));

        $this->assertSame('ok:1', $holder);
        $this->assertSame('rejected:'.SchoolNotOperationalException::class, $contender);
        $this->assertSame($categories, $this->rows('workforce_task_categories'));
    }

    #[Test]
    public function a_suspension_waiting_on_a_catalogue_write_lands_after_it(): void
    {
        $categories = $this->rows('workforce_task_categories');

        [$holder, $contender] = $this->raceWithHeldHolder($this->op('task-category', $this->hr->id), $this->suspend());

        $this->assertStringStartsWith('ok:', $holder);
        $this->assertSame('ok:1', $contender);
        $this->assertSame($categories + 1, $this->rows('workforce_task_categories'));
    }

    #[Test]
    public function an_administrative_cancel_waiting_on_a_suspension_is_refused_and_holds_no_task_while_it_waits(): void
    {
        $task = $this->task();

        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->suspend(),
            $this->op('task-cancel', $task->id, (string) $task->version, $this->hr->id),
            fn () => $this->rowIsFree('workforce_tasks', $task->id),
        );

        $this->assertSame('ok:1', $holder);
        $this->assertSame('rejected:'.SchoolNotOperationalException::class, $contender);
        $this->assertSame($task->status, DB::connection('pgsql_admin')->table('workforce_tasks')->where('id', $task->id)->value('status'));
    }

    #[Test]
    public function a_suspension_waiting_on_an_administrative_cancel_lands_after_it(): void
    {
        $task = $this->task();

        [$holder, $contender] = $this->raceWithHeldHolder($this->op('task-cancel', $task->id, (string) $task->version, $this->hr->id), $this->suspend());

        $this->assertSame('ok:cancelled', $holder);
        $this->assertSame('ok:1', $contender);
        $this->assertSame('cancelled', DB::connection('pgsql_admin')->table('workforce_tasks')->where('id', $task->id)->value('status'));
    }
}
