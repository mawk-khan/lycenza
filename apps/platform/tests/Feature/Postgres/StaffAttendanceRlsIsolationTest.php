<?php

namespace Tests\Feature\Postgres;

use App\Domain\StaffAttendance\Application\StaffAttendanceService;
use App\Domain\StaffAttendance\Infrastructure\StaffAttendanceRecord;
use App\Support\Tenancy\TenantRls;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\StaffAttendance\Concerns\CreatesStaffAttendanceFixtures;
use Tests\TestCase;

/**
 * HRX.3 (ADR 0065 §14, §24.9, CLAUDE.md rule 28): both Staff Attendance
 * tables are forced-RLS and same-School by construction. Proven at the
 * raw-SQL layer with the runtime role (`school_os_app`) and at the Eloquent
 * layer, and missing tenant context fails closed.
 */
class StaffAttendanceRlsIsolationTest extends TestCase
{
    use CreatesStaffAttendanceFixtures;

    private const TABLES = ['staff_attendance_records', 'staff_attendance_corrections'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-05 10:00:00');
    }

    private function context(?string $schoolId): void
    {
        DB::connection('pgsql')->select('select set_config(?, ?, false)', [TenantRls::SESSION_VAR, $schoolId ?? '']);
    }

    private function refused(callable $statement): string
    {
        try {
            DB::connection('pgsql')->transaction($statement);

            return '';
        } catch (QueryException $e) {
            return $e->getMessage();
        }
    }

    /** @return array{0: array<string, mixed>, 1: array<string, mixed>, 2: StaffAttendanceRecord} */
    private function twoSchools(): array
    {
        $a = $this->attendanceWorld();
        $b = $this->attendanceWorld();
        $record = $this->recordAttendance($a, '2026-09-28', 'present', 'present');
        app(StaffAttendanceService::class)->correct($a['school'], $record->id, 1, 'absent', 'present', 'entered_in_error', $a['clerk']);

        return [$a, $b, $record];
    }

    #[Test]
    public function both_tables_have_forced_rls_and_the_runtime_role_cannot_bypass_it(): void
    {
        $this->assertSame('school_os_app', DB::selectOne('select current_user as u')->u);
        foreach (self::TABLES as $table) {
            $row = DB::selectOne('select relrowsecurity, relforcerowsecurity from pg_class where relname = ?', [$table]);
            $this->assertTrue((bool) $row->relrowsecurity && (bool) $row->relforcerowsecurity, "{$table}: forced RLS");
        }
        $this->assertFalse((bool) DB::selectOne("select rolbypassrls from pg_roles where rolname = 'school_os_app'")->rolbypassrls);
    }

    #[Test]
    public function no_context_sees_nothing_and_another_school_can_neither_read_nor_update(): void
    {
        [$a, $b, $record] = $this->twoSchools();

        $this->context(null);
        foreach (self::TABLES as $table) {
            $this->assertSame(0, (int) DB::selectOne("select count(*) as c from {$table}")->c, "{$table}: no context, no rows (fail closed)");
        }

        $this->context($b['school']->id);
        foreach (self::TABLES as $table) {
            $this->assertSame(0, (int) DB::selectOne("select count(*) as c from {$table} where school_id = ?", [$a['school']->id])->c, "{$table}: School B sees none of A's rows");
        }
        $this->assertSame(0, DB::update("update staff_attendance_records set first_half_status = 'present' where id = ?", [$record->id]), 'a cross-School update touches nothing');

        $this->context($a['school']->id);
        $this->assertSame([1, 1], [(int) DB::selectOne('select count(*) as c from staff_attendance_records')->c, (int) DB::selectOne('select count(*) as c from staff_attendance_corrections')->c]);
        $this->context(null);

        // The Eloquent layer agrees: School B's context finds nothing of A's.
        $this->assertNull($this->inSchool($b['school'], fn () => StaffAttendanceRecord::query()->find($record->id)));
        $this->assertNotNull($this->inSchool($a['school'], fn () => StaffAttendanceRecord::query()->find($record->id)));
    }

    #[Test]
    public function a_foreign_school_id_or_a_cross_school_reference_is_refused_by_the_database(): void
    {
        [$a, $b, $record] = $this->twoSchools();
        $this->context($b['school']->id);

        // A row naming School A, written in School B's context, fails the RLS write check.
        $this->assertNotSame('', $this->refused(fn () => DB::insert(
            "insert into staff_attendance_records (id, school_id, employment_record_id, employee_id, attendance_date, first_half_status, recorded_by_user_id, created_at, updated_at) values (?, ?, ?, ?, '2026-09-29', 'present', ?, now(), now())",
            [(string) Str::uuid7(), $a['school']->id, $a['employment']->id, $a['employment']->employee_id, $b['clerk']->id],
        )));
        // A School B record naming School A's employment: the trigger cannot even resolve it, and the composite FK refuses it.
        $this->assertNotSame('', $this->refused(fn () => DB::insert(
            "insert into staff_attendance_records (id, school_id, employment_record_id, employee_id, attendance_date, first_half_status, recorded_by_user_id, created_at, updated_at) values (?, ?, ?, ?, '2026-09-29', 'present', ?, now(), now())",
            [(string) Str::uuid7(), $b['school']->id, $a['employment']->id, $a['employment']->employee_id, $b['clerk']->id],
        )));
        // A School B correction pointing at School A's record: invisible to the trigger under RLS, and the composite FK refuses it.
        $this->assertMatchesRegularExpression('/staff_attendance_correction_stale|staff_attendance_corrections_record_fk/', $this->refused(fn () => DB::insert(
            'insert into staff_attendance_corrections (id, school_id, staff_attendance_record_id, employment_record_id, from_version, to_version, before_first_half_status, before_second_half_status, after_first_half_status, after_second_half_status, reason_code, corrected_by_user_id) values (?, ?, ?, ?, 2, 3, ?, ?, ?, ?, ?, ?)',
            [(string) Str::uuid7(), $b['school']->id, $record->id, $record->employment_record_id, 'absent', 'present', 'present', 'present', 'other', $b['clerk']->id],
        )));
        $this->context(null);
    }
}
