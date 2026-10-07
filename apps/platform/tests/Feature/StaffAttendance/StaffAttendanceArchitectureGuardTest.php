<?php

namespace Tests\Feature\StaffAttendance;

use App\Domain\Leave\Application\AttendancePresenceConflictReader;
use App\Domain\StaffAttendance\Application\StaffAttendancePresenceReader;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * HRX.3 (ADR 0065 §24) static and schema guards. They fail loudly the moment
 * a change breaks a boundary this checkpoint relies on:
 * - only `present`/`absent` per half are stored; no leave, holiday, clock,
 *   device, location, biometric, health or free-text column;
 * - one-way dependencies: StaffAttendance -> HR / Leave through their
 *   Application contracts only; never Leave/HR/Payroll -> StaffAttendance,
 *   never StaffAttendance -> Payroll; Leave's port bound in the composition
 *   root;
 * - no role-name authorization; independent capabilities; HRX.4's `.self`
 *   is read only;
 * - the `teacher` role untouched (E33); no own-attendance write;
 * - Staff Attendance is not Student attendance.
 */
class StaffAttendanceArchitectureGuardTest extends TestCase
{
    private const TABLES = ['staff_attendance_records', 'staff_attendance_corrections'];

    /** HRX-L1/L2, §24.14: health, free text, clock/time-tracking, device, location or biometric evidence. */
    private const PROHIBITED_COLUMN = '/medical|diagnos|health|illness|sick|certificate|doctor|attachment|document|file|note|remark|comment|description|free_text|reason_text|details|biometric|fingerprint|face|photo|device|gps|latitude|longitude|location|geo|clock|punch|check_in|check_out|time_in|time_out|minutes|hours|overtime|late|shift|break|wage|deduct|pay/';

    /** The only Leave classes Staff Attendance may name (Leave's Application contracts). */
    private const ALLOWED_LEAVE_CLASSES = [
        'App\\Domain\\Leave\\Application\\AttendancePresenceConflictReader',
        'App\\Domain\\Leave\\Application\\Exceptions\\LeaveException',
        'App\\Domain\\Leave\\Application\\LeaveCapabilities',
        'App\\Domain\\Leave\\Application\\LeaveCoverageReader',
        'App\\Domain\\Leave\\Application\\LeaveLocks',
        'App\\Domain\\Leave\\Application\\StaffCalendarService',
    ];

    private function code(string $file): string
    {
        return (string) preg_replace('#/\*.*?\*/|//[^\n]*#s', '', (string) file_get_contents($file));
    }

    /** @return list<string> */
    private function phpFiles(string $dir): array
    {
        $files = [];
        exec('find '.escapeshellarg(app_path($dir)).' -name "*.php"', $files);
        sort($files);

        return $files;
    }

    #[Test]
    public function only_two_closed_half_values_are_stored_and_nothing_sensitive_or_time_tracking_exists(): void
    {
        $columns = DB::select('SELECT table_name, column_name FROM information_schema.columns WHERE table_schema = ? AND table_name IN (?, ?)', ['public', ...self::TABLES]);
        $this->assertNotEmpty($columns);
        $hits = array_values(array_filter(array_map(fn ($c) => "{$c->table_name}.{$c->column_name}", $columns), fn (string $c) => preg_match(self::PROHIBITED_COLUMN, explode('.', $c)[1]) === 1));
        $this->assertSame([], $hits, 'Staff Attendance is daily presence evidence only (ADR 0065 §24.14, HRX-L1/L2)');

        $check = (string) DB::selectOne("select pg_get_constraintdef(oid) as d from pg_constraint where conname = 'staff_attendance_records_shape_check'")->d;
        foreach (['leave', 'holiday', 'off', 'half_day', 'late', 'excused'] as $derived) {
            $this->assertStringNotContainsString("'{$derived}", $check, "{$derived} is derived or out of scope, never a stored attendance value");
        }
        $this->assertStringContainsString("'present'", $check);
        $this->assertStringContainsString("'absent'", $check);
    }

