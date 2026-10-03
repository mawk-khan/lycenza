<?php

namespace Tests\Feature\Leave;

use App\Domain\Leave\Application\LeavePolicyAssignmentService;
use App\Domain\Leave\Application\LeaveYearService;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Leave\Concerns\CreatesLeaveFixtures;
use Tests\TestCase;

/**
 * HRX.2 (ADR 0065 §23): the request, manager-approval and year-close API --
 * capability allow/deny per family, `private-no-store`, idempotent replay
 * and conflict on ledger-affecting commands, the manager surface's private
 * 404, and the tenant-safe 404 for another School's ids.
 */
class LeaveRequestApiTest extends TestCase
{
    use CreatesLeaveFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-05 10:00:00');
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

    private function submit(array $w, User $actor, string $on, ?string $employmentId = null): TestResponse
    {
        return $this->as($actor)->postJson($this->url($w, '/requests'), [
            'employment_record_id' => $employmentId ?? $w['employment']->id, 'leave_type_id' => $w['type']->id,
            'starts_on' => $on, 'start_portion' => 'full', 'ends_on' => $on, 'end_portion' => 'full', 'reason_code' => 'personal',
        ]);
    }

    private function world(): array
    {
        $w = $this->leaveWorld();
        $this->workingWeek($w['school'], $w['admin']);
        $this->allocate($w);

        return $w;
    }

    #[Test]
    public function each_capability_family_allows_its_operations_and_nothing_else(): void
    {
        $w = $this->world();
        $viewer = $this->createUserWithCapabilities($w['school'], ['hr.leave.view']);
        $approver = $this->createUserWithCapabilities($w['school'], ['hr.leave.approve']);
        $configurer = $this->createUserWithCapabilities($w['school'], ['hr.leave.configure']);

        $this->submit($w, $viewer, '2026-10-12')->assertForbidden();
        $this->submit($w, $approver, '2026-10-12')->assertForbidden();
        $this->submit($w, $configurer, '2026-10-12')->assertForbidden();
        $id = $this->submit($w, $w['admin'], '2026-10-12')->assertCreated()->assertJsonPath('data.status', 'submitted')->assertJsonPath('data.submittedUnits', 2)->json('data.id');

        $this->as($viewer)->getJson($this->url($w, '/requests'))->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertJsonPath('data.0.id', $id);
        $this->as($approver)->getJson($this->url($w, '/requests'))->assertForbidden();
        $this->as($viewer)->postJson($this->url($w, "/requests/{$id}/approve"))->assertForbidden();
        $this->as($approver)->postJson($this->url($w, "/requests/{$id}/approve"))->assertForbidden();
        $this->as($viewer)->getJson($this->url($w, '/approvals'))->assertForbidden();
        $this->as($approver)->getJson($this->url($w, '/approvals'))->assertOk()->assertJsonPath('data', []);
        $this->as($approver)->postJson($this->url($w, "/approvals/{$id}/approve"))->assertNotFound();
        $this->as($viewer)->getJson($this->url($w, "/year-closes/preview?leave_year_id={$w['year']->id}"))->assertForbidden();

        $this->as($w['admin'])->postJson($this->url($w, "/requests/{$id}/approve"))->assertOk()->assertJsonPath('data.status', 'approved');
        $this->as($viewer)->getJson($this->url($w, "/requests/{$id}"))->assertOk()
            ->assertJsonPath('data.days.0.units', 2)->assertJsonPath('data.decisions.0.path', 'administrative')->assertJsonPath('data.ledgerEntries.0.kind', 'consumption');
    }

    #[Test]
    public function ledger_affecting_commands_replay_on_retry_and_refuse_a_reused_key_with_a_different_payload(): void
    {
        $w = $this->world();
        $id = $this->submit($w, $w['admin'], '2026-10-12')->json('data.id');
        $key = (string) Str::uuid();

        $this->as($w['admin'], $key)->postJson($this->url($w, "/requests/{$id}/approve"))->assertOk();
        $this->as($w['admin'], $key)->postJson($this->url($w, "/requests/{$id}/approve"))->assertOk()->assertJsonPath('data.status', 'approved');
        $this->assertSame(1, $this->inSchool($w['school'], fn () => DB::table('leave_ledger_entries')->where('leave_request_id', $id)->count()), 'a retry never consumes twice');
        $this->as($w['admin'])->postJson($this->url($w, "/requests/{$id}/approve"))->assertStatus(409)->assertJsonPath('error.code', 'LEAVE_REQUEST_NOT_SUBMITTED');

        $cancelKey = (string) Str::uuid();
        $this->as($w['admin'], $cancelKey)->postJson($this->url($w, "/requests/{$id}/cancel"), ['reason_code' => 'plans_changed'])->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->as($w['admin'], $cancelKey)->postJson($this->url($w, "/requests/{$id}/cancel"), ['reason_code' => 'plans_changed'])->assertOk();
        $this->as($w['admin'], $cancelKey)->postJson($this->url($w, "/requests/{$id}/cancel"), ['reason_code' => 'other'])->assertStatus(409)->assertJsonPath('error.code', 'IDEMPOTENCY_KEY_CONFLICT');
        $this->assertSame(1, $this->inSchool($w['school'], fn () => DB::table('leave_ledger_entries')->where('leave_request_id', $id)->where('kind', 'reversal')->count()), 'never reversed twice');
    }

