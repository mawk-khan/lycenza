<?php

namespace Tests\Feature\App;

use App\Domain\HR\Application\EmploymentService;
use App\Domain\HR\Infrastructure\EmploymentRecord;
use App\Domain\HR\Infrastructure\EmploymentSeparationCase;
use App\Models\School;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\Concerns\ProvidesSensitiveActionMfa;
use Tests\TestCase;

/**
 * HR.12: the separation pages over HTTP -- every route's capability floor
 * (allow and deny), the whole flow through the browser endpoints with fresh
 * MFA, refusals surfaced as refusals (never a 500), and the profile's direct
 * end limited to a death.
 */
class SeparationUiTest extends TestCase
{
    use CreatesTenancyFixtures, ProvidesSensitiveActionMfa;

    private School $school;

    private User $hr;

    private User $approver;

    private EmploymentRecord $employment;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-06-15 10:00:00');
        $this->school = $this->createSchool();
        $this->hr = $this->createUserWithCapabilities($this->school, [
            'hr.employees.view', 'hr.employees.assignments.view', 'hr.employees.assignments.manage',
            'hr.separations.view', 'hr.separations.manage', 'hr.separations.configure',
        ]);
        $this->approver = $this->createUserWithCapabilities($this->school, ['hr.separations.view', 'hr.separations.approve']);
        $this->employment = app(EmploymentService::class)->create($this->createEmployee($this->school), ['employment_type' => 'permanent', 'starts_on' => '2022-01-01'], $this->hr);
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

    private function caseId(): string
    {
        return (string) app(TenantContext::class)->withSchool($this->school, fn () => EmploymentSeparationCase::query()->value('id'));
    }

    #[Test]
    public function the_whole_flow_runs_through_the_browser_endpoints(): void
    {
        $this->as($this->hr)->post('/app/hr/separations', [
            'employment_record_id' => $this->employment->id, 'reason' => 'resignation', 'notified_on' => '2026-06-15', 'planned_last_day' => '2026-06-15',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $id = $this->caseId();

        $this->as($this->approver)->get("/app/hr/separations/{$id}")->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('App/HR/Separations/Show')->where('case.status', 'requested')->where('case.actions.decide', true));
        $this->as($this->hr)->get("/app/hr/separations/{$id}")->assertInertia(fn (AssertableInertia $page) => $page
            ->where('case.actions.decide', false)->where('case.actions.decideBlockedAsRequester', true));

        $this->as($this->approver)->post("/app/hr/separations/{$id}/approve", ['version' => 1, 'confirm' => '1'])->assertSessionHasErrors('mfa_code');
        $this->as($this->approver)->post("/app/hr/separations/{$id}/approve", ['version' => 1, 'confirm' => '1', 'mfa_code' => $this->freshMfaCode($this->approver)])
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->as($this->hr)->post("/app/hr/separations/{$id}/finalize", ['version' => 2, 'confirm' => '1', 'mfa_code' => $this->freshMfaCode($this->hr)])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('separated', app(TenantContext::class)->withSchool($this->school, fn () => EmploymentRecord::query()->whereKey($this->employment->id)->value('status')));

        $this->as($this->hr)->get('/app/hr/separations?status=finalized')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('App/HR/Separations/Index')->where('result.total', 1)->where('result.rows.0.status', 'finalized'));
    }

    #[Test]
    public function every_page_and_action_is_refused_without_its_capability(): void
    {
        $nobody = $this->createUserWithCapabilities($this->school, ['hr.employees.view']);
        $this->as($this->hr)->post('/app/hr/separations', [
            'employment_record_id' => $this->employment->id, 'reason' => 'resignation', 'notified_on' => '2026-06-15', 'planned_last_day' => '2026-07-31',
        ]);
        $id = $this->caseId();

        $this->as($nobody)->get('/app/hr/separations')->assertForbidden();
        $this->as($nobody)->get("/app/hr/separations/{$id}")->assertForbidden();
        $this->as($this->approver)->get("/app/hr/separations/create?employment={$this->employment->id}")->assertForbidden();
        $this->as($this->approver)->post('/app/hr/separations', ['employment_record_id' => $this->employment->id, 'reason' => 'resignation', 'notified_on' => '2026-06-15', 'planned_last_day' => '2026-07-31'])->assertForbidden();
        $this->as($this->hr)->post("/app/hr/separations/{$id}/approve", ['version' => 1, 'confirm' => '1'])->assertForbidden();
        $this->as($this->hr)->post("/app/hr/separations/{$id}/reject", ['version' => 1, 'confirm' => '1'])->assertForbidden();
        $this->as($this->approver)->post("/app/hr/separations/{$id}/withdraw", ['version' => 1, 'confirm' => '1'])->assertForbidden();
        $this->as($this->approver)->get('/app/hr/separations/requirements')->assertForbidden();
        $this->as($this->hr)->post("/app/hr/separations/{$id}/access-retained", ['version' => 1, 'confirm' => '1'])->assertForbidden();

        $this->as($this->hr)->get('/app/hr/separations/requirements')->assertOk();
        $this->as($this->hr)->get("/app/hr/separations/create?employment={$this->employment->id}")->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('recordable', false)->where('liveCaseId', $id));
    }

    #[Test]
    public function a_refusal_comes_back_as_a_refusal_and_another_schools_id_is_not_found(): void
    {
        $this->as($this->hr)->post('/app/hr/separations', [
            'employment_record_id' => $this->employment->id, 'reason' => 'resignation', 'notified_on' => '2026-06-15', 'planned_last_day' => '2026-07-31',
        ]);
        $id = $this->caseId();

        $this->as($this->hr)->post('/app/hr/separations', [
            'employment_record_id' => $this->employment->id, 'reason' => 'resignation', 'notified_on' => '2026-06-15', 'planned_last_day' => '2026-07-31',
        ])->assertRedirect()->assertSessionHasErrors('refusal');

        $other = $this->createSchool();
        $otherViewer = $this->createUserWithCapabilities($other, ['hr.separations.view']);
        $this->actingAs($otherViewer)->post("/app/schools/{$other->id}/activate");
        $this->get("/app/hr/separations/{$id}")->assertNotFound();
    }

    #[Test]
    public function the_profile_direct_end_records_only_a_death_with_fresh_mfa_and_confirmation(): void
    {
        $base = "/app/hr/employees/{$this->employment->employee_id}/employment-records/{$this->employment->id}/end";

        $this->as($this->hr)->post($base, ['ends_on' => '2026-06-15', 'status' => 'separated', 'confirm' => '1', 'mfa_code' => $this->freshMfaCode($this->hr)])
            ->assertSessionHasErrors('refusal');
        $this->as($this->hr)->post($base, ['ends_on' => '2026-06-15', 'status' => 'deceased'])->assertSessionHasErrors('confirm');
        $this->assertNull(app(TenantContext::class)->withSchool($this->school, fn () => EmploymentRecord::query()->whereKey($this->employment->id)->value('ends_on')));

        $this->as($this->hr)->post($base, ['ends_on' => '2026-06-15', 'status' => 'deceased', 'confirm' => '1', 'mfa_code' => $this->freshMfaCode($this->hr)])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('deceased', app(TenantContext::class)->withSchool($this->school, fn () => EmploymentRecord::query()->whereKey($this->employment->id)->value('status')));
    }
}
