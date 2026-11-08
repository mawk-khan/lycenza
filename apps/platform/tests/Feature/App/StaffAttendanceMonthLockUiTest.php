<?php

namespace Tests\Feature\App;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ProvidesSensitiveActionMfa;
use Tests\Feature\StaffAttendance\Concerns\CreatesStaffAttendanceFixtures;
use Tests\TestCase;

/**
 * HR.R1-C18 (WO-6): the month lock through the real web routes. Locking and deciding need the lock
 * key, an explicit confirmation and a fresh code (single use); a request carries a server-issued form
 * key; the page offers a decision only to someone independent, and the server decides again.
 */
class StaffAttendanceMonthLockUiTest extends TestCase
{
    use CreatesStaffAttendanceFixtures, ProvidesSensitiveActionMfa;

    /** @var array<string, mixed> */
    private array $w;

    private User $principal;

    private User $head;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-05 10:00:00');
        $this->w = $this->attendanceWorld();
        $keys = ['hr.staff_attendance.view', 'hr.staff_attendance.manage', 'hr.staff_attendance.lock'];
        $this->principal = $this->createUserWithCapabilities($this->w['school'], $keys);
        $this->head = $this->createUserWithCapabilities($this->w['school'], $keys);
    }

    private function as(User $user): static
    {
        return $this->actingAs($user)->withHeader('X-School-Id', $this->w['school']->id);
    }

    private function locks(): int
    {
        return $this->inSchool($this->w['school'], fn () => DB::table('staff_attendance_month_locks')->count());
    }

    #[Test]
    public function locking_through_the_page_needs_the_key_a_confirmation_and_a_fresh_single_use_code(): void
    {
        $this->as($this->w['clerk'])->get('/app/staff-attendance/monthly?month=2026-10')->assertOk()
            ->assertInertia(fn (Assert $p) => $p->where('lock.locked', false)->where('canLock', false));
        $this->as($this->w['clerk'])->post('/app/staff-attendance/months/2026-10/lock', ['confirm' => '1', 'mfa_code' => 'X'])->assertForbidden();

        $this->as($this->principal)->post('/app/staff-attendance/months/2026-10/lock', ['mfa_code' => $this->freshMfaCode($this->principal)])->assertSessionHasErrors('confirm');
        $this->as($this->principal)->post('/app/staff-attendance/months/2026-10/lock', ['confirm' => '1', 'mfa_code' => 'NOT-A-CODE'])->assertSessionHasErrors('mfa_code');
        $this->assertSame(0, $this->locks());

        $code = $this->freshMfaCode($this->principal);
        $this->as($this->principal)->post('/app/staff-attendance/months/2026-10/lock', ['confirm' => '1', 'mfa_code' => $code])->assertSessionHasNoErrors();
        $this->assertSame(1, $this->locks());
        $this->as($this->head)->post('/app/staff-attendance/months/2026-09/lock', ['confirm' => '1', 'mfa_code' => $code])->assertSessionHasErrors('mfa_code');

        $this->as($this->principal)->get('/app/staff-attendance/monthly?month=2026-10')->assertOk()
            ->assertInertia(fn (Assert $p) => $p->where('lock.locked', true)->where('canLock', true)->where('hasMfaFactor', true));
    }

    #[Test]
    public function a_request_from_the_register_is_decided_only_by_someone_independent(): void
    {
        $record = $this->recordAttendance($this->w, '2026-10-05', 'present', 'present');
        $this->as($this->principal)->post('/app/staff-attendance/months/2026-10/lock', ['confirm' => '1', 'mfa_code' => $this->freshMfaCode($this->principal)]);

        $key = null;
        $this->as($this->w['clerk'])->get('/app/staff-attendance?date=2026-10-05')->assertOk()
            ->assertInertia(function (Assert $p) use (&$key) {
                $p->where('monthLock.locked', true)->where('pendingCorrections', [])->whereType('formKey', 'string');
                $key = $p->toArray()['props']['formKey'];
            });
        $this->as($this->w['clerk'])->post("/app/staff-attendance/records/{$record->id}/corrections", [
            'expected_version' => 1, 'first_half' => 'absent', 'second_half' => 'present', 'reason_code' => 'late_information',
        ])->assertSessionHasErrors('attendance');

        $body = ['expected_version' => 1, 'first_half' => 'absent', 'second_half' => 'present', 'reason_code' => 'late_information', 'form_key' => $key];
        $this->as($this->w['clerk'])->post("/app/staff-attendance/records/{$record->id}/locked-corrections", [...$body, 'form_key' => '00000000-0000-4000-8000-000000000000'])->assertSessionHasErrors('attendance');
        $this->as($this->w['clerk'])->post("/app/staff-attendance/records/{$record->id}/locked-corrections", $body)->assertSessionHasNoErrors();
        $this->as($this->w['clerk'])->post("/app/staff-attendance/records/{$record->id}/locked-corrections", $body)->assertSessionHasNoErrors();
        $request = $this->inSchool($this->w['school'], fn () => DB::table('staff_attendance_locked_corrections')->sole());

        $this->as($this->w['clerk'])->get('/app/staff-attendance?date=2026-10-05')
            ->assertInertia(fn (Assert $p) => $p->where('pendingCorrections', [$record->id]));
        $this->as($this->head)->get('/app/staff-attendance/monthly?month=2026-10')
            ->assertInertia(fn (Assert $p) => $p->has('requests', 1)->where('requests.0.canDecide', true)->where('requests.0.status', 'requested'));

        $this->as($this->head)->post("/app/staff-attendance/locked-corrections/{$request->id}/approve", ['confirm' => '1', 'mfa_code' => $this->freshMfaCode($this->head)])->assertSessionHasNoErrors();
        $this->assertSame(['absent', 2], [
            $this->inSchool($this->w['school'], fn () => DB::table('staff_attendance_records')->where('id', $record->id)->value('first_half_status')),
            (int) $this->inSchool($this->w['school'], fn () => DB::table('staff_attendance_records')->where('id', $record->id)->value('version')),
        ]);
        $this->as($this->head)->get('/app/staff-attendance/monthly?month=2026-10')
            ->assertInertia(fn (Assert $p) => $p->where('lock.locked', true)->where('requests.0.status', 'approved')->where('requests.0.canDecide', false));
    }

    #[Test]
    public function the_requester_is_never_offered_their_own_request_and_cannot_decide_it_by_posting(): void
    {
        $record = $this->recordAttendance($this->w, '2026-10-05', 'present', 'present');
        $this->as($this->principal)->post('/app/staff-attendance/months/2026-10/lock', ['confirm' => '1', 'mfa_code' => $this->freshMfaCode($this->principal)]);
        $key = null;
        $this->as($this->principal)->get('/app/staff-attendance?date=2026-10-05')->assertInertia(function (Assert $p) use (&$key) {
            $key = $p->toArray()['props']['formKey'];
        });
        $this->as($this->principal)->post("/app/staff-attendance/records/{$record->id}/locked-corrections", [
            'expected_version' => 1, 'first_half' => 'absent', 'second_half' => 'present', 'reason_code' => 'other', 'form_key' => $key,
        ])->assertSessionHasNoErrors();
        $request = $this->inSchool($this->w['school'], fn () => DB::table('staff_attendance_locked_corrections')->sole());

        $this->as($this->principal)->get('/app/staff-attendance/monthly?month=2026-10')
            ->assertInertia(fn (Assert $p) => $p->where('requests.0.canDecide', false)->where('requests.0.mine', true));
        $this->as($this->principal)->post("/app/staff-attendance/locked-corrections/{$request->id}/approve", ['confirm' => '1', 'mfa_code' => $this->freshMfaCode($this->principal)])
            ->assertSessionHasErrors('attendance');
        $this->assertSame('requested', $this->inSchool($this->w['school'], fn () => DB::table('staff_attendance_locked_corrections')->value('status')));
    }
}