    #[Test]
    public function dependencies_run_one_way_through_application_contracts(): void
    {
        foreach ($this->phpFiles('Domain/StaffAttendance') as $file) {
            $code = $this->code($file);
            foreach (['App\\Domain\\Payroll', 'App\\Domain\\Finance', 'App\\Domain\\Attendance\\', 'App\\Domain\\HR\\Infrastructure', 'App\\Domain\\Leave\\Infrastructure', 'leave_requests', 'leave_request_days', 'leave_ledger', 'employees.user_id', "'user_id'"] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $code, "{$file}: Staff Attendance never depends on {$forbidden}");
            }
            // HRX.5: Staff Attendance serves Payroll (one read contract) but never reads or writes a Payroll table.
            $this->assertDoesNotMatchRegularExpression('/\bpayroll_(runs?|run_[a-z_]+|periods|adjustments|lwf_[a-z_]+|statutory_[a-z_]+|structures?|components?)\b/', $code, "{$file}: Staff Attendance never touches a Payroll table");
            // HRX.4: the acting Employee is resolved through HR's one resolver, in the read service only.
            if (str_contains($code, 'ActingEmployeeResolver')) {
                $this->assertSame('StaffAttendanceReadService.php', basename($file), "{$file}: only the read service resolves the acting Employee");
            }
            preg_match_all('/App\\\\Domain\\\\Leave\\\\[A-Za-z\\\\]+/', $code, $m);
            foreach (array_unique($m[0]) as $class) {
                $this->assertContains($class, self::ALLOWED_LEAVE_CLASSES, "{$file}: Staff Attendance reaches Leave only through its Application contracts");
            }
        }
        foreach (['Domain/Leave', 'Domain/HR', 'Domain/Payroll'] as $dir) {
            foreach ($this->phpFiles($dir) as $file) {
                $code = $this->code($file);
                $this->assertStringNotContainsString('staff_attendance_', $code, "{$file}: {$dir} never reads Staff Attendance tables");
                preg_match_all('/App\\\\Domain\\\\StaffAttendance\\\\[A-Za-z\\\\]+/', $code, $m);
                foreach (array_unique($m[0]) as $class) {
                    // HRX.5 (ADR 0065 §26.3): Payroll reaches Staff Attendance ONLY through the one payroll read contract.
                    $this->assertTrue($dir === 'Domain/Payroll' && str_starts_with($class, 'App\\Domain\\StaffAttendance\\Application\\Payroll\\'), "{$file}: {$dir} never depends on Staff Attendance ({$class})");
                }
            }
        }
        $this->assertInstanceOf(StaffAttendancePresenceReader::class, app(AttendancePresenceConflictReader::class), 'Leave\'s port is bound to Staff Attendance in the composition root');
    }

    #[Test]
    public function only_the_service_writes_records_and_corrections(): void
    {
        $service = app_path('Domain/StaffAttendance/Application/StaffAttendanceService.php');
        foreach ($this->phpFiles('') as $file) {
            if ($file === $service) {
                continue;
            }
            $code = $this->code($file);
            $this->assertDoesNotMatchRegularExpression('/StaffAttendance(Record|Correction)::query\(\)->(create|insert|update|delete|upsert)|->table\(\'staff_attendance_(records|corrections)\'\)->(insert|update|delete)/', $code, "{$file}: only StaffAttendanceService writes Staff Attendance");
        }
    }

    #[Test]
    public function authorization_is_by_capability_never_by_role_name_and_the_teacher_role_is_unchanged(): void
    {
        foreach ($this->phpFiles('Domain/StaffAttendance') as $file) {
            $this->assertDoesNotMatchRegularExpression('/->role\b|hasRole\(|role_key|\'teacher\'|\'manager\'|\'school_admin\'|\'principal\'|\'hr\'/', $this->code($file), "{$file}: no role-name check");
        }
        foreach (['StaffAttendanceService', 'StaffAttendanceReadService'] as $service) {
            $this->assertStringContainsString('authorizeCapabilityFor(', $this->code(app_path("Domain/StaffAttendance/Application/{$service}.php")), "{$service} authorizes itself");
        }

        $keys = DB::table('capabilities')->where('key', 'like', 'hr.staff_attendance.%')->orderBy('key')->pluck('key')->all();
        $this->assertSame(['hr.staff_attendance.manage', 'hr.staff_attendance.self', 'hr.staff_attendance.view'], $keys, 'HRX.4 adds the read-only own capability');
        $teacher = DB::table('roles as r')->join('role_capabilities as rc', 'rc.role_id', '=', 'r.id')
            ->where('r.key', 'teacher')->pluck('rc.capability_key')->sort()->values()->all();
        $this->assertSame(['attendance.teacher', 'curriculum.delivery.teacher', 'examinations.marks.teacher', 'lms.assignments.teacher', 'lms.content.teacher'], $teacher, 'E33: the teacher role is unchanged by Staff Attendance (RES.4 adds only the owned marks key)');
        // school_admin also holds `.self` only so it can grant staff_self_service (HRX.4, no escalation).
        foreach (['school_admin' => ['hr.staff_attendance.manage', 'hr.staff_attendance.self', 'hr.staff_attendance.view'], 'principal' => ['hr.staff_attendance.manage', 'hr.staff_attendance.view']] as $role => $expected) {
            $granted = DB::table('roles as r')->join('role_capabilities as rc', 'rc.role_id', '=', 'r.id')->where('r.key', $role)->where('rc.capability_key', 'like', 'hr.staff_attendance.%')->orderBy('rc.capability_key')->pluck('rc.capability_key')->all();
            $this->assertSame($expected, $granted, "{$role}'s default Staff Attendance capabilities");
        }
        $this->assertSame(0, DB::table('roles as r')->join('role_capabilities as rc', 'rc.role_id', '=', 'r.id')->where('r.key', 'staff_self_service')
            ->whereIn('rc.capability_key', ['hr.staff_attendance.view', 'hr.staff_attendance.manage'])->count(), 'HRX.4: self-service never carries attendance administration');
    }

    #[Test]
    public function own_attendance_is_read_only_no_device_surface_exists_and_student_attendance_is_separate(): void
    {
        $routes = (string) file_get_contents(base_path('routes/api.php')).(string) file_get_contents(base_path('routes/web.php'));
        $this->assertDoesNotMatchRegularExpression('#staff-attendance/(clock|punch|check-in|device|biometric|kiosk|ncp|loss-of-pay)#', $routes);
        // HRX.4: own attendance is READ ONLY -- no write of any kind on an own-attendance route.
        $own = collect(Route::getRoutes())->filter(fn ($r) => preg_match('#my[-/]staff-attendance#', $r->uri()) === 1)->values();
        $this->assertNotEmpty($own);
        foreach ($own as $route) {
            $this->assertSame([], array_values(array_diff($route->methods(), ['GET', 'HEAD'])), "{$route->uri()}: own attendance is read only");
        }
        foreach ($this->phpFiles('Domain/Attendance') as $file) {
            $this->assertStringNotContainsString('StaffAttendance', $this->code($file), "{$file}: Student attendance shares nothing with Staff Attendance");
        }
        foreach ($this->phpFiles('Domain/StaffAttendance') as $file) {
            $this->assertDoesNotMatchRegularExpression('/(?<!staff_)attendance_records\b|attendance_sessions|student_enrollment/', $this->code($file), "{$file}: Staff Attendance never touches Student attendance");
        }
    }
}
