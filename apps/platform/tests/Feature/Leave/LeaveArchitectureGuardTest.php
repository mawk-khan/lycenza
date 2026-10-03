<?php

namespace Tests\Feature\Leave;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * HRX.1 (ADR 0065 §3, §10, §12, §13) static and schema guards. They fail
 * loudly the moment a change breaks a boundary this checkpoint relies on:
 * no health, biometric or free-text column; one-way dependencies; no
 * role-name authorization; one ledger writer; no stored balance; the
 * `teacher` role untouched (E33).
 */
class LeaveArchitectureGuardTest extends TestCase
{
    private const TABLES = [
        'leave_settings', 'leave_years', 'leave_types', 'leave_policies', 'leave_policy_assignments',
        'staff_working_weekdays', 'staff_holidays', 'leave_allocation_runs', 'leave_ledger_entries',
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
            foreach (['App\\Domain\\Payroll', 'App\\Domain\\Finance', 'App\\Domain\\Fees', 'App\\Domain\\Payments', 'App\\Domain\\Attendance', 'App\\Domain\\StaffAttendance', 'payroll_', 'fee_settings', 'financial_period'] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $code, "{$file}: Leave never depends on {$forbidden}");
            }
            foreach (['employees.user_id', "'user_id'", 'ActingEmployeeResolver', 'App\\Domain\\HR\\Infrastructure'] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $code, "{$file}: Leave reads HR only through HR's Application services, and resolves no User");
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
            $this->assertDoesNotMatchRegularExpression('/->role\b|hasRole\(|role_key|\'teacher\'|\'manager\'|\'school_admin\'|\'principal\'/', $code, "{$file}: no role-name check");
        }
        foreach (['LeaveYearService', 'LeaveTypeService', 'LeavePolicyService', 'LeavePolicyAssignmentService', 'StaffCalendarService', 'LeaveLedgerService', 'LeaveReadService'] as $service) {
            $this->assertStringContainsString('authorizeCapabilityFor(', $this->code(app_path("Domain/Leave/Application/{$service}.php")), "{$service} authorizes itself");
        }
    }

    #[Test]
    public function the_ledger_has_one_writer_and_no_request_consumption_exists_yet(): void
    {
        $writers = array_values(array_filter($this->phpFiles(''), fn (string $f) => str_contains($this->code($f), 'LeaveLedgerEntry::query()->create(')));
        $this->assertSame([app_path('Domain/Leave/Application/LeaveLedgerService.php')], $writers);

        foreach ($this->phpFiles('') as $file) {
            $this->assertDoesNotMatchRegularExpression("/'kind'\\s*=>\\s*'(consumption|reversal|carry_forward_in|carry_forward_out|expiry)'/", $this->code($file), "{$file}: request consumption and year close are HRX.2");
        }
        $this->assertSame([], glob(app_path('Domain/Leave/Application/*Request*.php')) ?: [], 'no leave request workflow in HRX.1');
    }

    #[Test]
    public function the_teacher_role_is_untouched_and_no_self_service_capability_exists_yet(): void
    {
        $teacher = DB::table('roles as r')->join('role_capabilities as rc', 'rc.role_id', '=', 'r.id')
            ->where('r.key', 'teacher')->pluck('rc.capability_key')->sort()->values()->all();
        $this->assertSame(['attendance.teacher', 'curriculum.delivery.teacher', 'lms.assignments.teacher', 'lms.content.teacher'], $teacher, 'E33: the teacher role is unchanged');

        $leave = DB::table('capabilities')->where('key', 'like', 'hr.leave.%')->orderBy('key')->pluck('key')->all();
        $this->assertSame(['hr.leave.configure', 'hr.leave.manage', 'hr.leave.view'], $leave, 'approve and self arrive with HRX.2/HRX.4');
        $this->assertFalse(DB::table('roles')->where('key', 'staff_self_service')->exists(), 'the staff self-service role is HRX.4');
    }
}
