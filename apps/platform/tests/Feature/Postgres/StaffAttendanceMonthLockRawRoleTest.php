<?php

namespace Tests\Feature\Postgres;

use App\Domain\StaffAttendance\Application\StaffAttendanceService;
use App\Domain\StaffAttendance\Infrastructure\StaffAttendanceLockedCorrection;
use App\Domain\StaffAttendance\Infrastructure\StaffAttendanceRecord;
use App\Models\User;
use App\Support\Tenancy\TenantRls;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\StaffAttendance\Concerns\CreatesStaffAttendanceFixtures;
use Tests\TestCase;

/**
 * HR.R1-C18 (WO-6): the month lock as the database enforces it, against the runtime role writing
 * raw SQL with no service in the way. A raw write takes the same School-month lock as the service,
 * so it serializes with locking; a locked month changes only as the application of an approved
 * request, which the database applies itself, from the request's own values, once.
 */
class StaffAttendanceMonthLockRawRoleTest extends TestCase
{
    use CreatesStaffAttendanceFixtures;

    /** @var array<string, mixed> */
    private array $w;

    private User $principal;

    private User $head;

    private StaffAttendanceRecord $record;

    private StaffAttendanceLockedCorrection $request;

    /** A second record, recorded before the lock, for a request by a lock-key holder. */
    private StaffAttendanceRecord $second;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-05 10:00:00');
        $this->w = $this->attendanceWorld();
        $keys = ['hr.staff_attendance.view', 'hr.staff_attendance.manage', 'hr.staff_attendance.lock'];
        $this->principal = $this->createUserWithCapabilities($this->w['school'], $keys);
        $this->head = $this->createUserWithCapabilities($this->w['school'], $keys);
        $this->record = $this->recordAttendance($this->w, '2026-10-05', 'present', 'present');
        $this->second = $this->recordAttendance($this->w, '2026-10-02', 'present', 'present');
        $service = app(StaffAttendanceService::class);
        $service->lockMonth($this->w['school'], '2026-10', $this->principal, $this->freshMfaProofFor($this->principal));
        $this->request = $service->requestLockedCorrection($this->w['school'], $this->record->id, 1, 'absent', 'present', 'late_information', (string) Str::uuid(), $this->w['clerk']);
    }

    /** Runs raw SQL as the runtime role in its own savepoint; returns the database's refusal, or '' if it succeeded. */
    private function raw(callable $statement): string
    {
        DB::connection('pgsql')->select('select set_config(?, ?, false)', [TenantRls::SESSION_VAR, $this->w['school']->id]);
        try {
            DB::connection('pgsql')->transaction($statement);

            return '';
        } catch (QueryException $e) {
            return $e->getMessage();
        } finally {
            DB::connection('pgsql')->select('select set_config(?, ?, false)', [TenantRls::SESSION_VAR, '']);
        }
    }

    private function correctionRow(array $overrides = []): array
    {
        return [...[
            'id' => (string) Str::uuid7(), 'school_id' => $this->w['school']->id, 'staff_attendance_record_id' => $this->record->id,
            'employment_record_id' => $this->record->employment_record_id, 'from_version' => 1, 'to_version' => 2,
            'before_first_half_status' => 'present', 'before_second_half_status' => 'present',
            'after_first_half_status' => 'absent', 'after_second_half_status' => 'present',
            'reason_code' => 'late_information', 'corrected_by_user_id' => $this->head->id, 'created_at' => now(),
        ], ...$overrides];
    }

    private function version(): int
    {
        return (int) $this->inSchool($this->w['school'], fn () => DB::table('staff_attendance_records')->where('id', $this->record->id)->value('version'));
    }

    private function decide(array $values): string
    {
        return $this->raw(fn () => DB::table('staff_attendance_locked_corrections')->where('id', $this->request->id)->update($values));
    }

    #[Test]
    public function a_raw_record_or_correction_cannot_land_in_a_locked_month(): void
    {
        $this->assertStringContainsString('staff_attendance_month_locked', $this->raw(fn () => DB::table('staff_attendance_records')->insert([
            'id' => (string) Str::uuid7(), 'school_id' => $this->w['school']->id, 'employment_record_id' => $this->record->employment_record_id,
            'employee_id' => $this->record->employee_id, 'attendance_date' => '2026-10-02', 'first_half_status' => 'present',
            'recorded_by_user_id' => $this->w['clerk']->id, 'created_at' => now(), 'updated_at' => now(),
        ])));
        $this->assertStringContainsString('staff_attendance_month_locked', $this->raw(function () {
            DB::table('staff_attendance_corrections')->insert($this->correctionRow());
            DB::table('staff_attendance_records')->where('id', $this->record->id)->update(['first_half_status' => 'absent', 'version' => 2]);
        }));
        // With no correction row at all, the record's own guard refuses first; either way nothing changes.
        $this->assertMatchesRegularExpression('/staff_attendance_correction_missing|staff_attendance_month_locked/', $this->raw(fn () => DB::table('staff_attendance_records')->where('id', $this->record->id)->update(['first_half_status' => 'absent', 'version' => 2])));
        $this->assertSame(1, $this->version());
    }

    #[Test]
    public function a_pending_request_authorizes_nothing_and_cannot_be_forged_into_an_approval(): void
    {
        $this->assertStringContainsString('staff_attendance_month_locked', $this->raw(function () {
            DB::table('staff_attendance_corrections')->insert($this->correctionRow(['locked_correction_id' => $this->request->id]));
            DB::table('staff_attendance_records')->where('id', $this->record->id)->update(['first_half_status' => 'absent', 'version' => 2]);
        }), 'a correction naming a request that is still pending');

        $this->assertStringContainsString('the reviewer holds hr.staff_attendance.lock', $this->decide(['status' => 'approved', 'decided_by_user_id' => $this->w['clerk']->id, 'decided_at' => now()]), 'the requester, who holds no lock key');
        $own = app(StaffAttendanceService::class)->requestLockedCorrection($this->w['school'], $this->second->id, 1, 'absent', null, 'other', (string) Str::uuid(), $this->principal);
        $this->assertStringContainsString('the reviewer is independent', $this->raw(fn () => DB::table('staff_attendance_locked_corrections')->where('id', $own->id)
            ->update(['status' => 'approved', 'decided_by_user_id' => $this->principal->id, 'decided_at' => now()])), 'a lock-key holder approving their own request');
        $outsider = $this->createUserWithCapabilities($this->w['school'], ['hr.staff_attendance.view', 'hr.staff_attendance.manage']);
        $this->assertStringContainsString('the reviewer holds hr.staff_attendance.lock', $this->decide(['status' => 'approved', 'decided_by_user_id' => $outsider->id, 'decided_at' => now()]));
        $this->assertStringContainsString('the terms of a request are frozen', $this->decide(['after_first_half_status' => 'present', 'after_second_half_status' => 'absent']));
        $this->assertStringContainsString('a request is born requested', $this->raw(fn () => DB::table('staff_attendance_locked_corrections')->insert([
            'id' => (string) Str::uuid7(), 'school_id' => $this->w['school']->id, 'staff_attendance_record_id' => $this->record->id,
            'employment_record_id' => $this->record->employment_record_id, 'month' => '2026-10-01', 'from_version' => 1,
            'before_first_half_status' => 'present', 'before_second_half_status' => 'present', 'after_first_half_status' => 'absent',
            'after_second_half_status' => 'absent', 'reason_code' => 'other', 'form_key' => (string) Str::uuid(), 'status' => 'approved',
            'requested_by_user_id' => $this->w['clerk']->id, 'decided_by_user_id' => $this->head->id, 'decided_at' => now(),
        ])));
        $this->assertSame(1, $this->version());
    }

    #[Test]
    public function an_approval_applies_exactly_what_was_requested_once_and_is_final(): void
    {
        $this->assertSame('', $this->decide(['status' => 'approved', 'decided_by_user_id' => $this->head->id, 'decided_at' => now()]));

        $record = $this->inSchool($this->w['school'], fn () => DB::table('staff_attendance_records')->where('id', $this->record->id)->first());
        $this->assertSame(['absent', 'present', 2], [$record->first_half_status, $record->second_half_status, $record->version]);
        $this->assertSame(1, $this->inSchool($this->w['school'], fn () => DB::table('staff_attendance_corrections')->where('locked_correction_id', $this->request->id)->count()));

        $this->assertStringContainsString('a decision is final', $this->decide(['status' => 'refused']));
        $this->assertNotSame('', $this->raw(function () {
            DB::table('staff_attendance_corrections')->insert($this->correctionRow([
                'from_version' => 2, 'to_version' => 3, 'before_first_half_status' => 'absent', 'after_first_half_status' => 'present',
                'locked_correction_id' => $this->request->id,
            ]));
            DB::table('staff_attendance_records')->where('id', $this->record->id)->update(['first_half_status' => 'present', 'version' => 3]);
        }), 'an applied approval is never reused');
        $this->assertSame(2, $this->version());
    }

    #[Test]
    public function a_lock_is_append_only_and_only_a_lock_key_holder_records_one(): void
    {
        $this->assertNotSame('', $this->raw(fn () => DB::table('staff_attendance_month_locks')->update(['month' => '2026-09-01'])), 'never moved');
        $this->assertNotSame('', $this->raw(fn () => DB::table('staff_attendance_month_locks')->delete()), 'never removed, never reopened');
        $this->assertStringContainsString('the locker holds hr.staff_attendance.lock', $this->raw(fn () => DB::table('staff_attendance_month_locks')->insert([
            'id' => (string) Str::uuid7(), 'school_id' => $this->w['school']->id, 'month' => '2026-09-01', 'locked_by_user_id' => $this->w['clerk']->id,
        ])));
        $this->assertStringContainsString('has ended', $this->raw(fn () => DB::table('staff_attendance_month_locks')->insert([
            'id' => (string) Str::uuid7(), 'school_id' => $this->w['school']->id, 'month' => now()->addYears(2)->format('Y-m-01'), 'locked_by_user_id' => $this->head->id,
        ])));
        $this->assertSame(1, $this->inSchool($this->w['school'], fn () => DB::table('staff_attendance_month_locks')->count()));
    }

    #[Test]
    public function another_school_reads_and_writes_none_of_it(): void
    {
        $other = $this->createSchool();
        DB::connection('pgsql')->select('select set_config(?, ?, false)', [TenantRls::SESSION_VAR, $other->id]);
        try {
            $this->assertSame([0, 0], [DB::table('staff_attendance_month_locks')->count(), DB::table('staff_attendance_locked_corrections')->count()]);
            $this->assertSame(0, DB::table('staff_attendance_locked_corrections')->where('id', $this->request->id)->update(['status' => 'refused']));
        } finally {
            DB::connection('pgsql')->select('select set_config(?, ?, false)', [TenantRls::SESSION_VAR, '']);
        }
        $this->assertSame([0, 0], [DB::table('staff_attendance_month_locks')->count(), DB::table('staff_attendance_locked_corrections')->count()], 'no School context, no rows');
    }
}