    #[Test]
    public function the_contract_validates_and_answers_one_404_for_other_schools_and_non_reports(): void
    {
        $w = $this->world();
        $other = $this->world();

        $this->as($w['admin'])->postJson($this->url($w, '/requests'), ['employment_record_id' => $w['employment']->id, 'leave_type_id' => $w['type']->id, 'starts_on' => '2026-10-12', 'start_portion' => 'half', 'ends_on' => '2026-10-12', 'end_portion' => 'full'])->assertStatus(422);
        $this->as($w['admin'])->postJson($this->url($w, '/requests'), ['employment_record_id' => $w['employment']->id, 'leave_type_id' => $w['type']->id, 'starts_on' => '2026-10-12', 'start_portion' => 'full', 'ends_on' => '2026-10-12', 'end_portion' => 'full', 'reason_code' => 'medical'])->assertStatus(422);
        $this->as($w['admin'])->postJson($this->url($w, '/requests'), ['employment_record_id' => $w['employment']->id, 'leave_type_id' => $w['type']->id, 'starts_on' => '2026-10-12', 'start_portion' => 'first_half', 'ends_on' => '2026-10-13', 'end_portion' => 'full'])
            ->assertStatus(422)->assertJsonPath('error.code', 'LEAVE_REQUEST_SHAPE_INVALID');
        $id = $this->submit($w, $w['admin'], '2026-10-12')->json('data.id');
        $this->as($w['admin'])->postJson($this->url($w, "/requests/{$id}/reject"), ['reason_code' => 'too busy'])->assertStatus(422);

        $foreign = $this->submit($other, $other['admin'], '2026-10-12')->json('data.id');
        $this->as($w['admin'])->getJson($this->url($w, "/requests/{$foreign}"))->assertNotFound();
        $this->as($w['admin'])->postJson($this->url($w, "/requests/{$foreign}/approve"))->assertNotFound();
        $this->submit($w, $w['admin'], '2026-10-13', $other['employment']->id)->assertNotFound();
        $this->as($w['admin'])->getJson($this->url($w, '/requests/not-a-uuid'))->assertNotFound();
    }

    #[Test]
    public function a_manager_decides_only_a_direct_reports_request_through_the_approvals_surface(): void
    {
        $w = $this->world();
        $report = $this->staffMember($w['school']);
        $manager = $this->staffMember($w['school'], ['hr.leave.approve']);
        $this->reportTo($report['assignment'], $manager['assignment']);
        app(LeavePolicyAssignmentService::class)->assign($w['school'], $report['employment']->id, $w['policy']->id, '2026-04-01', null, $w['admin']);
        $this->allocate($w, 24, null, $report['employment']);

        $mine = $this->submit($w, $w['admin'], '2026-10-12', $report['employment']->id)->json('data.id');
        $notMine = $this->submit($w, $w['admin'], '2026-10-12')->json('data.id');

        $this->as($manager['user'])->getJson($this->url($w, '/approvals'))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $mine);
        $this->as($manager['user'])->getJson($this->url($w, "/approvals/{$notMine}"))->assertNotFound();
        $this->as($manager['user'])->postJson($this->url($w, "/approvals/{$notMine}/reject"), ['reason_code' => 'staffing_need'])->assertNotFound();
        $this->as($manager['user'])->getJson($this->url($w, "/approvals/{$mine}"))->assertOk()->assertJsonPath('data.status', 'submitted');
        $this->as($manager['user'])->postJson($this->url($w, "/approvals/{$mine}/approve"))->assertOk()->assertJsonPath('data.status', 'approved');
    }

    #[Test]
    public function a_year_close_is_previewed_executed_once_and_read_back_over_the_api(): void
    {
        $w = $this->world();
        $this->travelTo('2027-04-10 10:00:00');
        app(LeaveYearService::class)->open($w['school'], '2027-04-01', $w['admin']);
        $key = (string) Str::uuid();

        $this->as($w['admin'])->getJson($this->url($w, "/year-closes/preview?leave_year_id={$w['year']->id}"))->assertOk()
            ->assertJsonPath('data.blockers', [])->assertJsonPath('data.items.0.carriedUnits', 10)->assertJsonPath('data.items.0.lapsedUnits', 14);
        $closeId = $this->as($w['admin'], $key)->postJson($this->url($w, '/year-closes'), ['leave_year_id' => $w['year']->id])->assertCreated()->json('data.id');
        $this->as($w['admin'], $key)->postJson($this->url($w, '/year-closes'), ['leave_year_id' => $w['year']->id])->assertCreated()->assertJsonPath('data.id', $closeId);
        $this->as($w['admin'])->postJson($this->url($w, '/year-closes'), ['leave_year_id' => $w['year']->id])->assertStatus(409)->assertJsonPath('error.code', 'LEAVE_YEAR_ALREADY_CLOSED');
        $this->as($w['admin'])->getJson($this->url($w, "/year-closes/{$closeId}"))->assertOk()->assertJsonPath('data.items.0.closingUnits', 24)->assertJsonPath('data.reconciliations', []);
        $this->as($w['admin'])->getJson($this->url($w, '/year-closes'))->assertOk()->assertJsonPath('data.0.id', $closeId);
    }
}
