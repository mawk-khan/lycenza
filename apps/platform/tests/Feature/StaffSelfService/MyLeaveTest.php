<?php

namespace Tests\Feature\StaffSelfService;

use App\Domain\Leave\Application\Exceptions\LeaveException;
use App\Domain\Leave\Application\LeaveRequestService;
use App\Models\Role;
use App\Models\SchoolMembership;
use App\Models\User;
use App\Support\Tenancy\TenantRls;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\StaffSelfService\Concerns\CreatesSelfServiceFixtures;
use Tests\TestCase;

/**
 * HRX.4 (ADR 0065 §25.4, §25.5): own leave -- overview, requests, submit,
 * withdraw, cancel before start -- through the same HRX.2 services, with
 * ActingEmployee ownership and one identical private 404 for anything else.
 * The clock is Monday 2026-10-05.
 */
class MyLeaveTest extends TestCase
{
    use CreatesSelfServiceFixtures;

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
        return "/api/v1/schools/{$w['school']->id}/my/leave{$path}";
    }

    private function submit(array $w, User $user, string $from, ?string $to = null, string $start = 'full', ?string $end = null, array $extra = [], ?string $key = null): TestResponse
    {
        return $this->as($user, $key)->postJson($this->url($w, '/requests'), [
            'leave_type_id' => $w['type']->id, 'starts_on' => $from, 'start_portion' => $start, 'ends_on' => $to ?? $from, 'end_portion' => $end ?? $start,
        ] + $extra);
    }

    /** @return array<string, mixed> */
    private function world(): array
    {
        $w = $this->attendanceWorld();
        $w['me'] = $this->selfMember($w);
        $w['colleague'] = $this->selfMember($w);

        return $w;
    }

    #[Test]
    public function i_see_my_balances_types_and_only_my_requests(): void
    {
        $w = $this->world();
        $this->submitLeave($w, '2026-10-19', '2026-10-19', employment: $w['colleague']['employment']);
        $mine = $this->submit($w, $w['me']['user'], '2026-10-12')->assertCreated()->json('data.id');

        $overview = $this->as($w['me']['user'])->getJson($this->url($w, ''))->assertOk()->assertHeader('Cache-Control', 'no-store, private')->json('data');
        $this->assertSame('2026-10-05', $overview['asOf']);
        $this->assertSame(24, $overview['balances'][0]['availableUnits'], 'the same ledger-derived balance as administration, in integer half-day units');
        $this->assertSame('CL leave', $overview['balances'][0]['leaveTypeName']);
        $this->assertSame(['allowsHalfDay', 'code', 'id', 'isPaid', 'name', 'tracksBalance'], collect($overview['types'][0])->keys()->sort()->values()->all(), 'no policy or configuration detail');

        $this->as($w['me']['user'])->getJson($this->url($w, '/requests'))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $mine);
    }

    #[Test]
    public function i_submit_full_half_and_multi_day_requests_for_my_own_employment_through_the_hrx2_rules(): void
    {
        $w = $this->world();

        $full = $this->submit($w, $w['me']['user'], '2026-10-12')->assertCreated()->assertJsonPath('data.submittedUnits', 2)->json('data');
        $this->assertSame($w['me']['employment']->id, $full['employmentRecordId'], 'the acting EmploymentRecord, never a client value');
        $this->submit($w, $w['me']['user'], '2026-10-13', null, 'first_half')->assertCreated()->assertJsonPath('data.submittedUnits', 1);
        $this->submit($w, $w['me']['user'], '2026-10-14', '2026-10-17', 'second_half', 'first_half')->assertCreated()->assertJsonPath('data.submittedUnits', 6);

        $this->submit($w, $w['me']['user'], '2026-10-12')->assertStatus(409)->assertJsonPath('error.code', 'LEAVE_REQUEST_OVERLAP');
        $this->submit($w, $w['me']['user'], '2026-10-18')->assertStatus(422)->assertJsonPath('error.code', 'LEAVE_REQUEST_NO_WORKING_DAYS');
        $this->submit($w, $w['me']['user'], '2026-10-20', null, 'full', null, ['reason_code' => 'medical'])->assertStatus(422);
        foreach (['employee_id' => $w['colleague']['employment']->employee_id, 'employment_record_id' => $w['colleague']['employment']->id, 'school_id' => $w['school']->id, 'manager_id' => $w['colleague']['employment']->employee_id] as $field => $value) {
            $this->submit($w, $w['me']['user'], '2026-10-21', null, 'full', null, [$field => $value])->assertStatus(422)->assertJsonValidationErrors([$field], 'error.errors');
        }
        $this->assertSame(0, $this->inSchool($w['school'], fn () => DB::table('leave_requests')->where('employment_record_id', $w['colleague']['employment']->id)->count()), 'nothing was filed for anyone else');
        $this->assertSame(['self_service'], $this->inSchool($w['school'], fn () => DB::table('school_audit_events')->where('event_type', 'leave.request.submitted')->pluck('metadata')->map(fn ($m) => json_decode($m, true)['source'])->unique()->values()->all()));
    }

    #[Test]
    public function i_withdraw_my_submitted_request_and_cancel_my_approved_one_before_it_starts(): void
    {
        $w = $this->world();
        $submitted = $this->submit($w, $w['me']['user'], '2026-10-12')->json('data.id');
        $this->as($w['me']['user'])->postJson($this->url($w, "/requests/{$submitted}/withdraw"), ['reason_code' => 'plans_changed'])->assertOk()->assertJsonPath('data.status', 'withdrawn');

        $approved = $this->submit($w, $w['me']['user'], '2026-10-19', '2026-10-20')->json('data.id');
        app(LeaveRequestService::class)->approve($w['school'], $approved, $w['admin']);
        $this->as($w['me']['user'])->postJson($this->url($w, "/requests/{$approved}/cancel"), ['reason_code' => 'plans_changed'])->assertOk()->assertJsonPath('data.status', 'cancelled');

        $this->inSchool($w['school'], function () use ($submitted, $approved) {
            $this->assertSame(['self', 'self'], [DB::table('leave_decisions')->where('leave_request_id', $submitted)->value('path'), DB::table('leave_decisions')->where('leave_request_id', $approved)->where('decision', 'cancelled')->value('path')]);
            $this->assertSame(1, DB::table('leave_ledger_entries')->where('leave_request_id', $approved)->where('kind', 'reversal')->count(), 'the HRX.2 reversal, once');
            $this->assertSame(1, DB::table('domain_event_outbox')->where('event_type', 'leave.request.cancelled.v1')->count(), 'the existing event, exactly once');
        });
        $this->as($w['me']['user'])->getJson($this->url($w, "/requests/{$approved}"))->assertOk()
            ->assertJsonPath('data.days.0.units', 2)->assertJsonMissingPath('data.ledgerEntries')->assertJsonMissingPath('data.days.0.leavePolicyId');
    }

    #[Test]
    public function leave_that_has_started_is_cancelled_only_by_an_administrator(): void
    {
        $w = $this->world();
        $today = $this->submitLeave($w, '2026-10-05', '2026-10-06', employment: $w['me']['employment']);
        app(LeaveRequestService::class)->approve($w['school'], $today->id, $w['admin']);

        $this->as($w['me']['user'])->postJson($this->url($w, "/requests/{$today->id}/cancel"), ['reason_code' => 'plans_changed'])->assertStatus(409)->assertJsonPath('error.code', 'LEAVE_SELF_CANCEL_STARTED');
        $this->assertSame('cancelled', app(LeaveRequestService::class)->cancel($w['school'], $today->id, 'administrative_correction', $w['admin'])->status);
    }

    #[Test]
    public function nobody_decides_their_own_request_and_the_database_refuses_a_forged_self_decision(): void
    {
        $w = $this->world();
        $mine = $this->submit($w, $w['me']['user'], '2026-10-12')->json('data.id');
        $colleagues = $this->submitLeave($w, '2026-10-12', '2026-10-12', employment: $w['colleague']['employment'])->id;

        // No approve/reject on the self path, and the self capability opens neither decision surface.
        $this->as($w['me']['user'])->postJson($this->url($w, "/requests/{$mine}/approve"))->assertNotFound();
        $this->as($w['me']['user'])->postJson("/api/v1/schools/{$w['school']->id}/leave/requests/{$mine}/approve")->assertForbidden();
        $this->as($w['me']['user'])->getJson("/api/v1/schools/{$w['school']->id}/leave/approvals")->assertForbidden();
        // Even an administrator who is the requester cannot approve their own request (HRX.2, unchanged).
        $selfAdmin = $this->selfMember($w);
        $own = $this->submitLeave($w, '2026-10-26', '2026-10-26', employment: $selfAdmin['employment']);
        $this->assertSame('LEAVE_SELF_DECISION', $this->refusalCode(fn () => app(LeaveRequestService::class)->approve($w['school'], $own->id, $this->grant($w, $selfAdmin['user'], ['hr.leave.manage']))));

        // Raw SQL as the runtime role: a `self` decision for someone else's request is refused by the CHECK.
        DB::connection('pgsql')->select('select set_config(?, ?, false)', [TenantRls::SESSION_VAR, $w['school']->id]);
        try {
            DB::connection('pgsql')->transaction(fn () => DB::insert(
                "insert into leave_decisions (id, school_id, leave_request_id, requester_employee_id, decision, path, decided_by_user_id, reason_code) values (?, ?, ?, ?, 'withdrawn', 'self', ?, 'plans_changed')",
                [(string) Str::uuid7(), $w['school']->id, $colleagues, $w['colleague']['employment']->employee_id, $w['me']['user']->id],
            ));
            $this->fail('a forged self decision was accepted');
        } catch (QueryException $e) {
            $this->assertStringContainsString('leave_decisions_shape_check', $e->getMessage());
        } finally {
            DB::connection('pgsql')->select('select set_config(?, ?, false)', [TenantRls::SESSION_VAR, '']);
        }
    }

    #[Test]
    public function another_employees_unknown_or_foreign_request_is_one_identical_404(): void
    {
        $w = $this->world();
        $other = $this->world();
        $colleagues = $this->submitLeave($w, '2026-10-12', '2026-10-12', employment: $w['colleague']['employment'])->id;
        $foreign = $this->submitLeave($other, '2026-10-12', '2026-10-12', employment: $other['me']['employment'])->id;

        $unknown = $this->as($w['me']['user'])->getJson($this->url($w, '/requests/'.Str::uuid()))->assertNotFound()->json('error');
        unset($unknown['requestId']);
        foreach ([$colleagues, $foreign, 'not-a-uuid'] as $id) {
            foreach ([['getJson', ''], ['postJson', '/withdraw'], ['postJson', '/cancel']] as [$method, $suffix]) {
                $body = $this->as($w['me']['user'])->{$method}($this->url($w, "/requests/{$id}{$suffix}"), ['reason_code' => 'plans_changed'])->assertNotFound()->json('error');
                unset($body['requestId']);
                $this->assertSame($unknown, $body, "{$method} {$id}{$suffix}");
            }
        }
        $this->assertSame('submitted', $this->inSchool($w['school'], fn () => DB::table('leave_requests')->where('id', $colleagues)->value('status')));
    }

    #[Test]
    public function capability_without_an_acting_employee_and_an_employee_without_the_capability_are_refused(): void
    {
        $w = $this->world();
        $noEmployee = $this->createUserWithCapabilities($w['school'], ['hr.leave.self']);
        $noCapability = $this->staffMember($w['school'], ['hr.staff_attendance.self', 'payroll.payslips.self']);
        $leaveAdmin = $this->createUserWithCapabilities($w['school'], ['hr.leave.view', 'hr.leave.manage', 'hr.leave.approve']);

        $this->as($noEmployee)->getJson($this->url($w, ''))->assertNotFound();
        $this->submit($w, $noEmployee, '2026-10-12')->assertNotFound();
        $this->as($noCapability['user'])->getJson($this->url($w, '/requests'))->assertForbidden();
        $this->submit($w, $noCapability['user'], '2026-10-12')->assertForbidden();
        $this->submit($w, $leaveAdmin, '2026-10-12')->assertForbidden();
        $this->assertSame(0, $this->inSchool($w['school'], fn () => DB::table('leave_requests')->count()));
    }

    #[Test]
    public function submit_withdraw_and_cancel_replay_on_retry_and_refuse_a_reused_key_with_a_different_payload(): void
    {
        $w = $this->world();
        $key = (string) Str::uuid();
        $id = $this->submit($w, $w['me']['user'], '2026-10-12', key: $key)->assertCreated()->json('data.id');
        $this->submit($w, $w['me']['user'], '2026-10-12', key: $key)->assertCreated()->assertJsonPath('data.id', $id);
        $this->submit($w, $w['me']['user'], '2026-10-13', key: $key)->assertStatus(409)->assertJsonPath('error.code', 'IDEMPOTENCY_KEY_CONFLICT');
        $this->assertSame(1, $this->inSchool($w['school'], fn () => DB::table('leave_requests')->count()));

        app(LeaveRequestService::class)->approve($w['school'], $id, $w['admin']);
        $ckey = (string) Str::uuid();
        $this->as($w['me']['user'], $ckey)->postJson($this->url($w, "/requests/{$id}/cancel"), ['reason_code' => 'plans_changed'])->assertOk();
        $this->as($w['me']['user'], $ckey)->postJson($this->url($w, "/requests/{$id}/cancel"), ['reason_code' => 'plans_changed'])->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->as($w['me']['user'], $ckey)->postJson($this->url($w, "/requests/{$id}/cancel"), ['reason_code' => 'other'])->assertStatus(409)->assertJsonPath('error.code', 'IDEMPOTENCY_KEY_CONFLICT');
        $this->inSchool($w['school'], function () use ($id) {
            $this->assertSame(1, DB::table('leave_ledger_entries')->where('leave_request_id', $id)->where('kind', 'reversal')->count());
            $this->assertSame(1, DB::table('domain_event_outbox')->where('event_type', 'leave.request.cancelled.v1')->count());
        });

        $other = $this->submit($w, $w['me']['user'], '2026-10-14')->json('data.id');
        $wkey = (string) Str::uuid();
        $this->as($w['me']['user'], $wkey)->postJson($this->url($w, "/requests/{$other}/withdraw"), ['reason_code' => 'plans_changed'])->assertOk();
        $this->as($w['me']['user'], $wkey)->postJson($this->url($w, "/requests/{$other}/withdraw"), ['reason_code' => 'plans_changed'])->assertOk()->assertJsonPath('data.status', 'withdrawn');
        $this->assertSame(1, $this->inSchool($w['school'], fn () => DB::table('leave_decisions')->where('leave_request_id', $other)->count()));
    }

    private function refusalCode(callable $command): string
    {
        try {
            $command();
        } catch (LeaveException $e) {
            return $e->errorCode();
        }

        return 'accepted';
    }

    /** @param  list<string>  $capabilities */
    private function grant(array $w, User $user, array $capabilities): User
    {
        $role = Role::query()->create(['key' => 'test.'.Str::random(8), 'name' => 'Test', 'scope' => 'school', 'is_system' => false]);
        $role->capabilities()->sync($capabilities);
        $membership = SchoolMembership::query()->where('school_id', $w['school']->id)->where('user_id', $user->id)->firstOrFail();
        $this->assignSchoolRole($membership, $role->key);

        return $user->refresh();
    }
}
