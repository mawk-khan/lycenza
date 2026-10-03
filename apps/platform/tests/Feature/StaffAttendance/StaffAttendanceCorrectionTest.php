<?php

namespace Tests\Feature\StaffAttendance;

use App\Domain\Leave\Application\DayPortion;
use App\Domain\Leave\Application\StaffCalendarService;
use App\Domain\StaffAttendance\Application\Exceptions\StaffAttendanceException;
use App\Domain\StaffAttendance\Application\StaffAttendanceReadService;
use App\Support\Tenancy\TenantRls;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\StaffAttendance\Concerns\CreatesStaffAttendanceFixtures;
use Tests\TestCase;

/**
 * HRX.3 (ADR 0065 §24.8, §24.9): a correction is compare-and-swap on the
 * record's version plus an append-only correction row with both halves
 * before and after and a closed neutral reason. The database refuses any
 * other change -- including raw SQL with the runtime role.
 */
class StaffAttendanceCorrectionTest extends TestCase
{
    use CreatesStaffAttendanceFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-05 10:00:00');
    }

    private function refusal(callable $command): string
    {
        try {
            $command();
        } catch (StaffAttendanceException $e) {
            return $e->errorCode();
        }

        return 'accepted';
    }

    /** Runs raw SQL as the runtime role in its own savepoint; returns the database's refusal message, or '' if it succeeded. */
    private function raw(string $schoolId, callable $statement): string
    {
        DB::connection('pgsql')->select('select set_config(?, ?, false)', [TenantRls::SESSION_VAR, $schoolId]);
        try {
            DB::connection('pgsql')->transaction($statement);

            return '';
        } catch (QueryException $e) {
            return $e->getMessage();
        } finally {
            DB::connection('pgsql')->select('select set_config(?, ?, false)', [TenantRls::SESSION_VAR, '']);
        }
    }

    #[Test]
    public function corrections_move_the_version_once_and_keep_every_step_as_evidence(): void
    {
        $w = $this->attendanceWorld();
        $record = $this->recordAttendance($w, '2026-09-28', 'present', 'present');

        $record = $this->correctAttendance($w, $record, 'absent', 'absent', 'entered_in_error');
        $this->assertSame(['absent', 'absent', 2], [$record->first_half_status, $record->second_half_status, $record->version]);
        $record = $this->correctAttendance($w, $record, 'present', 'absent', 'late_information');
        $record = $this->correctAttendance($w, $record, 'present', 'present', 'administrative_review');
        $this->assertSame(4, $record->version);

        $shown = app(StaffAttendanceReadService::class)->show($w['school'], $record->id, $w['clerk']);
        $this->assertSame([
            [1, 2, ['firstHalf' => 'present', 'secondHalf' => 'present'], ['firstHalf' => 'absent', 'secondHalf' => 'absent'], 'entered_in_error'],
            [2, 3, ['firstHalf' => 'absent', 'secondHalf' => 'absent'], ['firstHalf' => 'present', 'secondHalf' => 'absent'], 'late_information'],
            [3, 4, ['firstHalf' => 'present', 'secondHalf' => 'absent'], ['firstHalf' => 'present', 'secondHalf' => 'present'], 'administrative_review'],
        ], array_map(fn ($c) => [$c['fromVersion'], $c['toVersion'], $c['before'], $c['after'], $c['reasonCode']], $shown['corrections']));

        $this->inSchool($w['school'], function () use ($record) {
            $this->assertSame(3, DB::table('school_audit_events')->where('event_type', 'staff_attendance.corrected')->count());
            $events = DB::table('domain_event_outbox')->where('event_type', 'staff_attendance.corrected.v1')->orderBy('id')->get();
            $this->assertCount(3, $events);
            $payload = json_decode($events->first()->payload, true);
            $this->assertSame($record->id, $payload['staffAttendanceRecordId']);
            $keys = array_keys($payload);
            sort($keys);
            $this->assertSame(['after', 'attendanceDate', 'before', 'employeeId', 'employmentRecordId', 'fromVersion', 'reasonCode', 'staffAttendanceRecordId', 'toVersion'], $keys, 'minimized: no leave detail, no free text');
        });
    }

    #[Test]
    public function a_correction_needs_the_current_version_a_real_change_and_a_closed_reason(): void
    {
        $w = $this->attendanceWorld();
        $record = $this->recordAttendance($w, '2026-09-28', 'present', 'present');

        $this->assertSame('STAFF_ATTENDANCE_VERSION_STALE', $this->refusal(fn () => $this->correctAttendance($w, $record, 'absent', 'present', 'entered_in_error', 2)));
        $this->assertSame('STAFF_ATTENDANCE_CORRECTION_UNCHANGED', $this->refusal(fn () => $this->correctAttendance($w, $record, 'present', 'present')));
        $this->assertSame('STAFF_ATTENDANCE_REASON_INVALID', $this->refusal(fn () => $this->correctAttendance($w, $record, 'absent', 'present', 'doctor_note')));
        $this->assertSame('STAFF_ATTENDANCE_CLEAR_REASON', $this->refusal(fn () => $this->correctAttendance($w, $record, null, null, 'late_information')));

        $moved = $this->correctAttendance($w, $record, 'absent', 'present');
        $this->assertSame('STAFF_ATTENDANCE_VERSION_STALE', $this->refusal(fn () => $this->correctAttendance($w, $record, 'present', 'absent', 'other', 1)), 'a stale reader loses deterministically; no lost update');
        $this->assertSame(['absent', 'present', 2], [$moved->first_half_status, $moved->second_half_status, $moved->version]);

        // Entered in error: both halves cleared; the row keeps its history and can be corrected again.
        $cleared = $this->correctAttendance($w, $moved, null, null, 'entered_in_error');
        $this->assertSame([null, null, 3], [$cleared->first_half_status, $cleared->second_half_status, $cleared->version]);
        $this->assertSame('unrecorded', app(StaffAttendanceReadService::class)->register($w['school'], '2026-09-28', $w['clerk'])['rows'][0]['day']['summary']);
        $this->assertSame(4, $this->correctAttendance($w, $cleared, 'present', null, 'late_information')->version);
    }

    #[Test]
    public function a_correction_rechecks_approved_leave_and_new_evidence_needs_working_time(): void
    {
        $w = $this->attendanceWorld();
        $absent = $this->recordAttendance($w, '2026-09-28', 'absent', 'absent');
        $this->approvedLeave($w, '2026-09-28', '2026-09-28', 'first_half');

        $this->assertSame('STAFF_ATTENDANCE_ON_LEAVE', $this->refusal(fn () => $this->correctAttendance($w, $absent, 'present', 'absent')), 'cancel the leave first');
        $partial = $this->correctAttendance($w, $absent, 'absent', 'present', 'late_information');
        $this->assertSame(['absent', 'present'], [$partial->first_half_status, $partial->second_half_status], 'the leave half keeps its evidence; the other half is corrected');
        $cleared = $this->correctAttendance($w, $partial, 'absent', null, 'entered_in_error');
        $this->assertSame(['absent', null], [$cleared->first_half_status, $cleared->second_half_status], 'clearing a half is always allowed');

        // Saturday afternoon is off: it cannot gain evidence by correction.
        $saturday = $this->recordAttendance($w, '2026-10-03', 'present', null);
        $this->assertSame('STAFF_ATTENDANCE_NOT_WORKING_TIME', $this->refusal(fn () => $this->correctAttendance($w, $saturday, 'present', 'present')));

        // A holiday added afterwards never hides or freezes existing evidence: it can still be corrected.
        $tuesday = $this->recordAttendance($w, '2026-09-29', 'present', 'present');
        app(StaffCalendarService::class)->addHoliday($w['school'], '2026-09-29', DayPortion::Full, 'Declared later', $w['admin']);
        $day = app(StaffAttendanceReadService::class)->register($w['school'], '2026-09-29', $w['clerk'])['rows'][0]['day'];
        $this->assertSame(['present', 'holiday'], [$day['firstHalf']['state'], $day['firstHalf']['calendar']], 'recorded evidence stays visible beside today\'s calendar');
        $this->assertSame(2, $this->correctAttendance($w, $tuesday, 'absent', 'present', 'late_information')->version);
    }

    #[Test]
    public function a_correction_after_separation_is_allowed_while_initial_recording_is_not(): void
    {
        $w = $this->attendanceWorld();
        $record = $this->recordAttendance($w, '2026-09-28', 'present', 'present');
        $this->inSchool($w['school'], fn () => DB::table('employment_records')->where('id', $w['employment']->id)->update(['status' => 'separated', 'ends_on' => '2026-09-30']));

        $this->assertSame('STAFF_ATTENDANCE_EMPLOYMENT_NOT_ELIGIBLE', $this->refusal(fn () => $this->recordAttendance($w, '2026-09-29', 'present', 'present')));
        $this->assertSame(2, $this->correctAttendance($w, $record, 'present', 'absent', 'late_information')->version);
    }

    #[Test]
    public function raw_sql_with_the_runtime_role_cannot_rewrite_attendance_without_correction_evidence(): void
    {
        $w = $this->attendanceWorld();
        $record = $this->recordAttendance($w, '2026-09-28', 'present', 'present');
        $school = $w['school']->id;
        $this->assertSame('school_os_app', DB::selectOne('select current_user as u')->u);

        $this->assertStringContainsString('staff_attendance_version_invalid', $this->raw($school, fn () => DB::update("update staff_attendance_records set first_half_status = 'absent' where id = ?", [$record->id])));
        $this->assertStringContainsString('staff_attendance_correction_missing', $this->raw($school, fn () => DB::update("update staff_attendance_records set first_half_status = 'absent', version = version + 1 where id = ?", [$record->id])));
        $this->assertStringContainsString('staff_attendance_immutable', $this->raw($school, fn () => DB::update("update staff_attendance_records set attendance_date = '2026-09-29' where id = ?", [$record->id])));
        $this->assertStringContainsString('permission denied', $this->raw($school, fn () => DB::delete('delete from staff_attendance_records where id = ?', [$record->id])));

        // A forged correction must start from the current version and values...
        $insert = fn (int $from, ?string $beforeFirst, ?string $afterFirst, string $reason = 'other') => DB::insert(
            'insert into staff_attendance_corrections (id, school_id, staff_attendance_record_id, employment_record_id, from_version, to_version, before_first_half_status, before_second_half_status, after_first_half_status, after_second_half_status, reason_code, corrected_by_user_id) values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [(string) Str::uuid7(), $school, $record->id, $record->employment_record_id, $from, $from + 1, $beforeFirst, 'present', $afterFirst, 'present', $reason, $w['clerk']->id],
        );
        $this->assertStringContainsString('staff_attendance_correction_stale', $this->raw($school, fn () => $insert(2, 'present', 'absent')));
        $this->assertStringContainsString('staff_attendance_correction_stale', $this->raw($school, fn () => $insert(1, 'absent', 'present')));
        $this->assertStringContainsString('staff_attendance_corrections_shape_check', $this->raw($school, fn () => $insert(1, 'present', 'late')));
        // Corrections are append-only.
        $corrected = $this->correctAttendance($w, $record, 'absent', 'present');
        $this->assertStringContainsString('permission denied', $this->raw($school, fn () => DB::update("update staff_attendance_corrections set reason_code = 'other' where staff_attendance_record_id = ?", [$record->id])));
        $this->assertStringContainsString('permission denied', $this->raw($school, fn () => DB::delete('delete from staff_attendance_corrections where staff_attendance_record_id = ?', [$record->id])));
        // A record cannot be inserted already corrected, nor empty.
        $this->assertStringContainsString('staff_attendance_version_invalid', $this->raw($school, fn () => DB::insert(
            "insert into staff_attendance_records (id, school_id, employment_record_id, employee_id, attendance_date, first_half_status, version, recorded_by_user_id, created_at, updated_at) values (?, ?, ?, ?, '2026-09-29', 'present', 2, ?, now(), now())",
            [(string) Str::uuid7(), $school, $record->employment_record_id, $record->employee_id, $w['clerk']->id],
        )));
        $this->assertStringContainsString('staff_attendance_records_shape_check', $this->raw($school, fn () => DB::insert(
            "insert into staff_attendance_records (id, school_id, employment_record_id, employee_id, attendance_date, recorded_by_user_id, created_at, updated_at) values (?, ?, ?, ?, '2026-09-29', ?, now(), now())",
            [(string) Str::uuid7(), $school, $record->employment_record_id, $record->employee_id, $w['clerk']->id],
        )));

        $this->assertSame(['absent', 'present', 2], [$corrected->first_half_status, $corrected->second_half_status, $corrected->version], 'only the sanctioned correction changed the row');
        $this->assertSame(1, $this->inSchool($w['school'], fn () => DB::table('staff_attendance_corrections')->count()));

        // A matching correction that is never applied is refused at commit (forced here, inside the test transaction).
        $this->assertStringContainsString('staff_attendance_correction_unapplied', $this->raw($school, function () use ($school, $record, $w) {
            DB::insert(
                'insert into staff_attendance_corrections (id, school_id, staff_attendance_record_id, employment_record_id, from_version, to_version, before_first_half_status, before_second_half_status, after_first_half_status, after_second_half_status, reason_code, corrected_by_user_id) values (?, ?, ?, ?, 2, 3, ?, ?, ?, ?, ?, ?)',
                [(string) Str::uuid7(), $school, $record->id, $record->employment_record_id, 'absent', 'present', 'present', 'present', 'other', $w['clerk']->id],
            );
            DB::statement('SET CONSTRAINTS trg_staff_attendance_corrections_applied IMMEDIATE');
        }));
        DB::statement('SET CONSTRAINTS trg_staff_attendance_corrections_applied DEFERRED');
    }
}
