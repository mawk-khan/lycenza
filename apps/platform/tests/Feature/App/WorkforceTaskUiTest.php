<?php

namespace Tests\Feature\App;

use App\Domain\WorkforceTasks\Application\TaskAssignmentService;
use App\Domain\WorkforceTasks\Application\TaskCatalogueService;
use App\Domain\WorkforceTasks\Infrastructure\WorkforceTask;
use App\Models\School;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * HR.13: the staff task pages over HTTP -- each route's capability floor
 * (allow and deny) and the assignee's submission through the browser.
 */
class WorkforceTaskUiTest extends TestCase
{
    use CreatesTenancyFixtures;

    private School $school;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-06-15 10:00:00');
        $this->school = $this->createSchool();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function as(User $user): static
    {
        $this->actingAs($user)->post("/app/schools/{$this->school->id}/activate");

        return $this;
    }

    #[Test]
    public function each_page_needs_its_key_and_an_employee_submits_through_the_browser(): void
    {
        $hr = $this->createUserWithCapabilities($this->school, ['hr.tasks.view', 'hr.tasks.configure', 'hr.tasks.manage']);
        $staffUser = $this->createUserWithCapabilities($this->school, ['hr.tasks.self']);
        $nobody = $this->createUserWithCapabilities($this->school, ['hr.employees.view']);
        $leaveManager = $this->createUserWithCapabilities($this->school, ['hr.leave.approve']);
        $employment = $this->createEmploymentRecord($this->createEmployee($this->school, ['user_id' => $staffUser->id]), ['starts_on' => '2024-01-01', 'status' => 'active', 'staff_type' => 'teaching']);
        $category = app(TaskCatalogueService::class)->createCategory($this->school, 'gen', 'General', $hr);
        $task = app(TaskAssignmentService::class)->assign($this->school, $employment->id, [
            'title' => 'Update the class register', 'category_id' => $category->id, 'completion_mode' => 'self', 'available_on' => '2026-06-15', 'due_on' => '2026-06-15',
        ], $hr);

        foreach (['/app/hr/tasks', '/app/hr/tasks/catalogue', '/app/hr/tasks/assign', '/app/hr/tasks/team', '/app/hr/tasks/assigned-by-me', '/app/my-tasks'] as $url) {
            $this->as($nobody)->get($url)->assertForbidden();
            $this->as($leaveManager)->get($url)->assertForbidden();
        }
        $this->as($hr)->get('/app/hr/tasks')->assertOk()->assertInertia(fn (AssertableInertia $p) => $p->component('App/HR/Tasks/Monitor')->where('result.total', 1));
        $this->as($hr)->get('/app/hr/tasks/catalogue')->assertOk();
        $this->as($staffUser)->get('/app/hr/tasks/catalogue')->assertForbidden();

        $this->as($staffUser)->get('/app/my-tasks')->assertOk()->assertInertia(fn (AssertableInertia $p) => $p->component('App/HR/Tasks/Mine')->where('result.rows.0.title', 'Update the class register'));
        $this->as($staffUser)->get("/app/hr/tasks/{$task->id}")->assertOk()->assertInertia(fn (AssertableInertia $p) => $p->where('task.actions.submit', true)->where('task.actions.cancel', false));
        $this->as($nobody)->get("/app/hr/tasks/{$task->id}")->assertForbidden();

        $this->as($staffUser)->post("/app/my-tasks/{$task->id}/submit", ['version' => 1, 'remarks' => '<script>x</script> done'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('completed', app(TenantContext::class)->withSchool($this->school, fn () => WorkforceTask::query()->whereKey($task->id)->value('status')));
        $this->as($staffUser)->post("/app/my-tasks/{$task->id}/submit", ['version' => 2])->assertSessionHasErrors('refusal');
        $this->as($staffUser)->post("/app/hr/tasks/{$task->id}/cancel", ['version' => 2, 'reason' => 'no_longer_needed', 'confirm' => '1'])->assertForbidden();
    }
}
