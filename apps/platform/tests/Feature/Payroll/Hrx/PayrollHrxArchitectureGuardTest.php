<?php

namespace Tests\Feature\Payroll\Hrx;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * HRX.5 (ADR 0065 §26.3, §26.11) static guards:
 * - Payroll reaches HRX only through the one read contract
 *   (App\Domain\StaffAttendance\Application\Payroll\*): no Leave or Staff
 *   Attendance model, service or table;
 * - HRX never depends on Payroll;
 * - HRX evidence never feeds an amount: no calculation, statutory or ECR
 *   code reads the snapshot or the contract;
 * - no half-day unit is rounded into days anywhere (no hidden NCP rounding);
 * - the ECR NCP field keeps its legacy default `0`.
 */
class PayrollHrxArchitectureGuardTest extends TestCase
{
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
    public function payroll_reads_hrx_only_through_the_one_contract_and_hrx_never_depends_on_payroll(): void
    {
        foreach ($this->phpFiles('Domain/Payroll') as $file) {
            $code = $this->code($file);
            $this->assertStringNotContainsString('App\\Domain\\Leave', $code, "{$file}: Payroll never names a Leave class");
            $this->assertDoesNotMatchRegularExpression('/\b(leave_[a-z_]+|staff_attendance_[a-z_]+|staff_holidays|staff_working_weekdays)\b/', $code, "{$file}: Payroll never reads an HRX table");
            preg_match_all('/App\\\\Domain\\\\StaffAttendance\\\\[A-Za-z\\\\]+/', $code, $m);
            foreach (array_unique($m[0]) as $class) {
                $this->assertStringStartsWith('App\\Domain\\StaffAttendance\\Application\\Payroll\\', $class, "{$file}: only the HRX payroll read contract");
            }
        }
        foreach ([...$this->phpFiles('Domain/Leave'), ...$this->phpFiles('Domain/StaffAttendance')] as $file) {
            $this->assertStringNotContainsString('App\\Domain\\Payroll', $this->code($file), "{$file}: HRX never depends on Payroll");
        }
    }

    #[Test]
    public function hrx_evidence_cannot_reach_any_amount_or_the_ecr_while_hrx_l4_is_open(): void
    {
        $allowed = [
            app_path('Domain/Payroll/Application/PayrollRunService.php'),          // captures, after every amount is final
            app_path('Domain/Payroll/Application/PayrollHrxInputReadService.php'), // reads and compares
            app_path('Domain/Payroll/Infrastructure/PayrollRunHrxInput.php'),
        ];
        foreach ($this->phpFiles('') as $file) {
            if (in_array($file, $allowed, true) || ! str_contains($file, '/Domain/Payroll/')) {
                continue;
            }
            $this->assertDoesNotMatchRegularExpression('/PayrollRunHrxInput|PayrollAbsenceEvidence|payroll_run_hrx_inputs|HalfUnits/', $this->code($file), "{$file}: HRX evidence never feeds Payroll calculation, statutory or export code");
        }

        // Inside PayrollRunService the evidence is used only by captureHrxInputs(), and only after the results are persisted.
        $service = $this->code(app_path('Domain/Payroll/Application/PayrollRunService.php'));
        $this->assertSame(1, substr_count($service, '->captureForPayroll('));
        $this->assertLessThan(strpos($service, '$this->captureHrxInputs('), strpos($service, '$persisted[$employmentRecordId] = $this->persistResult('), 'captured after the amounts are final');
        preg_match('/private function captureHrxInputs\(.*?\n    \}\n/s', $service, $m);
        $this->assertNotEmpty($m);
        $this->assertDoesNotMatchRegularExpression('/amount|gross|net_|deduction|PayrollRunResultLine|engine/i', preg_replace("/'payroll_run_result_id' => [^,]+,/", '', $m[0]), 'the capture never touches money');

        $ecr = $this->code(app_path('Domain/Payroll/Statutory/Application/Export/StatutoryEcrExportService.php'));
        $this->assertMatchesRegularExpression("/\\\$result->employer_epf \\?\\? '0\\.00',\\s*'0',\\s*'0\\.00',/", $ecr, 'NCP Days keeps the legacy default 0 pending validated HRX-L4/EPFO mapping');
    }

    #[Test]
    public function no_half_day_unit_is_rounded_into_days_anywhere(): void
    {
        foreach ([...$this->phpFiles('Domain/Payroll'), ...$this->phpFiles('Domain/Leave'), ...$this->phpFiles('Domain/StaffAttendance')] as $file) {
            $code = $this->code($file);
            $this->assertDoesNotMatchRegularExpression('/(intdiv|ceil|floor|round)\s*\([^;]*(half|Half|units|Units)[^;]*\)/', $code, "{$file}: no hidden half-day -> day rounding");
            $this->assertDoesNotMatchRegularExpression('/(HalfUnits|half_units|Units)\s*\/\s*2\b/', $code, "{$file}: no half-day -> day conversion");
            $this->assertDoesNotMatchRegularExpression('/\bncp_?days?\b|\bnon_?payable\b|\bloss_?of_?pay\b|\bdeductible\b/i', preg_replace("/'NCP Days'|NCP Days/", '', $code), "{$file}: no NCP/loss-of-pay quantity is derived (HRX-L4 open)");
        }
    }
}
