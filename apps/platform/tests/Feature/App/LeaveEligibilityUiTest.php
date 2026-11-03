<?php

namespace Tests\Feature\App;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Leave\Concerns\CreatesLeaveFixtures;
use Tests\TestCase;

/**
 * HR.9 (ADR 0065 §28): the policy editor, its draft preview and the
 * eligibility page -- every answer from the server, each action gated by
 * its own capability, a refusal a form error with its stable code.
 */
class LeaveEligibilityUiTest extends TestCase
{
    use CreatesLeaveFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-05 06:00:00');
    }

    private function actor(array $w, ?User $user = null): static
    {
        return $this->actingAs($user ?? $w['admin'])->withHeader('X-School-Id', $w['school']->id);
    }

    /** @return array<string, mixed> */
    private function version(array $w, array $overrides = []): array
    {
        return array_merge([
            'leave_type_id' => $w['type']->id, 'name' => 'Teaching v2', 'annual_allocation_units' => 20, 'carry_forward_allowed' => false,
            'supersedes_policy_id' => $w['policy']->id, 'min_notice_days' => 3, 'max_backdate_days' => null, 'max_units_per_request' => null,
            'eligibility_rules' => [['staff_type' => 'teaching', 'employee_category_id' => null, 'department_id' => null]],
        ], $overrides);
    }

    #[Test]
    public function the_configuration_page_offers_the_editor_its_dimensions_and_saves_a_version(): void
    {
        $w = $this->leaveWorld();
        $category = $this->createEmployeeCategory($w['school']);
        $this->createEmployeeCategory($w['school'], ['status' => 'inactive']);
        $this->createDepartment($w['school']);

        $this->actor($w)->get('/app/leave/configuration')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('App/Leave/Configuration')->has('staffTypes', 3)->has('employeeCategories', 1)->has('departments', 1)
            ->where('employeeCategories.0.id', $category->id)->where('today', '2026-10-05')
            ->where('policies.0.minNoticeDays', null)->where('policies.0.eligibilityRules', []));

        $this->actor($w)->post('/app/leave/policies', $this->version($w))->assertSessionHasNoErrors();
        $this->actor($w)->get('/app/leave/configuration')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('policies.1.minNoticeDays', 3)->where('policies.1.eligibilityRules.0.staffType', 'teaching')->where('policies.0.status', 'retired'));

        // A refusal is a form error carrying its stable code.
        $this->actor($w)->post('/app/leave/policies', $this->version($w, ['supersedes_policy_id' => null, 'name' => 'Overlap']))
            ->assertSessionHasErrors(['leave' => 'An employment could match these eligibility rules and another active policy of this leave type. Supersede or retire that policy, or narrow the rules. (LEAVE_ELIGIBILITY_OVERLAP)']);
    }

    #[Test]
    public function the_draft_preview_is_server_json_for_the_configure_capability_only(): void
    {
        $w = $this->leaveWorld();
        $teacher = $this->createEmploymentRecord($this->createEmployee($w['school']), ['status' => 'active', 'starts_on' => '2024-01-01', 'ends_on' => null, 'staff_type' => 'teaching']);
        $body = ['leave_type_id' => $w['type']->id, 'date' => '2026-10-05', 'name' => 'Draft', 'annual_allocation_units' => 20,
            'eligibility_rules' => [['staff_type' => 'teaching']]];

        $this->actor($w)->postJson('/app/leave/policies/preview', $body)->assertOk()
            ->assertJsonPath('data.counts.eligible', 1)->assertJsonPath('data.counts.assigned', 1);
        $rows = collect($this->actor($w)->postJson('/app/leave/policies/preview', $body)->json('data.rows'))->keyBy('employmentRecordId');
        $this->assertSame('draft', $rows[$teacher->id]['policyId']);
        $this->assertSame('assigned', $rows[$w['employment']->id]['outcome']);

        $this->actor($w, $this->createUserWithCapabilities($w['school'], ['hr.leave.view', 'hr.leave.manage']))->postJson('/app/leave/policies/preview', $body)->assertForbidden();
        $this->actor($w)->postJson('/app/leave/policies/preview', array_merge($body, ['eligibility_rules' => [['staff_type' => 'x']]]))->assertStatus(422);
        $this->assertSame(1, $this->inSchool($w['school'], fn () => DB::table('leave_policies')->count()), 'a preview writes nothing');
    }

    #[Test]
    public function the_eligibility_page_shows_the_server_answer_and_only_a_manager_runs_it(): void
    {
        $w = $this->leaveWorld();
        $this->actor($w)->post('/app/leave/policies', $this->version($w, ['eligibility_rules' => [['staff_type' => null]]]))->assertSessionHasNoErrors();
        $newcomer = $this->createEmploymentRecord($this->createEmployee($w['school']), ['status' => 'active', 'starts_on' => '2026-09-01', 'ends_on' => null]);
        $viewer = $this->createUserWithCapabilities($w['school'], ['hr.leave.view']);

        $this->actor($w, $viewer)->get("/app/leave/eligibility?leave_type_id={$w['type']->id}&outcome=eligible")->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('App/Leave/Eligibility')->where('canManage', false)
                ->where('result.counts.eligible', 1)->where('result.counts.assigned', 1)->where('outcome', 'eligible')->has('result.versions', 1));
        $this->actor($w, $this->createUserWithCapabilities($w['school'], ['hr.leave.approve']))->get('/app/leave/eligibility')->assertForbidden();

        $this->actor($w, $viewer)->post('/app/leave/eligibility-runs', ['leave_type_id' => $w['type']->id, 'as_of' => '2026-10-05'])->assertForbidden();
        $this->actor($w)->post('/app/leave/eligibility-runs', ['leave_type_id' => $w['type']->id, 'as_of' => '2026-10-05'])
            ->assertSessionHasNoErrors()->assertSessionHas('status', 'Eligibility run complete: 1 assigned, 0 not assigned.');
        $this->assertSame(1, $this->inSchool($w['school'], fn () => DB::table('leave_policy_assignments')->where('employment_record_id', $newcomer->id)->count()));

        $this->actor($w)->get('/app/leave/eligibility?leave_type_id=not-a-uuid')->assertSessionHasErrors('leave_type_id');
    }
}
