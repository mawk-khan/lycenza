<?php

namespace Tests\Feature\StaffAttendance;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\StaffAttendance\Concerns\CreatesStaffAttendanceFixtures;
use Tests\TestCase;

/**
 * HRX.3 (ADR 0065 §24): the Staff Attendance API -- capability allow/deny
 * (view and manage independent of each other and of Leave), `private-no-store`,
 * idempotent replay and conflict on every mutation, validation of the closed
 * value lists, and the tenant-safe 404 for another School's ids.
 */
class StaffAttendanceApiTest extends TestCase
{
    use CreatesStaffAttendanceFixtures;

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
        return "/api/v1/schools/{$w['school']->id}/staff-attendance{$path}";
    }

    private function body(array $w, string $date = '2026-09-28', ?string $first = 'present', ?string $second = 'present', ?string $employmentId = null): array
    {
        return ['employment_record_id' => $employmentId ?? $w['employment']->id, 'date' => $date, 'first_half' => $first, 'second_half' => $second];
    }

    private function inTable(array $w, string $table): int
    {
        return $this->inSchool($w['school'], fn () => DB::table($table)->where('school_id', $w['school']->id)->count());
    }

    #[Test]
    public function view_and_manage_are_independent_and_nothing_else_grants_them(): void
    {
        $w = $this->attendanceWorld();
        $viewer = $this->createUserWithCapabilities($w['school'], ['hr.staff_attendance.view']);
        $recorder = $this->createUserWithCapabilities($w['school'], ['hr.staff_attendance.manage']);
        $leaveAdmin = $this->createUserWithCapabilities($w['school'], ['hr.leave.view', 'hr.leave.manage', 'hr.leave.configure', 'hr.leave.approve']);
        $teacher = $this->createUserWithCapabilities($w['school'], ['attendance.teacher', 'attendance.manage', 'attendance.view']);

        foreach ([$viewer, $leaveAdmin, $teacher] as $refused) {
            $this->as($refused)->postJson($this->url($w, '/records'), $this->body($w))->assertForbidden();
        }
        // A manage-only actor's committed write answers 201 with the record -- never a 403 for lacking view.
        $created = $this->as($recorder)->postJson($this->url($w, '/records'), $this->body($w))->assertCreated()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data.firstHalf', 'present')->assertJsonPath('data.version', 1)->json('data');
        $this->as($recorder)->postJson($this->url($w, "/records/{$created['id']}/corrections"), ['expected_version' => 1, 'first_half' => 'absent', 'second_half' => 'present', 'reason_code' => 'entered_in_error'])
            ->assertOk()->assertJsonPath('data.version', 2);
        $this->as($recorder)->postJson($this->url($w, '/register'), ['date' => '2026-09-29', 'items' => [['employment_record_id' => $w['employment']->id, 'first_half' => 'present', 'second_half' => 'absent']]])
            ->assertCreated()->assertJsonPath('data.0.secondHalf', 'absent');

        foreach ([$recorder, $leaveAdmin, $teacher] as $refused) {
            $this->as($refused)->getJson($this->url($w, '/register?date=2026-09-28'))->assertForbidden();
            $this->as($refused)->getJson($this->url($w, "/records/{$created['id']}"))->assertForbidden();
        }
        $this->as($viewer)->getJson($this->url($w, '/register?date=2026-09-28'))->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data.rows.0.day.summary', 'half_day_absent');
        $this->as($viewer)->getJson($this->url($w, "/records/{$created['id']}"))->assertOk()->assertJsonPath('data.corrections.0.reasonCode', 'entered_in_error');
        $this->as($viewer)->getJson($this->url($w, "/history?employment_record_id={$w['employment']->id}&from=2026-09-28&to=2026-10-04"))->assertOk()
            ->assertJsonCount(7, 'data.days')->assertJsonPath('data.days.6.summary', 'off_day');
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();
        $this->getJson($this->url($w, '/register?date=2026-09-28'))->assertUnauthorized();
    }

    #[Test]
    public function every_mutation_replays_on_retry_and_refuses_a_reused_key_with_a_different_payload(): void
    {
        $w = $this->attendanceWorld();

        $key = (string) Str::uuid();
        $first = $this->as($w['clerk'], $key)->postJson($this->url($w, '/records'), $this->body($w))->assertCreated()->json('data');
        $this->as($w['clerk'], $key)->postJson($this->url($w, '/records'), $this->body($w))->assertCreated()->assertJsonPath('data.id', $first['id']);
        $this->as($w['clerk'], $key)->postJson($this->url($w, '/records'), $this->body($w, '2026-09-28', 'absent'))->assertStatus(409)->assertJsonPath('error.code', 'IDEMPOTENCY_KEY_CONFLICT');
        $this->assertSame(1, $this->inTable($w, 'staff_attendance_records'));

        $correction = ['expected_version' => 1, 'first_half' => 'absent', 'second_half' => 'present', 'reason_code' => 'late_information'];
        $ckey = (string) Str::uuid();
        $this->as($w['clerk'], $ckey)->postJson($this->url($w, "/records/{$first['id']}/corrections"), $correction)->assertOk()->assertJsonPath('data.version', 2);
        $this->as($w['clerk'], $ckey)->postJson($this->url($w, "/records/{$first['id']}/corrections"), $correction)->assertOk()->assertJsonPath('data.version', 2);
        $this->as($w['clerk'], $ckey)->postJson($this->url($w, "/records/{$first['id']}/corrections"), ['reason_code' => 'other'] + $correction)->assertStatus(409)->assertJsonPath('error.code', 'IDEMPOTENCY_KEY_CONFLICT');
        $this->as($w['clerk'])->postJson($this->url($w, "/records/{$first['id']}/corrections"), $correction)->assertStatus(409)->assertJsonPath('error.code', 'STAFF_ATTENDANCE_VERSION_STALE');
        $this->assertSame(1, $this->inTable($w, 'staff_attendance_corrections'), 'a retry never corrects twice');
        $this->assertSame(1, $this->inSchool($w['school'], fn () => DB::table('domain_event_outbox')->where('event_type', 'staff_attendance.corrected.v1')->count()));

        $other = $this->currentEmployment($w['school']);
        $register = ['date' => '2026-09-29', 'items' => [['employment_record_id' => $w['employment']->id, 'first_half' => 'present', 'second_half' => 'present'], ['employment_record_id' => $other->id, 'first_half' => 'absent', 'second_half' => 'absent']]];
        $rkey = (string) Str::uuid();
        $this->as($w['clerk'], $rkey)->postJson($this->url($w, '/register'), $register)->assertCreated()->assertJsonCount(2, 'data');
        $this->as($w['clerk'], $rkey)->postJson($this->url($w, '/register'), $register)->assertCreated()->assertJsonCount(2, 'data');
        $this->as($w['clerk'], $rkey)->postJson($this->url($w, '/register'), ['date' => '2026-09-30'] + $register)->assertStatus(409)->assertJsonPath('error.code', 'IDEMPOTENCY_KEY_CONFLICT');
        $this->assertSame(3, $this->inTable($w, 'staff_attendance_records'));
        $this->assertSame(1, $this->inSchool($w['school'], fn () => DB::table('school_audit_events')->where('event_type', 'staff_attendance.bulk_recorded')->count()), 'no duplicate side effect on replay');
    }

    #[Test]
    public function the_contract_validates_closed_values_and_answers_one_404_for_other_schools(): void
    {
        $w = $this->attendanceWorld();
        $other = $this->attendanceWorld();

        $this->as($w['clerk'])->postJson($this->url($w, '/records'), ['employment_record_id' => $w['employment']->id, 'date' => '2026-09-28', 'first_half' => 'present'])->assertStatus(422);
        $this->as($w['clerk'])->postJson($this->url($w, '/records'), $this->body($w, '2026-09-28', 'leave'))->assertStatus(422);
        $this->as($w['clerk'])->postJson($this->url($w, '/records'), $this->body($w, '2026-09-28', 'late'))->assertStatus(422);
        $this->as($w['clerk'])->postJson($this->url($w, '/records'), $this->body($w, '28-09-2026'))->assertStatus(422);
        $this->as($w['clerk'])->postJson($this->url($w, '/records'), $this->body($w, '2026-10-06'))->assertStatus(422)->assertJsonPath('error.code', 'STAFF_ATTENDANCE_DATE_IN_FUTURE');
        $this->as($w['clerk'])->postJson($this->url($w, '/records'), $this->body($w, '2026-10-04'))->assertStatus(409)->assertJsonPath('error.code', 'STAFF_ATTENDANCE_NOT_WORKING_TIME');
        $this->as($w['clerk'])->postJson($this->url($w, '/register'), ['date' => '2026-09-28', 'items' => []])->assertStatus(422);
        $id = $this->as($w['clerk'])->postJson($this->url($w, '/records'), $this->body($w))->json('data.id');
        $this->as($w['clerk'])->postJson($this->url($w, "/records/{$id}/corrections"), ['expected_version' => 1, 'first_half' => 'absent', 'second_half' => 'present', 'reason_code' => 'medical'])->assertStatus(422);
        $this->as($w['clerk'])->postJson($this->url($w, "/records/{$id}/corrections"), ['expected_version' => 1, 'first_half' => 'absent', 'second_half' => 'present', 'reason_code' => 'other', 'note' => 'flu'])->assertOk()
            ->assertJsonMissingPath('data.note');
        $this->as($w['clerk'])->getJson($this->url($w, "/history?employment_record_id={$w['employment']->id}&from=2026-01-01&to=2026-09-30"))->assertStatus(422)->assertJsonPath('error.code', 'STAFF_ATTENDANCE_RANGE_INVALID');

        $foreignRecord = $this->as($other['clerk'])->postJson($this->url($other, '/records'), $this->body($other))->json('data.id');
        $this->as($w['clerk'])->postJson($this->url($w, '/records'), $this->body($w, '2026-09-29', 'present', 'present', $other['employment']->id))->assertNotFound();
        $this->as($w['clerk'])->getJson($this->url($w, "/records/{$foreignRecord}"))->assertNotFound();
        $this->as($w['clerk'])->postJson($this->url($w, "/records/{$foreignRecord}/corrections"), ['expected_version' => 1, 'first_half' => 'absent', 'second_half' => 'absent', 'reason_code' => 'other'])->assertNotFound();
        $this->as($w['clerk'])->getJson($this->url($w, "/history?employment_record_id={$other['employment']->id}&from=2026-09-28&to=2026-09-30"))->assertNotFound();
        $this->as($w['clerk'])->getJson($this->url($w, '/records/not-a-uuid'))->assertNotFound();
        // Another School's clerk is no member here: the School itself is the private 404.
        $this->as($other['clerk'])->getJson($this->url($w, '/register?date=2026-09-28'))->assertNotFound();
    }
}
