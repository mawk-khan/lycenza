<?php

namespace Tests\Feature\StaffAttendance;

use App\Domain\StaffAttendance\Application\Exceptions\StaffAttendanceException;
use App\Domain\StaffAttendance\Application\Locking\StaffAttendanceMonthLocks;
use App\Domain\StaffAttendance\Application\StaffAttendanceService;
use App\Domain\StaffAttendance\Infrastructure\StaffAttendanceLockedCorrection;
use App\Models\User;
use App\Models\UserMfaFactor;
use App\Support\Auth\Mfa\FreshMfaProof;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use PragmaRX\Google2FA\Google2FA;
use Tests\Concerns\ProvidesSensitiveActionMfa;
use Tests\Feature\StaffAttendance\Concerns\CreatesStaffAttendanceFixtures;
use Tests\TestCase;
use Throwable;

/**
 * HR.R1-C20 (WO-6 acceptance, C18-A4, A6, A8, A9 and the advisory-key identity): the evidence C18
 * left open, exercised on the real paths.
 *
 * - A4: lock and decide refuse a missing, stale (an expired TOTP step), reused and another user's
 *   code, and the service refuses another user's proof; nothing is written.
 * - A6: the database's own clock refuses a month that has not ended, even when the application's
 *   clock says it has, and that refusal is the domain conflict, not a raw error.
 * - A8: a malformed correction id is the ordinary 404 before any capability check, as for every
 *   UUID parameter (WebRouteParameters).
 * - A9: `/api/v1` refuses every write into a locked month, and leaves no row and no audit event.
 * - The PHP lock, the triggers and the SQL key function take the same advisory key.
 */
