<?php

namespace Tests\Feature\Leave;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * HRX.1/HRX.2 (ADR 0065 §3, §10, §12, §13, §23) static and schema guards.
 * They fail loudly the moment a change breaks a boundary these checkpoints
 * rely on:
 * - no health, biometric or free-text column;
 * - one-way dependencies, with User -> Employee only through HR's
 *   ActingEmployeeResolver, in the two request services;
 * - no role-name authorization;
 * - one ledger writer;
 * - no stored balance;
 * - the `teacher` role untouched (E33);
 * - no self-service yet; Staff Attendance (HRX.3) reached only through
 *   Leave's own port.
 */
class LeaveArchitectureGuardTest extends TestCase
{
    private const TABLES = [
        'leave_settings', 'leave_year_start_changes', 'leave_years', 'leave_types', 'leave_policies', 'leave_policy_assignments',
        'staff_working_weekdays', 'staff_holidays', 'leave_allocation_runs', 'leave_ledger_entries',
        'leave_requests', 'leave_request_days', 'leave_decisions', 'leave_year_closes', 'leave_year_close_items', 'leave_year_close_reconciliations',
    ];

    /**
     * HRX-L1 / HRX-L2: a column whose name says health, medical evidence,
     * biometrics or free text. `reason_code` (a closed list) is the only
     * reason a Leave row may carry.
     */
    private const PROHIBITED_COLUMN = '/medical|diagnos|health|illness|sick_note|certificate|prescription|doctor|hospital|attachment|document|file|biometric|fingerprint|face|photo|comment|remark|note|description|free_text|reason_text|details/';

    /** A stored, mutable balance column (the balance is always derived from the ledger). */
    private const STORED_BALANCE = '/^(balance|remaining_balance|available_balance|available_units|balance_units|remaining_units)$/';

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
    public function no_leave_table_can_hold_health_biometric_free_text_or_a_stored_balance(): void
    {
        $columns = DB::select('SELECT table_name, column_name FROM information_schema.columns WHERE table_schema = ? AND table_name IN ('.implode(',', array_fill(0, count(self::TABLES), '?')).')', ['public', ...self::TABLES]);
        $this->assertNotEmpty($columns);

        $hits = array_values(array_filter(array_map(fn ($c) => "{$c->table_name}.{$c->column_name}", $columns), fn (string $c) => preg_match(self::PROHIBITED_COLUMN, explode('.', $c)[1]) === 1 || preg_match(self::STORED_BALANCE, explode('.', $c)[1]) === 1));
        $this->assertSame([], $hits, 'HRX v1 collects no health detail, biometrics, free text or stored balance (ADR 0065 §10); reopening needs a legal gate (HRX-L1/L2)');

        $documentColumns = array_column(DB::select("SELECT column_name FROM information_schema.columns WHERE table_schema = 'public' AND table_name = 'documents'"), 'column_name');
        $this->assertSame([], array_values(array_filter($documentColumns, fn (string $c) => str_contains($c, 'leave'))), 'no Document owner for leave in v1');
    }

