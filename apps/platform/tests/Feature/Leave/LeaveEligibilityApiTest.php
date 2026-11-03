<?php

namespace Tests\Feature\Leave;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Leave\Concerns\CreatesLeaveFixtures;
use Tests\TestCase;

/**
 * HR.9 (ADR 0065 §28): the policy rules and eligibility API -- typed input
 * (no mass assignment), each operation behind its own capability (allow AND
 * deny), the run idempotent (replay and conflict), and one tenant-safe 404.
 */
class LeaveEligibilityApiTest extends TestCase
{
    use CreatesLeaveFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-05 06:00:00');
    }

    private function as(User $user, ?string $key = null): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeader('Authorization', 'Bearer '.$user->createToken('test-device')->plainTextToken)
            ->withHeader('Idempotency-Key', $key ?? (string) Str::uuid());
    }

    private function url(array $w, string $path): string
    {
        return "/api/v1/schools/{$w['school']->id}/leave{$path}";
    }

    /** @return array<string, mixed> */
    private function version(array $w, array $overrides = []): array
    {
        return array_merge([
            'leave_type_id' => $w['type']->id, 'name' => 'Teaching v2', 'annual_allocation_units' => 20, 'carry_forward_allowed' => false,
            'supersedes_policy_id' => $w['policy']->id, 'min_notice_days' => 2, 'max_backdate_days' => 0, 'max_units_per_request' => 10,
            'eligibility_rules' => [['staff_type' => 'teaching'], ['staff_type' => 'non_teaching', 'employee_category_id' => null]],
        ], $overrides);
    }

    #[Test]
    public function a_version_is_created_with_its_rules_and_read_back(): void
    {
        $w = $this->leaveWorld();

        $created = $this->as($w['admin'])->postJson($this->url($w, '/policies'), $this->version($w))->assertCreated()
            ->assertJsonPath('data.minNoticeDays', 2)->assertJsonPath('data.maxBackdateDays', 0)->assertJsonPath('data.maxUnitsPerRequest', 10)
            ->assertJsonPath('data.eligibilityRules.0.staffType', 'teaching')->assertJsonCount(2, 'data.eligibilityRules');
        $this->as($w['admin'])->getJson($this->url($w, '/policies'))->assertOk()
            ->assertJsonPath('data.1.id', $created->json('data.id'))->assertJsonPath('data.0.status', 'retired');

        // No mass assignment: unknown keys and malformed rules are refused, never stored.
        $this->as($w['admin'])->postJson($this->url($w, '/policies'), $this->version($w, ['eligibility_rules' => [['gender' => 'female']]]))->assertStatus(422);
        $this->as($w['admin'])->postJson($this->url($w, '/policies'), $this->version($w, ['eligibility_rules' => [['staff_type' => 'manager']]]))->assertStatus(422);
        $this->as($w['admin'])->postJson($this->url($w, '/policies'), $this->version($w, ['min_notice_days' => 0]))->assertStatus(422);
        $this->as($w['admin'])->postJson($this->url($w, '/policies'), $this->version($w, ['supersedes_policy_id' => null, 'name' => 'Overlap']))
            ->assertStatus(409)->assertJsonPath('error.code', 'LEAVE_ELIGIBILITY_OVERLAP');
    }

    #[Test]
    public function each_operation_allows_its_capability_and_denies_the_others(): void
    {
        $w = $this->leaveWorld();
        $viewer = $this->createUserWithCapabilities($w['school'], ['hr.leave.view']);
        $configurer = $this->createUserWithCapabilities($w['school'], ['hr.leave.configure']);
        $manager = $this->createUserWithCapabilities($w['school'], ['hr.leave.manage']);
        $preview = ['leave_type_id' => $w['type']->id, 'name' => 'Draft', 'annual_allocation_units' => 0, 'eligibility_rules' => [['staff_type' => 'teaching']]];
        $run = ['leave_type_id' => $w['type']->id, 'as_of' => '2026-10-05'];

        $this->as($viewer)->getJson($this->url($w, "/eligibility?leave_type_id={$w['type']->id}"))->assertOk()->assertJsonPath('data.date', '2026-10-05')
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->as($configurer)->getJson($this->url($w, "/eligibility?leave_type_id={$w['type']->id}"))->assertForbidden();

        $this->as($configurer)->postJson($this->url($w, '/eligibility/preview'), $preview)->assertOk()->assertJsonPath('data.overlappingPolicyId', null);
        $this->as($viewer)->postJson($this->url($w, '/eligibility/preview'), $preview)->assertForbidden();
        $this->as($manager)->postJson($this->url($w, '/eligibility/preview'), $preview)->assertForbidden();

        $this->as($manager)->postJson($this->url($w, '/eligibility-runs'), $run)->assertCreated()->assertJsonPath('data.asOf', '2026-10-05');
        $this->as($viewer)->postJson($this->url($w, '/eligibility-runs'), $run)->assertForbidden();
        $this->as($configurer)->postJson($this->url($w, '/eligibility-runs'), $run)->assertForbidden();
        $this->as($viewer)->postJson($this->url($w, '/policies'), $this->version($w))->assertForbidden();
    }

    #[Test]
    public function the_run_replays_on_retry_and_refuses_a_reused_key_with_a_different_payload(): void
    {
        $w = $this->leaveWorld();
        $this->as($w['admin'])->postJson($this->url($w, '/policies'), $this->version($w, ['eligibility_rules' => [['staff_type' => null]]]))->assertCreated();
        $this->createEmploymentRecord($this->createEmployee($w['school']), ['status' => 'active', 'starts_on' => '2024-01-01', 'ends_on' => null]);
        $key = (string) Str::uuid();
        $run = ['leave_type_id' => $w['type']->id, 'as_of' => '2026-10-05'];

        $first = $this->as($w['admin'], $key)->postJson($this->url($w, '/eligibility-runs'), $run)->assertCreated()->assertJsonPath('data.assignedCount', 1);
        $this->as($w['admin'], $key)->postJson($this->url($w, '/eligibility-runs'), $run)->assertCreated()->assertJsonPath('data.id', $first->json('data.id'));
        $this->assertSame(1, $this->inSchool($w['school'], fn () => DB::table('leave_eligibility_runs')->count()), 'a retry runs once');
        $this->as($w['admin'], $key)->postJson($this->url($w, '/eligibility-runs'), ['as_of' => '2026-10-06'] + $run)->assertStatus(409)->assertJsonPath('error.code', 'IDEMPOTENCY_KEY_CONFLICT');
        $this->as($w['admin'])->postJson($this->url($w, '/eligibility-runs'), $run)->assertCreated()->assertJsonPath('data.assignedCount', 0);
    }

    #[Test]
    public function foreign_ids_are_one_404_and_input_is_validated(): void
    {
        $w = $this->leaveWorld();
        $other = $this->leaveWorld();

        $this->as($w['admin'])->getJson($this->url($w, "/eligibility?leave_type_id={$other['type']->id}"))->assertNotFound();
        $this->as($w['admin'])->postJson($this->url($w, '/eligibility-runs'), ['leave_type_id' => $other['type']->id, 'as_of' => '2026-10-05'])->assertNotFound();
        $this->as($w['admin'])->postJson($this->url($w, '/eligibility/preview'), ['leave_type_id' => $w['type']->id, 'name' => 'D', 'annual_allocation_units' => 0,
            'supersedes_policy_id' => $other['policy']->id])->assertNotFound();
        $this->as($w['admin'])->getJson($this->url($w, "/eligibility?leave_type_id={$w['type']->id}&date=2026-13-01"))->assertStatus(422);
        $this->as($w['admin'])->postJson($this->url($w, '/eligibility-runs'), ['leave_type_id' => $w['type']->id])->assertStatus(422);
    }

    #[Test]
    public function a_timing_refusal_is_a_stable_code_on_the_request_api(): void
    {
        $w = $this->leaveWorld();
        $this->workingWeek($w['school'], $w['admin']);
        $this->as($w['admin'])->postJson($this->url($w, '/policies'), $this->version($w, ['eligibility_rules' => []]))->assertCreated();
        // The employment still holds the ORIGINAL version (assignments are never repointed): no timing rule applies to it.
        $clerk = $this->leaveClerk($w['school']);
        $submit = fn (string $from) => $this->as($clerk)->postJson($this->url($w, '/requests'), [
            'employment_record_id' => $w['employment']->id, 'leave_type_id' => $w['type']->id,
            'starts_on' => $from, 'start_portion' => 'full', 'ends_on' => $from, 'end_portion' => 'full',
        ]);
        $submit('2026-10-01')->assertCreated();

        $ruled = $this->leaveWorld();
        $this->as($ruled['admin'])->postJson($this->url($ruled, '/policies'), $this->version($ruled, ['eligibility_rules' => []]))->assertCreated();
        $assignment = $this->inSchool($ruled['school'], fn () => DB::table('leave_policy_assignments')->where('employment_record_id', $ruled['employment']->id)->first());
        $this->as($ruled['admin'])->postJson($this->url($ruled, "/assignments/{$assignment->id}/end"), ['effective_to' => '2026-10-04'])->assertOk();
        $v2 = $this->inSchool($ruled['school'], fn () => DB::table('leave_policies')->where('status', 'active')->value('id'));
        $this->as($ruled['admin'])->postJson($this->url($ruled, '/assignments'), ['employment_record_id' => $ruled['employment']->id, 'leave_policy_id' => $v2, 'effective_from' => '2026-10-05'])->assertCreated();
        $this->workingWeek($ruled['school'], $ruled['admin']);
        $this->as($this->leaveClerk($ruled['school']))->postJson($this->url($ruled, '/requests'), [
            'employment_record_id' => $ruled['employment']->id, 'leave_type_id' => $ruled['type']->id,
            'starts_on' => '2026-10-06', 'start_portion' => 'full', 'ends_on' => '2026-10-06', 'end_portion' => 'full',
        ])->assertStatus(422)->assertJsonPath('error.code', 'LEAVE_NOTICE_TOO_SHORT');
    }
}