class StaffAttendanceMonthLockAcceptanceTest extends TestCase
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

    private function service(): StaffAttendanceService
    {
        return app(StaffAttendanceService::class);
    }

    private function rowsIn(string $table): int
    {
        return $this->inSchool($this->w['school'], fn () => DB::table($table)->count());
    }

    private function web(User $user): static
    {
        return $this->actingAs($user)->withHeader('X-School-Id', $this->w['school']->id);
    }

    private function lockedRequest(): StaffAttendanceLockedCorrection
    {
        $record = $this->recordAttendance($this->w, '2026-10-05', 'present', 'present');
        $this->recordAttendance($this->w, '2026-10-06', 'present', 'present');
        $this->service()->lockMonth($this->w['school'], '2026-10', $this->principal, $this->freshMfaProofFor($this->principal));

        return $this->service()->requestLockedCorrection($this->w['school'], $record->id, 1, 'absent', 'present', 'late_information', (string) Str::uuid(), $this->w['clerk']);
    }

    /** A TOTP code of the user's active factor, $steps time steps away from now (negative = the past). */
    private function totp(User $user, int $steps = 0): string
    {
        $this->mfaEnrolled($user);
        $secret = UserMfaFactor::query()->where('user_id', $user->id)->where('status', 'active')->sole()->secret_encrypted;
        $google = app(Google2FA::class);

        return $google->oathTotp($secret, $google->getTimestamp() + $steps);
    }

    private function refusalCode(callable $call): string
    {
        try {
            $call();
        } catch (StaffAttendanceException $e) {
            return $e->errorCode();
        } catch (Throwable $e) {
            return class_basename($e);
        }
        $this->fail('expected a refusal');
    }

    #[Test]
    public function locking_refuses_a_missing_stale_reused_or_borrowed_code_and_writes_nothing(): void
    {
        $this->mfaEnrolled($this->principal);
        $this->mfaEnrolled($this->head);
        $lock = fn (User $user, array $body) => $this->web($user)->post('/app/staff-attendance/months/2026-10/lock', ['confirm' => '1', ...$body]);
        $window = (int) config('mfa.totp_window');

        $lock($this->principal, [])->assertSessionHasErrors('mfa_code');
        $lock($this->principal, ['mfa_code' => $this->totp($this->principal, -($window + 3))])->assertSessionHasErrors('mfa_code');
        $lock($this->principal, ['mfa_code' => $this->freshMfaCode($this->head)])->assertSessionHasErrors('mfa_code');
        $this->assertSame(0, $this->rowsIn('staff_attendance_month_locks'), 'a stale or another user\'s code locks nothing');

        $current = $this->totp($this->principal);
        $lock($this->principal, ['mfa_code' => $current])->assertSessionHasNoErrors();
        $this->web($this->principal)->post('/app/staff-attendance/months/2026-09/lock', ['confirm' => '1', 'mfa_code' => $current])->assertSessionHasErrors('mfa_code');
        $this->assertSame(1, $this->rowsIn('staff_attendance_month_locks'), 'a reused step locks nothing more');
    }

    #[Test]
    public function deciding_refuses_a_missing_stale_reused_or_borrowed_code_and_decides_nothing(): void
    {
        $request = $this->lockedRequest();
        $this->mfaEnrolled($this->principal);
        $this->mfaEnrolled($this->head);
        $approve = fn (User $user, array $body) => $this->web($user)->post("/app/staff-attendance/locked-corrections/{$request->id}/approve", ['confirm' => '1', ...$body]);
        $window = (int) config('mfa.totp_window');

        $approve($this->head, [])->assertSessionHasErrors('mfa_code');
        $approve($this->head, ['mfa_code' => $this->totp($this->head, -($window + 3))])->assertSessionHasErrors('mfa_code');
        $approve($this->head, ['mfa_code' => $this->freshMfaCode($this->principal)])->assertSessionHasErrors('mfa_code');

        // A step the head already spent (on a refusal of an unrelated request) is not accepted again.
        $spent = $this->totp($this->head);
        $other = $this->inSchool($this->w['school'], fn () => DB::table('staff_attendance_records')->where('attendance_date', '2026-10-06')->sole());
        $second = $this->service()->requestLockedCorrection($this->w['school'], $other->id, 1, 'absent', null, 'other', (string) Str::uuid(), $this->w['clerk']);
        $this->web($this->head)->post("/app/staff-attendance/locked-corrections/{$second->id}/refuse", ['confirm' => '1', 'mfa_code' => $spent])->assertSessionHasNoErrors();
        $approve($this->head, ['mfa_code' => $spent])->assertSessionHasErrors('mfa_code');

        $row = $this->inSchool($this->w['school'], fn () => DB::table('staff_attendance_locked_corrections')->where('id', $request->id)->first());
        $this->assertSame(['requested', null], [$row->status, $row->decided_by_user_id]);
        $this->assertSame(1, (int) $this->inSchool($this->w['school'], fn () => DB::table('staff_attendance_records')->where('id', $request->staff_attendance_record_id)->value('version')));
    }

    #[Test]
    public function the_service_refuses_a_proof_minted_for_someone_else(): void
    {
        $request = $this->lockedRequest();
        $borrowed = new FreshMfaProof($this->principal->id, CarbonImmutable::now());

        $this->assertSame('LogicException', $this->refusalCode(fn () => $this->service()->approveLockedCorrection($this->w['school'], $request->id, $this->head, $borrowed)));
        $this->assertSame('LogicException', $this->refusalCode(fn () => $this->service()->refuseLockedCorrection($this->w['school'], $request->id, $this->head, $borrowed)));
        $this->assertSame('LogicException', $this->refusalCode(fn () => $this->service()->lockMonth($this->w['school'], '2026-09', $this->head, $borrowed)));
        $this->assertSame(['requested', 1], [
            $this->inSchool($this->w['school'], fn () => DB::table('staff_attendance_locked_corrections')->where('id', $request->id)->value('status')),
            $this->rowsIn('staff_attendance_month_locks'),
        ]);
    }

    #[Test]
    public function the_database_clock_refuses_a_month_that_has_not_ended_and_that_is_the_domain_refusal(): void
    {
        // The application's clock a month ahead of the database's: the service's own check passes, so
        // the refusal can only come from the trigger, on the database clock, translated (C18-A6).
        // The month the database itself is in, in the School's timezone (rule 141): never the UTC date,
        // which is a day behind for the last hours of a month east of UTC.
        $month = DB::selectOne("SELECT to_char(now() AT TIME ZONE ?, 'YYYY-MM') AS m", [$this->w['school']->timezone])->m;
        $real = CarbonImmutable::parse($month.'-01');
        $this->travelTo($real->addMonthNoOverflow()->startOfMonth()->addDays(2));

        $this->assertSame('STAFF_ATTENDANCE_MONTH_NOT_ENDED', $this->refusalCode(fn () => $this->service()->lockMonth($this->w['school'], $month, $this->principal, $this->freshMfaProofFor($this->principal))));
        $this->assertSame(0, $this->rowsIn('staff_attendance_month_locks'));

        // The translation matches this exact database text; if it is ever reworded, this fails first.
        try {
            $this->inSchool($this->w['school'], fn () => DB::table('staff_attendance_month_locks')->insert([
                'id' => (string) Str::uuid7(), 'school_id' => $this->w['school']->id, 'month' => $real->format('Y-m-01'), 'locked_by_user_id' => $this->principal->id,
            ]));
            $this->fail('the database locked a month that has not ended');
        } catch (QueryException $e) {
            $this->assertSame('23514', $e->getCode());
            $this->assertStringContainsString('only a month that has ended can be locked', $e->getMessage());
        }
    }

    #[Test]
    public function a_malformed_correction_id_is_the_ordinary_not_found_before_any_capability_check(): void
    {
        $outsider = $this->createUserWithCapabilities($this->w['school'], ['hr.staff_attendance.view']);
        foreach ([$outsider, $this->head] as $user) {
            $this->web($user)->post('/app/staff-attendance/locked-corrections/not-a-uuid/approve', ['confirm' => '1', 'mfa_code' => 'X'])->assertNotFound();
        }
        $this->web($outsider)->post('/app/staff-attendance/locked-corrections/'.Str::uuid().'/approve', ['confirm' => '1', 'mfa_code' => 'X'])->assertForbidden();
    }

    #[Test]
    public function the_api_refuses_every_write_into_a_locked_month_and_leaves_no_trace(): void
    {
        $this->travelTo('2026-11-05 10:00:00');
        $record = $this->recordAttendance($this->w, '2026-10-05', 'present', 'present');
        $this->service()->lockMonth($this->w['school'], '2026-10', $this->principal, $this->freshMfaProofFor($this->principal));
        $api = function (User $user) {
            $this->app['auth']->forgetGuards();

            return $this->withHeader('Authorization', 'Bearer '.$user->createToken('test-device')->plainTextToken)->withHeader('Idempotency-Key', (string) Str::uuid());
        };
        $url = fn (string $path, ?string $school = null) => '/api/v1/schools/'.($school ?? $this->w['school']->id).'/staff-attendance'.$path;
        $records = $this->rowsIn('staff_attendance_records');
        $corrections = $this->rowsIn('staff_attendance_corrections');
        $events = fn () => $this->inSchool($this->w['school'], fn () => DB::table('school_audit_events')->where('event_type', 'like', 'staff_attendance.%')->count());
        $before = $events();

        $api($this->w['clerk'])->postJson($url('/records'), ['employment_record_id' => $this->w['employment']->id, 'date' => '2026-10-06', 'first_half' => 'present', 'second_half' => 'present'])
            ->assertStatus(409)->assertJsonPath('error.code', 'STAFF_ATTENDANCE_MONTH_LOCKED');
        $api($this->w['clerk'])->postJson($url('/register'), ['date' => '2026-10-07', 'items' => [['employment_record_id' => $this->w['employment']->id, 'first_half' => 'present', 'second_half' => 'present']]])
            ->assertStatus(409)->assertJsonPath('error.code', 'STAFF_ATTENDANCE_MONTH_LOCKED');
        $api($this->w['clerk'])->postJson($url("/records/{$record->id}/corrections"), ['expected_version' => 1, 'first_half' => 'absent', 'second_half' => 'present', 'reason_code' => 'late_information'])
            ->assertStatus(409)->assertJsonPath('error.code', 'STAFF_ATTENDANCE_MONTH_LOCKED');
        $this->assertSame([$records, $corrections, $before], [$this->rowsIn('staff_attendance_records'), $this->rowsIn('staff_attendance_corrections'), $events()], 'no row and no audit event');

        // The same API still records an open month; the other refusals are unchanged.
        $api($this->w['clerk'])->postJson($url('/records'), ['employment_record_id' => $this->w['employment']->id, 'date' => '2026-11-03', 'first_half' => 'present', 'second_half' => 'present'])->assertCreated();
        $viewer = $this->createUserWithCapabilities($this->w['school'], ['hr.staff_attendance.view']);
        $api($viewer)->postJson($url("/records/{$record->id}/corrections"), ['expected_version' => 1, 'first_half' => 'absent', 'second_half' => 'present', 'reason_code' => 'other'])->assertForbidden();
        $api($this->w['clerk'])->postJson($url('/records/'.Str::uuid().'/corrections'), ['expected_version' => 1, 'first_half' => 'absent', 'second_half' => 'present', 'reason_code' => 'other'])->assertNotFound();
        $other = $this->createSchool();
        $api($this->w['clerk'])->postJson($url("/records/{$record->id}/corrections", $other->id), ['expected_version' => 1, 'first_half' => 'absent', 'second_half' => 'present', 'reason_code' => 'other'])->assertNotFound();

        DB::table('schools')->where('id', $this->w['school']->id)->update(['status' => 'suspended']);
        $api($this->w['clerk'])->postJson($url('/records'), ['employment_record_id' => $this->w['employment']->id, 'date' => '2026-11-04', 'first_half' => 'present', 'second_half' => 'present'])->assertNotFound();
        $this->assertSame([$records + 1, $corrections], [$this->rowsIn('staff_attendance_records'), $this->rowsIn('staff_attendance_corrections')]);
    }

    #[Test]
    public function the_php_lock_the_trigger_and_the_sql_function_take_one_advisory_key(): void
    {
        $school = $this->w['school'];
        $key = fn (string $month) => (int) DB::selectOne('SELECT public.staff_attendance_month_key(?, ?) AS k', [$school->id, $month])->k;
        $held = fn (int $sqlKey) => collect(DB::select(
            "SELECT mode FROM pg_locks WHERE locktype = 'advisory' AND pid = pg_backend_pid() AND objsubid = 1 AND ((classid::bigint << 32) | objid::bigint) = ?",
            [$sqlKey],
        ))->pluck('mode')->all();

        // The PHP lock, on a connection of its own so its release at commit is observable. HR.R1-C22: a month in the
        // future, because the test's own transaction holds every past month shared (the fixture's past-dated week).
        config(['database.connections.c20_probe' => config('database.connections.pgsql')]);
        $month = '2030-01-01';
        $probeKey = $key($month);
        DB::usingConnection('c20_probe', function () use ($school, $held, $probeKey, $month): void {
            DB::transaction(function () use ($school, $held, $probeKey, $month): void {
                StaffAttendanceMonthLocks::take($school, $month, shared: true);
                $this->assertSame(['ShareLock'], $held($probeKey), 'the PHP lock is the SQL key, shared');
            });
            $this->assertSame([], $held($probeKey), 'released at commit');
            DB::transaction(function () use ($school, $held, $probeKey, $month): void {
                StaffAttendanceMonthLocks::take($school, $month, shared: false);
                $this->assertSame(['ExclusiveLock'], $held($probeKey), 'and exclusive for locking');
                DB::rollBack();
            });
            $this->assertSame([], $held($probeKey), 'released at rollback');
        });
        DB::purge('c20_probe');

        // The triggers take `staff_attendance_month_key(NEW.school_id, …)` (pinned by `staff_attendance_month_lock_invariants`),
        // and that function is exactly the PHP key string's hash, for any School and month.
        foreach (['2026-09-01', '2026-10-01', '2030-01-01'] as $m) {
            $this->assertSame($key($m), (int) DB::selectOne('SELECT hashtextextended(?, 0) AS k', ['hr.staff_month:'.$school->id.':'.substr($m, 0, 7)])->k);
        }
    }
}