    #[Test]
    public function dependencies_run_one_way_and_leave_never_touches_payroll_finance_or_identity_resolution(): void
    {
        foreach ($this->phpFiles('Domain/Leave') as $file) {
            $code = $this->code($file);
            // HRX.3: Leave reaches Staff Attendance only through its own port (AttendancePresenceConflictReader), bound in AppServiceProvider.
            foreach (['App\\Domain\\Payroll', 'App\\Domain\\Finance', 'App\\Domain\\Fees', 'App\\Domain\\Payments', 'App\\Domain\\Attendance', 'App\\Domain\\StaffAttendance', 'staff_attendance_', 'payroll_', 'fee_settings', 'financial_period'] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $code, "{$file}: Leave never depends on {$forbidden}");
            }
            foreach (['employees.user_id', "'user_id'", 'App\\Domain\\HR\\Infrastructure'] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $code, "{$file}: Leave reads HR only through HR's Application services, and resolves no User itself");
            }
            // HRX.2: the acting manager is resolved only through HR's one resolver, in the two request services.
            if (str_contains($code, 'ActingEmployeeResolver')) {
                $this->assertContains(basename($file), ['LeaveRequestService.php', 'LeaveRequestReadService.php'], "{$file}: only the request services resolve the acting Employee");
            }
        }
        foreach (['Domain/HR', 'Domain/Payroll'] as $dir) {
            foreach ($this->phpFiles($dir) as $file) {
                $this->assertStringNotContainsString('App\\Domain\\Leave', $this->code($file), "{$file}: {$dir} never depends on Leave (Payroll reaches it only through HRX.5's contract)");
            }
        }
    }

    #[Test]
    public function authorization_is_by_capability_never_by_role_name(): void
    {
        foreach ($this->phpFiles('Domain/Leave') as $file) {
            $code = $this->code($file);
            // 'manager' is a decision PATH value here; a role comparison with it stays prohibited.
            $this->assertDoesNotMatchRegularExpression('/->role\b|hasRole\(|role_key|\'teacher\'|role\s*={2,3}\s*\'manager\'|\'school_admin\'|\'principal\'/', $code, "{$file}: no role-name check");
        }
        foreach (['LeaveYearService', 'LeaveTypeService', 'LeavePolicyService', 'LeavePolicyAssignmentService', 'StaffCalendarService', 'LeaveLedgerService', 'LeaveReadService', 'LeaveRequestService', 'LeaveRequestReadService', 'LeaveYearCloseService'] as $service) {
            $this->assertStringContainsString('authorizeCapabilityFor(', $this->code(app_path("Domain/Leave/Application/{$service}.php")), "{$service} authorizes itself");
        }
    }

    #[Test]
    public function the_ledger_has_one_writer_and_only_the_request_and_close_services_drive_it(): void
    {
        $ledger = app_path('Domain/Leave/Application/LeaveLedgerService.php');
        $writers = array_values(array_filter($this->phpFiles(''), fn (string $f) => str_contains($this->code($f), 'LeaveLedgerEntry::query()->create(')));
        $this->assertSame([$ledger], $writers);

        foreach ($this->phpFiles('') as $file) {
            if ($file !== $ledger) {
                $this->assertDoesNotMatchRegularExpression("/'kind'\\s*=>\\s*'(consumption|reversal|carry_forward_in|carry_forward_out|expiry)'/", $this->code($file), "{$file}: only the ledger service writes consumption, reversal and close movements");
            }
        }
        // HRX.2: the uncapable record*() entry points are called only from inside the two authorized services.
        $callers = array_values(array_filter($this->phpFiles(''), fn (string $f) => $f !== $ledger && preg_match('/->record(Consumption|Reversal|CloseMovement)\(/', $this->code($f)) === 1));
        $this->assertSame([app_path('Domain/Leave/Application/LeaveRequestService.php'), app_path('Domain/Leave/Application/LeaveYearCloseService.php')], $callers);
    }

    #[Test]
    public function no_self_service_or_payroll_coupling_exists_yet(): void
    {
        $routes = (string) file_get_contents(base_path('routes/api.php')).(string) file_get_contents(base_path('routes/web.php'));
        $this->assertDoesNotMatchRegularExpression('#/my[-/](leave|staff[-_]attendance|payslips?)|hr\.leave\.self|hr\.staff_attendance\.self#', $routes, 'self-service is HRX.4');
        foreach ($this->phpFiles('Domain/Payroll') as $file) {
            $this->assertDoesNotMatchRegularExpression('/leave_(requests|request_days|decisions|ledger_entries|year_close)/', $this->code($file), "{$file}: Payroll never reads Leave tables (HRX.5 is a read contract)");
        }
    }

    #[Test]
    public function the_teacher_role_is_untouched_and_no_self_service_capability_exists_yet(): void
    {
        $teacher = DB::table('roles as r')->join('role_capabilities as rc', 'rc.role_id', '=', 'r.id')
            ->where('r.key', 'teacher')->pluck('rc.capability_key')->sort()->values()->all();
        $this->assertSame(['attendance.teacher', 'curriculum.delivery.teacher', 'lms.assignments.teacher', 'lms.content.teacher'], $teacher, 'E33: the teacher role is unchanged');

        $leave = DB::table('capabilities')->where('key', 'like', 'hr.leave.%')->orderBy('key')->pluck('key')->all();
        $this->assertSame(['hr.leave.approve', 'hr.leave.configure', 'hr.leave.manage', 'hr.leave.view'], $leave, 'HRX.2 adds approve; self is HRX.4');
        $this->assertFalse(DB::table('roles')->where('key', 'staff_self_service')->exists(), 'the staff self-service role is HRX.4');
    }
}
